#!/usr/bin/env python3
"""
python-runner/wrapper.py

Fixed execution template for the Python-Playwright automation path.

Reads  : RUN_JSON_PATH  — the full run-spec JSON written by generateScript.ts /
                          ExecuteSingleTestCaseRun (cases[0].python_script_body,
                          target.base_url, runtime.headless, cases[0].external_id,
                          cases[0].execution_profile.required_inputs[].value)
Writes : RESULT_JSON_PATH — RunResultV1-compatible JSON (schema_version 1.0)
         ARTIFACTS_DIR/final.png — screenshot always captured, pass or fail

Exit codes mirror playwright-runner-job/src/main.ts:
  0  all passed
  1  assertion failure (test verdict: failed)
  2  navigation / timeout / URL unreachable (test verdict: blocked)
  3  unexpected runtime error or infra failure (test verdict: blocked)
"""

import asyncio
import json
import os
import sys
import time
from pathlib import Path


# ---------------------------------------------------------------------------
# Result writer
# ---------------------------------------------------------------------------

def _write_result(
    result_path: str,
    run_id: str,
    external_id: int,
    status: str,
    error_type: str | None,
    error_message: str | None,
    duration_ms: int,
    screenshot_relative: str | None,
    runner_run_id: str,
    base_url: str,
) -> None:
    """Write a RunResultV1-compatible result.json."""
    summary: dict[str, int] = {"passed": 0, "failed": 0, "blocked": 0, "skipped": 0}
    if status in summary:
        summary[status] += 1

    result = {
        "schema_version": "1.0",
        "run_id": run_id,
        "runner_run_id": runner_run_id,
        "base_url_used": base_url,
        "status": "done",
        "summary": summary,
        "results": [
            {
                "external_id": external_id,
                "status": status,
                "attempt": 1,
                "duration_ms": duration_ms,
                "error_type": error_type,
                "error_message": error_message,
                "artifacts": {
                    "trace_path": None,
                    "screenshot_path": screenshot_relative,
                    "video_path": None,
                },
                "execution_trace": [],
            }
        ],
    }

    out = Path(result_path)
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(result, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

async def main() -> int:
    run_json_path = os.environ.get("RUN_JSON_PATH", "/work/run.json")
    result_json_path = os.environ.get("RESULT_JSON_PATH", "/work/result.json")
    artifacts_dir = os.environ.get("ARTIFACTS_DIR", "/work/artifacts")

    runner_run_id = f"python-runner-{int(time.time() * 1000)}"

    # ── Read run spec ────────────────────────────────────────────────────────
    try:
        with open(run_json_path, encoding="utf-8") as f:
            run_data: dict = json.load(f)
    except Exception as exc:
        print(f"[wrapper] ERROR: cannot read {run_json_path}: {exc}", file=sys.stderr)
        _write_result(
            result_json_path, "unknown", 0, "blocked", "unexpected_error",
            f"Cannot read run spec: {exc}", 0, None, runner_run_id, "",
        )
        return 3

    run_id: str = run_data.get("run_id", "unknown")
    cases: list = run_data.get("cases") or []
    case: dict = cases[0] if cases else {}
    external_id: int = int(case.get("external_id", 0))
    base_url: str = (run_data.get("target") or {}).get("base_url", "")
    headless: bool = bool((run_data.get("runtime") or {}).get("headless", True))
    python_script_body: str = case.get("python_script_body", "")

    # Build inputs dict from two sources, applied in order of increasing authority:
    #
    # Source 1 — provided_inputs dict (present on the manual/direct path when
    #   generateScript.ts embeds the tester's raw values at root or case level).
    #   When dispatched by Laravel this key is NOT present in the written run spec
    #   (confirmed: ExecuteSingleTestCaseRun.php line 163 only writes the merged
    #   run_spec which no longer carries a top-level provided_inputs key).
    #
    # Source 2 — execution_profile.required_inputs[].value (the authoritative source
    #   for the Laravel path: mergeProvidedInputsIntoExecutionProfile() at line 807
    #   in ExecuteSingleTestCaseRun.php resolves alias keys and writes the tester's
    #   values into each required_input's "value" field before the spec is written).
    #   Any non-empty value here overrides Source 1.
    inputs: dict[str, str] = {}

    root_provided = run_data.get("provided_inputs")
    if isinstance(root_provided, dict):
        inputs.update({k: str(v) for k, v in root_provided.items() if isinstance(v, str)})

    case_provided = case.get("provided_inputs")
    if isinstance(case_provided, dict):
        inputs.update({k: str(v) for k, v in case_provided.items() if isinstance(v, str)})

    required_inputs: list = (case.get("execution_profile") or {}).get("required_inputs") or []
    for _inp in required_inputs:
        if isinstance(_inp, dict) and _inp.get("key"):
            _val = _inp.get("value")
            if isinstance(_val, str) and _val:  # non-empty overrides sources above
                inputs[_inp["key"]] = _val

    if not base_url:
        _write_result(
            result_json_path, run_id, external_id, "blocked", "unexpected_error",
            "base_url is missing from run spec", 0, None, runner_run_id, "",
        )
        return 3

    if not python_script_body.strip():
        _write_result(
            result_json_path, run_id, external_id, "blocked", "unexpected_error",
            "python_script_body is empty in run spec", 0, None, runner_run_id, base_url,
        )
        return 3

    Path(artifacts_dir).mkdir(parents=True, exist_ok=True)

    # ── Import Playwright ────────────────────────────────────────────────────
    from playwright.async_api import (  # type: ignore[import-untyped]
        async_playwright,
        expect,
        Error as PlaywrightError,
        TimeoutError as PlaywrightTimeoutError,
    )

    start_ms = int(time.time() * 1000)
    status = "passed"
    error_type: str | None = None
    error_message: str | None = None
    exit_code = 0
    screenshot_relative: str | None = None

    # ── Restricted exec() namespace ─────────────────────────────────────────
    # Full sandboxing is deferred (Phase 2 decision). We strip the most
    # obviously dangerous builtins while keeping the subset a test body needs.
    _safe_builtins: dict = {
        "None": None, "True": True, "False": False,
        "len": len, "str": str, "int": int, "float": float,
        "bool": bool, "list": list, "dict": dict, "tuple": tuple,
        "set": set, "range": range, "enumerate": enumerate, "zip": zip,
        "map": map, "filter": filter, "any": any, "all": all,
        "min": min, "max": max, "abs": abs, "round": round,
        "sorted": sorted, "reversed": reversed, "sum": sum,
        "isinstance": isinstance, "issubclass": issubclass,
        "type": type, "print": print, "repr": repr,
        "Exception": Exception, "ValueError": ValueError,
        "TypeError": TypeError, "KeyError": KeyError,
        "IndexError": IndexError, "StopIteration": StopIteration,
    }

    # Wrap body in async def so `await` expressions work inside exec()
    indented = "\n".join("    " + line for line in python_script_body.splitlines())
    if not indented.strip():
        indented = "    pass"
    wrapped_code = f"async def __script__(page, expect, inputs):\n{indented}\n"

    async with async_playwright() as pw:
        browser = await pw.chromium.launch(headless=headless)
        context = await browser.new_context(viewport={"width": 1280, "height": 720})
        page = await context.new_page()
        page.set_default_timeout(30000)

        # Initial navigation to base_url
        try:
            await page.goto(base_url, wait_until="domcontentloaded", timeout=30000)
        except PlaywrightTimeoutError as exc:
            duration_ms = int(time.time() * 1000) - start_ms
            await browser.close()
            _write_result(
                result_json_path, run_id, external_id, "blocked", "url_unreachable",
                str(exc), duration_ms, None, runner_run_id, base_url,
            )
            return 2
        except PlaywrightError as exc:
            msg = str(exc).lower()
            etype = "url_unreachable" if any(
                x in msg for x in (
                    "err_name_not_resolved", "err_connection_refused",
                    "net::", "econnrefused", "enotfound",
                )
            ) else "unexpected_error"
            duration_ms = int(time.time() * 1000) - start_ms
            await browser.close()
            _write_result(
                result_json_path, run_id, external_id, "blocked", etype,
                str(exc), duration_ms, None, runner_run_id, base_url,
            )
            return 2

        # Compile and execute the generated script body
        try:
            exec_globals: dict = {"__builtins__": _safe_builtins}  # type: ignore[assignment]
            exec(wrapped_code, exec_globals)  # noqa: S102
            await exec_globals["__script__"](page, expect, inputs)

        except PlaywrightTimeoutError as exc:
            msg = str(exc).lower()
            error_message = str(exc)
            if any(x in msg for x in ("page.goto", "goto:", "wait_for_url", "waitforurl", "navigation timeout")):
                status = "blocked"
                error_type = "navigation_timeout"
                exit_code = 2
            elif any(x in msg for x in ("locator.", "waiting for get_by", "waiting for locator", "no element matching", "no matching element")):
                # Locator timed out — element was not found in the DOM
                status = "failed"
                error_type = "selector_not_found"
                exit_code = 1
            else:
                status = "blocked"
                error_type = "timeout"
                exit_code = 2

        except AssertionError as exc:
            # Playwright's expect() raises AssertionError on failure
            status = "failed"
            error_type = "assertion_failed"
            error_message = str(exc) if str(exc) else "Playwright assertion failed"
            exit_code = 1

        except KeyError as exc:
            # Script tried inputs["key"] for a key the tester did not provide
            status = "blocked"
            error_type = "input_data_missing"
            error_message = f"Required test input not found: {exc}. Check that the tester provided a value for this field."
            exit_code = 2

        except PlaywrightError as exc:
            msg = str(exc).lower()
            error_message = str(exc)
            if any(x in msg for x in (
                "err_name_not_resolved", "err_connection_refused",
                "net::", "econnrefused", "enotfound",
            )):
                status = "blocked"
                error_type = "url_unreachable"
                exit_code = 2
            elif any(x in msg for x in ("strict mode violation", "no element matching selector", "no matching element for locator")):
                status = "failed"
                error_type = "selector_not_found"
                exit_code = 1
            elif "timeout" in msg:
                status = "blocked"
                error_type = "timeout"
                exit_code = 2
            elif any(x in msg for x in (
                "expected", "to be visible", "to have", "not_to", "assertion",
            )):
                # Playwright may raise Error (not AssertionError) for some assertions
                status = "failed"
                error_type = "assertion_failed"
                exit_code = 1
            else:
                status = "blocked"
                error_type = "unexpected_error"
                exit_code = 3

        except Exception as exc:  # noqa: BLE001
            status = "blocked"
            error_type = "unexpected_error"
            error_message = str(exc)
            exit_code = 3

        # Always capture a final screenshot (both headed and headless)
        try:
            screenshot_path = os.path.join(artifacts_dir, "final.png")
            await page.screenshot(path=screenshot_path)  # viewport only — full_page=True is fragile on fixed-position layouts
            screenshot_relative = "final.png"
        except Exception as exc:  # noqa: BLE001
            print(f"[wrapper] WARNING: screenshot failed: {exc}", file=sys.stderr)

        await browser.close()

    duration_ms = int(time.time() * 1000) - start_ms

    _write_result(
        result_json_path,
        run_id,
        external_id,
        status,
        error_type,
        error_message,
        duration_ms,
        screenshot_relative,
        runner_run_id,
        base_url,
    )

    print(
        f"[wrapper] run_id={run_id} external_id={external_id} "
        f"status={status} error_type={error_type} duration_ms={duration_ms}",
        file=sys.stderr,
    )

    return exit_code


if __name__ == "__main__":
    sys.exit(asyncio.run(main()))
