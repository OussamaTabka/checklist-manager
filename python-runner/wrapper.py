"""
python-runner/wrapper.py

Execution wrapper for the python-gen pipeline.

Environment variables (set by runLocal.js / Docker):
  RUN_JSON_PATH     — path to run.json (RunSpecDslV1 + python_script_body)
  RESULT_JSON_PATH  — path where result.json (RunResultV1) must be written
  ARTIFACTS_DIR     — directory for screenshots / traces (created if absent)

Contract for result.json (RunResultV1):
  {
    "schema_version": "1.0",
    "run_id": str,
    "status": "done",
    "summary": { "passed": int, "failed": int, "blocked": int, "skipped": int },
    "results": [
      {
        "external_id": int,
        "title": str,
        "status": "passed" | "failed" | "blocked",
        "error_type": str | null,      # optional
        "error_message": str | null,   # optional
        "duration_ms": int,
        "browsers_detail": [...]       # optional, one entry per browser run
      }
    ]
  }

Verdict semantics (per user requirement):
  passed  = all assertions verified on all requested browsers
  failed  = at least one Playwright assertion failed (element absent, wrong text…)
  blocked = technical error (browser not launchable, navigation timeout, script crash)

Multi-browser:
  If run.json includes runtime.browsers, the script is executed once per browser.
  The worst status across all browsers is promoted to the case status.
"""

import asyncio
import json
import os
import sys
import textwrap
import time
import traceback
from pathlib import Path
from typing import Any

from playwright.async_api import (
    async_playwright,
    Error as PlaywrightError,
    TimeoutError as PlaywrightTimeoutError,
)


# ── Result helpers ─────────────────────────────────────────────────────────────

def _classify_error(exc: BaseException) -> tuple[str, str, str]:
    """Return (status, error_type, error_message) for a caught exception."""
    msg = str(exc)
    lower = msg.lower()

    if isinstance(exc, PlaywrightTimeoutError):
        # Timeout during page.goto / wait_for_url → infrastructure / blocked
        if any(p in lower for p in ('goto', 'navigation', 'waitforurl', 'waitfornavigation')):
            return 'blocked', 'navigation_timeout', 'The page navigation did not finish in time.'
        # Timeout inside expect() waiting for an element → assertion failed
        return 'failed', 'assertion_timeout', f'An element did not appear before the timeout expired: {msg}'

    if isinstance(exc, PlaywrightError):
        # BrowserType.launch errors are infrastructure problems, not assertion failures
        if 'browsertype.launch' in lower or ("executable" in lower and "doesn't exist" in lower):
            short = msg.split('\n')[0]
            return 'blocked', 'browser_not_installed', short
        # All other playwright errors inside expect() are assertion failures
        return 'failed', 'assertion_failed', msg

    # Explicit raise Exception(...) from the script body = intentional test assertion
    if isinstance(exc, AssertionError):
        return 'failed', 'assertion_failed', msg

    # All other Python exceptions are script / infra errors
    return 'blocked', 'script_error', f'{type(exc).__name__}: {msg}'


def _status_rank(status: str) -> int:
    return {'blocked': 2, 'failed': 1, 'passed': 0}.get(status, 2)


def _aggregate_status(statuses: list[str]) -> str:
    if not statuses:
        return 'blocked'
    return max(statuses, key=_status_rank)


# ── Script execution ───────────────────────────────────────────────────────────

def _compile_script(body: str) -> Any:
    """
    Wrap python_script_body in an async function and compile it.
    Returns the compiled code object.
    """
    # Indent every line of the body so it sits inside the function.
    indented = textwrap.indent(body, '    ')
    src = f'async def __script__(page, expect, inputs):\n{indented}\n'
    return compile(src, '<python_script_body>', 'exec')


async def _run_script_on_browser(
    playwright_instance: Any,
    browser_type_name: str,
    base_url: str,
    runtime: dict,
    compiled_code: Any,
    inputs: dict,
) -> dict:
    """
    Launch one browser, navigate to base_url, execute the compiled script.
    Returns a browser-level result dict with keys: browser, status, error_type,
    error_message, duration_ms.
    """
    headless: bool = runtime.get('headless', True)
    timeout_ms: int = runtime.get('timeout_ms', 30000)
    viewport: dict = runtime.get('viewport', {'width': 1280, 'height': 720})

    browser_launcher = getattr(playwright_instance, browser_type_name, None)
    if browser_launcher is None:
        return {
            'browser': browser_type_name,
            'status': 'blocked',
            'error_type': 'unknown_browser',
            'error_message': f'Unknown browser type: {browser_type_name}',
            'duration_ms': 0,
        }

    browser = None
    t0 = time.monotonic()
    try:
        browser = await browser_launcher.launch(headless=headless)
        context = await browser.new_context(
            viewport=viewport,
            ignore_https_errors=True,
        )
        page = await context.new_page()
        page.set_default_timeout(timeout_ms)
        page.set_default_navigation_timeout(timeout_ms)

        # Navigate to the base URL before handing the page to the script
        try:
            await page.goto(base_url, wait_until='domcontentloaded', timeout=timeout_ms)
        except PlaywrightTimeoutError as nav_err:
            return {
                'browser': browser_type_name,
                'status': 'blocked',
                'error_type': 'navigation_timeout',
                'error_message': f'Initial navigation to {base_url} timed out: {nav_err}',
                'duration_ms': int((time.monotonic() - t0) * 1000),
            }
        except PlaywrightError as nav_err:
            return {
                'browser': browser_type_name,
                'status': 'blocked',
                'error_type': 'navigation_failed',
                'error_message': f'Initial navigation to {base_url} failed: {nav_err}',
                'duration_ms': int((time.monotonic() - t0) * 1000),
            }

        # Build the script function from the compiled code
        namespace: dict = {}
        exec(compiled_code, namespace)  # noqa: S102
        script_fn = namespace.get('__script__')
        if script_fn is None:
            return {
                'browser': browser_type_name,
                'status': 'blocked',
                'error_type': 'script_error',
                'error_message': 'Script compilation did not produce __script__ function.',
                'duration_ms': int((time.monotonic() - t0) * 1000),
            }

        # Import expect inside the wrapper — the script body must not import it
        from playwright.async_api import expect  # noqa: PLC0415

        await script_fn(page, expect, inputs)

        return {
            'browser': browser_type_name,
            'status': 'passed',
            'error_type': None,
            'error_message': None,
            'duration_ms': int((time.monotonic() - t0) * 1000),
        }

    except (PlaywrightError, AssertionError, Exception) as exc:  # noqa: BLE001
        status, error_type, error_message = _classify_error(exc)
        return {
            'browser': browser_type_name,
            'status': status,
            'error_type': error_type,
            'error_message': error_message,
            'duration_ms': int((time.monotonic() - t0) * 1000),
        }
    finally:
        if browser is not None:
            try:
                await browser.close()
            except Exception:  # noqa: BLE001
                pass


async def _run_case(
    playwright_instance: Any,
    case: dict,
    base_url: str,
    runtime: dict,
) -> dict:
    """
    Execute one case (potentially on multiple browsers).
    Returns a case-level result entry for result.json.
    """
    external_id: int = case['external_id']
    title: str = case.get('title', '')
    python_script_body: str = case.get('python_script_body', '')
    provided_inputs: dict = case.get('provided_inputs', {})

    # Merge required_inputs values into inputs dict
    inputs: dict = dict(provided_inputs)
    execution_profile = case.get('execution_profile', {})
    for ri in execution_profile.get('required_inputs', []):
        key = ri.get('key', '')
        value = ri.get('value')
        if key and value is not None and key not in inputs:
            inputs[key] = value

    # Compile the script body once (syntax errors caught here = blocked)
    try:
        compiled = _compile_script(python_script_body)
    except SyntaxError as exc:
        return {
            'external_id': external_id,
            'title': title,
            'status': 'blocked',
            'error_type': 'script_syntax_error',
            'error_message': f'SyntaxError in python_script_body: {exc}',
            'duration_ms': 0,
            'browsers_detail': [],
        }

    # Determine which browsers to test
    browsers: list[str] = runtime.get('browsers') or ['chromium']

    browser_results: list[dict] = []
    for browser_name in browsers:
        br = await _run_script_on_browser(
            playwright_instance,
            browser_name,
            base_url,
            runtime,
            compiled,
            inputs,
        )
        browser_results.append(br)
        sys.stderr.write(
            f'[wrapper] case={external_id} browser={browser_name} '
            f'status={br["status"]} duration_ms={br["duration_ms"]}\n'
        )

    # Aggregate across browsers: worst status wins
    overall_status = _aggregate_status([r['status'] for r in browser_results])
    total_ms = sum(r['duration_ms'] for r in browser_results)

    # Pick first failure detail for top-level error fields
    first_failure = next((r for r in browser_results if r['status'] != 'passed'), None)
    error_type = first_failure['error_type'] if first_failure else None
    error_message = first_failure['error_message'] if first_failure else None
    if first_failure and len(browsers) > 1:
        error_message = f'[{first_failure["browser"]}] {error_message}'

    return {
        'external_id': external_id,
        'title': title,
        'status': overall_status,
        'error_type': error_type,
        'error_message': error_message,
        'duration_ms': total_ms,
        'browsers_detail': browser_results,
    }


# ── Main ───────────────────────────────────────────────────────────────────────

async def main() -> None:
    run_json_path = os.environ.get('RUN_JSON_PATH', '/work/run.json')
    result_json_path = os.environ.get('RESULT_JSON_PATH', '/work/result.json')
    artifacts_dir = os.environ.get('ARTIFACTS_DIR', '/work/artifacts')

    Path(artifacts_dir).mkdir(parents=True, exist_ok=True)
    Path(run_json_path).parent.mkdir(parents=True, exist_ok=True)

    sys.stderr.write(f'[wrapper] run_json={run_json_path}\n')
    sys.stderr.write(f'[wrapper] result_json={result_json_path}\n')

    # Read and parse run.json
    try:
        with open(run_json_path, encoding='utf-8') as fh:
            run_spec = json.load(fh)
    except Exception as exc:  # noqa: BLE001
        sys.stderr.write(f'[wrapper] ERROR: cannot read run.json: {exc}\n')
        _write_blocked_result(result_json_path, 'unknown', 'cannot_read_run_json', str(exc))
        sys.exit(1)

    run_id: str = run_spec.get('run_id', 'unknown')
    base_url: str = run_spec.get('target', {}).get('base_url', '')
    runtime: dict = run_spec.get('runtime', {})
    cases: list[dict] = run_spec.get('cases', [])

    if not cases:
        sys.stderr.write('[wrapper] ERROR: run.json contains no cases\n')
        _write_blocked_result(result_json_path, run_id, 'no_cases', 'run.json contains no cases')
        sys.exit(1)

    sys.stderr.write(
        f'[wrapper] run_id={run_id} base_url={base_url} '
        f'cases={len(cases)} browsers={runtime.get("browsers") or ["chromium"]}\n'
    )

    case_results: list[dict] = []

    async with async_playwright() as pw:
        for case in cases:
            result = await _run_case(pw, case, base_url, runtime)
            case_results.append(result)

    # Build summary
    summary = {'passed': 0, 'failed': 0, 'blocked': 0, 'skipped': 0}
    for r in case_results:
        summary[r['status']] = summary.get(r['status'], 0) + 1

    result_payload = {
        'schema_version': '1.0',
        'run_id': run_id,
        'status': 'done',
        'summary': summary,
        'results': case_results,
    }

    with open(result_json_path, 'w', encoding='utf-8') as fh:
        json.dump(result_payload, fh, indent=2, ensure_ascii=False)
        fh.write('\n')

    sys.stderr.write(
        f'[wrapper] done — passed={summary["passed"]} failed={summary["failed"]} '
        f'blocked={summary["blocked"]}\n'
    )
    sys.exit(0 if summary['failed'] == 0 and summary['blocked'] == 0 else 1)


def _write_blocked_result(result_json_path: str, run_id: str, error_type: str, error_message: str) -> None:
    payload = {
        'schema_version': '1.0',
        'run_id': run_id,
        'status': 'done',
        'summary': {'passed': 0, 'failed': 0, 'blocked': 1, 'skipped': 0},
        'results': [
            {
                'external_id': 0,
                'title': 'wrapper error',
                'status': 'blocked',
                'error_type': error_type,
                'error_message': error_message,
                'duration_ms': 0,
                'browsers_detail': [],
            }
        ],
    }
    try:
        Path(result_json_path).parent.mkdir(parents=True, exist_ok=True)
        with open(result_json_path, 'w', encoding='utf-8') as fh:
            json.dump(payload, fh, indent=2, ensure_ascii=False)
            fh.write('\n')
    except Exception:  # noqa: BLE001
        pass


if __name__ == '__main__':
    asyncio.run(main())
