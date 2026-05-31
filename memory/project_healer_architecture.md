---
name: project-healer-architecture
description: Healer pass architecture — single LLM re-try for failed Python Playwright tests
metadata:
  type: project
---

# Healer Pass Architecture

Added in session 2026-05-31. Activates on any failed Python script test when `HEALER_ENABLED=true`.

## Files created
- `playwright-agent/src/prompts/healerPrompt.ts` — `HealPayload` type + `buildHealerPrompt()` + `HEALER_SYSTEM_PROMPT`
- `playwright-agent/src/python-gen/healScript.ts` — CLI: reads `--input heal-input.json`, calls LLM, outputs healed run-spec to stdout
- `backend/database/migrations/2026_05_31_120000_add_healer_columns_to_test_results.php` — adds `attempt`, `healed`, `heal_diagnosis` to `test_results`

## Files modified
- `backend/app/Jobs/ExecuteSingleTestCaseRun.php` — healer pass after first result.json, new `isHealable()` + `runHealerPass()` methods, `persistResult()` now accepts attempt/healed/heal_diagnosis
- `python-runner/wrapper.py` — added `selector_not_found` classification for locator timeouts (PlaywrightTimeoutError with "locator." in message → status=failed, error_type=selector_not_found)

## Activation conditions (all must be true)
1. `result.results[0].status === 'failed'`
2. `error_type ∈ ['selector_not_found', 'navigation_timeout', 'assertion_failed']`
3. `generation_mode === 'python_script'` (healer only runs on Python script tests, not DSL)
4. `env('HEALER_ENABLED') === true`

## LLM model
Uses same provider as generation: `gpt-5.3-codex` via codex.sale.

## Exit codes from healScript.js
- 0 = success (run-spec on stdout)
- 2 = LLM failure
- 3 = invalid response shape

## Testing confirmed
All 4 acceptance criteria passed:
1. `healScript.js` exits 0 on valid payload ✓
2. Healed spec executes through wrapper.py → well-formed result.json ✓
3. Synthetic `selector_not_found` (data-test="usernamez") → healer → passes ✓
4. Real `assertion_failed` (locked_out_user used in positive test) → healer → passes ✓

## Rollback
- Partial: set `HEALER_ENABLED=false` in .env
- Full: set `AGENT_GENERATION_MODE=dsl` + `HEALER_ENABLED=false`

**Why:** added `selector_not_found` to wrapper.py because the Python Playwright timeout error for missing locators was classified as generic `timeout`, which the healer condition didn't cover.
