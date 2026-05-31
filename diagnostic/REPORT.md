# Diagnostic Report — checklist-manager execution platform
Generated: 2026-05-31 (static analysis, Steps 1–3)

---

## Step 1 — System map

### (a) API endpoint that lists test cases
```
GET /api/checklists/{checklist_id}
```
Returns `{ id, name, items: [{id, title, description, status, ...}] }`.
Called by the frontend store: `frontend/src/stores/checklists.js:53 — api.get('/checklists/${checklistId}')`.

Items within a checklist are embedded in the checklist response; there is no separate `/items` list endpoint.

### (b) Endpoint that launches a run
```
POST /api/checklists/{checklist}/items/{item}/runs
```
`backend/routes/api.php:84` → `ChecklistItemExecutionController::run()` (line 119)
Body: `{ base_url, use_auth, watch_mode, environment_name, notes, provided_inputs:[], expected_result:null }`
Response: `{ run_id, status:"started"|"queued" }` HTTP 202.

### (c) Code that builds the runspec.json
`backend/app/Jobs/ExecuteSingleTestCaseRun.php` (dispatched at line 75–77).

Flow:
1. Line 102: `$planner->generateForChecklistItem()` → shells out to `node playwright-agent/dist/generateRunSpec.js --input <tmp.json>` (ExecutionProfileService.php:111).
2. Line 143–163: assembles final runSpec (merges provided_inputs into execution_profile.required_inputs[].value).
3. Line 163: writes to `storage/app/agent-run-dsl/run-single-{uuid}.json`.
4. Line 169 (watchMode) or 214 (Docker): calls runner with that file as `RUN_JSON_PATH`.

### (d) Where the base URL is resolved
Three places, in order of priority:

| Location | Code | Value source |
|---|---|---|
| Tester form (submitted) | `ChecklistItemRunService.php:24` — `$options['base_url']` | What the tester typed in the modal |
| localStorage (pre-filled into modal) | `ChecklistDetailView.vue` — `readPersistedRunBaseUrl()` | **Last URL any test was run with, across ALL checklists** |
| Project app_url (fallback for profile preview) | `ChecklistItemExecutionController.php:320` | `project.app_url` from the database |

**The base URL leak is entirely in the frontend**: `ChecklistDetailView.vue` stores `CHECKLIST_RUN_BASE_URL_STORAGE_KEY` in `localStorage` after every run and pre-fills it into the next modal that opens — regardless of which project or checklist that next modal belongs to.

### (e) python_script_body generation vs. generic template
Two separate paths:

**New Python path (manual CLI only — NOT yet wired into Laravel):**
- Entry: `node playwright-agent/dist/python-gen/generateScript.ts --input <file>`
- Calls LLM (codex.sale) to produce `{ execution_profile, python_script_body, human_readable_steps }`
- Verified zero of the 46 existing run specs in `agent-run-dsl/` were generated this way

**Old DSL path (current default, all 46 run specs use this):**
- Entry: `node playwright-agent/dist/generateRunSpec.js --input <file>`
- `generateRunSpec.ts:1384–1452` — `buildCasePlan()` → heuristic generator. **Does NOT read the authored plan from `execution_profile.last_generated_plan`**; it always regenerates from scratch based on keyword matching against `test_case_title` + `test_case_description`.
- Generic fallback selectors are emitted by `cssSelector()` (line 785–800), e.g. `input[type="search"], input[name*="search" i], ...`
- The seeder's carefully authored specific selectors (`#search_product`, `#submit_search`) are completely ignored.

---

## Step 2 — Test case enumeration

Source: `DemoAutomationProjectsSeeder.php` + `SauceDemoExecutionWorkspaceSeeder.php`.
**Total seeded cases: 27** across 4 projects + 1 workspace case (TC-01 Sauce Demo seed).
Full list saved to `diagnostic/cases.json`.

Summary by project:

| Project | App URL | Cases |
|---|---|---|
| Sauce Demo E-commerce Automation | https://www.saucedemo.com | 9 |
| Practice Login Automation | https://practicetestautomation.com/practice-test-login/ | 4 |
| Automation Testing Practice Playground | https://testautomationpractice.blogspot.com/ | 7 |
| Automation Exercise E-commerce QA | https://automationexercise.com/ | 7 |

**All 27 seeded cases have authored plans** (`execution_profile.last_generated_plan.steps` + `asserts`) embedded in the database via `buildExecutionProfile()`. None of the 46 existing run specs in `agent-run-dsl/` target `automationexercise.com`, `practicetestautomation.com` (for the correct cases), or `blogspot.com` — meaning none of the 27 seeded demo cases have ever been successfully executed via the platform.

---

## Step 3 — Dry-run analysis (static, from existing run specs)

No live LLM calls were made for this step. Analysis is based on the 46 run spec files already on disk plus the seeder-defined expected inputs.

### Master table

> Legend: URL-OK = correct project URL used | Script = python_script_body present | Selectors = case-specific or generic | BOM = UTF-8 BOM in file | Mojibake = encoding corruption present

| # | Title (abbreviated) | Project URL declared | URL in run spec | Mode | Script | Selectors | BOM | Mojibake |
|---|---|---|---|---|---|---|---|---|
| 1 | Verify successful login / standard_user | saucedemo.com | saucedemo.com ✓ | dsl-heuristic | ✗ | generic | ✗ | ✗ |
| 2 | Verify locked_out_user rejected | saucedemo.com | saucedemo.com ✓ | dsl-heuristic | ✗ | generic | ✗ | ✗ |
| 3 | Verify invalid credentials → error | saucedemo.com | saucedemo.com ✓ | dsl-heuristic | ✗ | generic | ✗ | ✗ |
| 4 | Verify logout → login page | saucedemo.com | saucedemo.com ✓ | dsl-heuristic | ✗ | generic | ✗ | ✗ |
| 5 | Verify inventory sorting Z→A | saucedemo.com | saucedemo.com ✓ | dsl-heuristic | ✗ | specific* | ✗ | ✗ |
| 6 | Verify Backpack added to cart | saucedemo.com | saucedemo.com ✓ | dsl-heuristic | ✗ | specific* | ✗ | ✗ |
| 7 | Verify removing item resets badge | saucedemo.com | saucedemo.com ✓ | dsl-heuristic | ✗ | specific* | ✗ | ✗ |
| 8 | Verify checkout requires postal code | saucedemo.com | saucedemo.com ✓ | dsl-heuristic | ✗ | generic | ✗ | ✗ |
| 9 | Verify successful checkout → thank-you | saucedemo.com | saucedemo.com ✓ | dsl-heuristic | ✗ | generic | ✗ | ✗ |
| 10 | Verify valid login → logged-in page | practicetestautomation.com | practicetestautomation.com ✓ | dsl-heuristic | ✗ | generic | ✗ | ✗ |
| 11 | Verify invalid username → error | practicetestautomation.com | practicetestautomation.com ✓ | dsl-heuristic | ✗ | generic | ✗ | ✗ |
| 12 | Verify invalid password → error | practicetestautomation.com | practicetestautomation.com ✓ | dsl-heuristic | ✗ | generic | ✗ | ✗ |
| 13 | Verify logout → practice login page | practicetestautomation.com | practicetestautomation.com ✓ | dsl-heuristic | ✗ | generic | ✗ | ✗ |
| 14 | Verify form required fields visible | blogspot.com | (never run) | — | — | — | — | — |
| 15 | Verify date input accepts valid date | blogspot.com | (never run) | — | — | — | — | — |
| 16 | Verify file upload accepted | blogspot.com | (never run) | — | — | — | — | — |
| 17 | Verify static web table visible | blogspot.com | (never run) | — | — | — | — | — |
| 18 | Verify pagination exposes page links | blogspot.com | (never run) | — | — | — | — | — |
| 19 | Verify alert button visible | blogspot.com | (never run) | — | — | — | — | — |
| 20 | Verify delayed button becomes visible | blogspot.com | (never run) | — | — | — | — | — |
| 21 | Verify AE home page loads | automationexercise.com | (never run) | — | — | — | — | — |
| 22 | Verify Signup/Login navigation | automationexercise.com | (never run) | — | — | — | — | — |
| 23 | Verify Women category visible | automationexercise.com | (never run) | — | — | — | — | — |
| 24 | **Verify dress search returns results** | automationexercise.com | **practicetestautomation.com ✗** | dsl-heuristic | ✗ | generic | ✗ | ✗ |
| 25 | Verify product detail page opens | automationexercise.com | (never run) | — | — | — | — | — |
| 26 | Verify add-to-cart confirmation | automationexercise.com | (never run) | — | — | — | — | — |
| 27 | Verify contact form reachable | automationexercise.com | (never run) | — | — | — | — | — |

*Cases 5–7 get specific SauceDemo hardcoded selectors from `isSauceDemoSortingCase()` / `isSauceDemoAddToCartCase()` / `isSauceDemoInventoryRemovalCase()` in `generateRunSpec.ts` — these ARE case-specific. Cases 1–4 and 8–9 on saucedemo.com get some SauceDemo-specific selectors (e.g. `sauceDemoLoginSelector`) but the overall flow is still template-driven.

**Confirmed TC-02 bug** (run-single-e69ab585):
- base_url: `https://practicetestautomation.com/practice-test-login` (wrong — leaked from case #10)
- step[0]: `goto: https://practicetestautomation.com/practice-test-login` — navigates to entirely wrong site
- step[1]: `wait_for_selector: input[type="search"], input[name*="search" i]...` — generic template, not `#search_product`
- Double failure: wrong site + wrong selectors even if the site were correct

**Also confirmed**: run-single-dc1bb5b1 = case #10 "Verify valid login reaches the logged-in page" with base_url = `practicetestautomation.com` — this is the run that poisoned localStorage, causing TC-02 to inherit its URL.

---

## Failure analysis — grouped by root cause

### Root Cause A — Base URL cross-contamination via localStorage (affects: all cases run after a different project's case)

**Evidence**: `run-single-e69ab585.json` — case 24 (dress search, should target automationexercise.com) ran against `practicetestautomation.com`.

**Mechanism**: `ChecklistDetailView.vue` — `readPersistedRunBaseUrl()` reads `localStorage.getItem('checklist_item_run_base_url')` and pre-fills the modal for every case regardless of project. After running case 10 (practicetestautomation.com login), the next modal that opens — for any case in any project — is pre-filled with that URL.

**Scope**: Every tester who switches between projects without manually correcting the URL will run cases against the wrong application. This silently produces incorrect results rather than an error.

**Cases at risk**: All 27 seeded cases. Any case run after a case from a different project is vulnerable.

---

### Root Cause B — Heuristic DSL generator ignores authored plan (affects: ALL cases on the old path)

**Evidence**: All 46 run specs use `dsl-heuristic` mode. The dress-search run spec used `input[type="search"], input[name*="search" i]...` instead of `#search_product` + `#submit_search`.

**Mechanism**: `generateRunSpec.ts:buildCasePlan()` (line 1382) always runs `extractRequiredInputs()`, `buildStepsAndAsserts()`, `buildPreflightChecks()` from scratch using keyword matching on `test_case_title` + `test_case_text`. It never reads `execution_profile.last_generated_plan` stored on the item (the authored plan from the seeder).

The seeder embeds high-quality, application-specific plans (e.g. `#search_product`, `#submit_search` for automationexercise.com; `#react-burger-menu-btn` for SauceDemo logout). These are completely discarded every time the agent regenerates the plan.

**Scope**: 100% of cases on the old DSL path. The cases where the heuristic happens to produce acceptable selectors (SauceDemo login/cart — cases 1–7) are lucky coincidences because `isSauceDemoTarget()` detects the URL and applies hardcoded SauceDemo selectors.

**Cases where the authored plan is CRITICAL** (authored plan uses selectors the heuristic would NOT produce):
- Case 4 (logout): authored uses `#react-burger-menu-btn` + `text:Logout`; heuristic would just navigate
- Case 5 (Z→A sort): authored uses `/inventory.html?sort=za`; heuristic produces a generic sort flow
- Case 8 (checkout validation): authored navigates cart → checkout step-by-step; heuristic produces a generic form flow
- Case 9 (full checkout): 8 authored steps; heuristic would not know to navigate cart → checkout
- Cases 14–20 (blogspot): authored uses specific IDs (`input#name`, `input#datepicker`, `input[type="file"]`); heuristic uses generic selectors
- Cases 21–27 (automationexercise): all authored with specific selectors; heuristic produces generic

---

### Root Cause C — Python path not yet reachable from the UI (affects: all cases)

**Evidence**: Zero of 46 run specs have `generation_mode: "python_script"` or `python_script_body`. The new Python path is only reachable via manual CLI (`node dist/python-gen/generateScript.js --input`).

**Mechanism**: `ExecuteSingleTestCaseRun.php` hardcodes the agent entrypoint to `generateRunSpec.js` (line 86) and the runner to `playwright-runner-job/dist/main.js` (line 86) — neither is switchable without modifying PHP.

**Scope**: All 27 cases. The new path cannot be used from the UI yet.

---

### Root Cause D — File upload case requires absolute path on executor machine (affects: case 16)

**Evidence**: The seeder embeds `upload_file = storage_path('app/testing/sample-upload.txt')` — an absolute path from the Laravel storage directory on the server where the seeder ran. When `ExecuteSingleTestCaseRun` runs on a different machine or in Docker, this path will not exist.

**Scope**: Case 16 only ("Verify sample upload file can be selected"). The path is baked into `execution_profile.required_inputs[0].value` in the database.

---

### Root Cause E — `input[type="search"]` selector doesn't exist on automationexercise.com/products

**Evidence**: The automation exercise search input uses `id="search_product"` and `type="text"` (not `type="search"`). The generic CSS selector `input[type="search"]` would match zero elements; the heuristic then falls through to `input[name*="search" i]` which also matches nothing.

**Scope**: Case 24 specifically. Also affects any future case where the generator defaults to `search_filter` coverage type on a site that uses `type="text"` for its search box.

---

## Prioritized fix list

### Fix 1 — Seed the modal URL from the project's `app_url`, not global localStorage

**Unblocks**: All 27 cases (prevents future cross-project contamination)  
**Risk**: Low — purely additive frontend change, no backend changes

**File**: `frontend/src/views/ChecklistDetailView.vue` — `openRunModal()` function (~line 558)

**Change**: After `await refreshExecutionState(item.id)`, instead of unconditionally reading `readPersistedRunBaseUrl()`, check if the checklist has a `project.app_url` and prefer it as the default. Keep localStorage only as a per-project fallback (keyed by project ID or checklist ID, not global).

Concretely: the project context URL is available as `$route.query.projectId` → already loaded in `projectContextChecklists`. Add a `projectUrl` fallback computed from the current checklist's project.

**Minimal fix** (no architecture change): store the base URL in localStorage under a project-scoped key: `checklist_run_base_url_project_{projectId}` instead of the single global key. The current behavior is a one-liner change in `persistRunBaseUrl` and `readPersistedRunBaseUrl`.

---

### Fix 2 — Pass the authored plan (seeder steps) through to the runner instead of regenerating

**Unblocks**: Cases 4, 5, 8, 9, 14–27 immediately. All 27 cases benefit.  
**Risk**: Medium — changes the agent invocation. Needs validation that the authored plan format is runner-compatible.

**File**: `backend/app/Jobs/ExecuteSingleTestCaseRun.php` and/or `ExecutionProfileService.php`

**Change**: Before calling the agent, check if `$item->execution_profile['last_generated_plan']['steps']` is already populated with authored steps. If so, write those steps directly into the run spec's `cases[0].steps` and `cases[0].asserts` instead of calling `generateRunSpec.js`. The agent would still be called for cases that have no authored plan (generating `required_inputs` and `intent_summary`), but its DSL step generation would be skipped when authored steps are available.

Alternatively (simpler): add a flag `skip_step_generation: true` to the agent input JSON, and in `generateRunSpec.ts`, when this flag is set, return the `execution_profile` data + the existing `steps/asserts` from the input payload (passed through as overrides) without running the heuristic `buildStepsAndAsserts()`.

---

### Fix 3 — Wire the Python path into Laravel behind an env flag

**Unblocks**: The entire new Python execution path (all cases)  
**Risk**: Medium — modifies `ExecuteSingleTestCaseRun.php`

**File**: `backend/app/Jobs/ExecuteSingleTestCaseRun.php` (lines 85–87 for agent, 169 for runner)

**Change**: Read `AGENT_GENERATION_MODE` from `.env`. If `python_script`, use `playwright-agent/dist/python-gen/generateScript.js` as agent entrypoint and `python-runner/wrapper.py` as runner (via `python3 wrapper.py` subprocess). Otherwise fall through to the existing DSL path. This keeps the old path as default (`dsl`) so nothing breaks until explicitly switched.

---

### Fix 4 — Fix the file upload path for case 16

**Unblocks**: Case 16 only  
**Risk**: Low

**File**: `backend/database/seeders/DemoAutomationProjectsSeeder.php` (line ~1161) / database (via re-seed)

**Change**: The `upload_file` required_input value is hardcoded to an absolute server path at seed time. Either: (a) make the seeder use a relative path from `storage_path()` that is guaranteed to exist, and have the executor resolve it; or (b) add a `SAMPLE_UPLOAD_FILE_PATH` env variable that the seeder and runner share.

---

## Questions requiring human decision before code changes

1. **Fix 2 scope**: Do you want the old DSL path to respect authored plans from the seeder? Or do you want to skip the heuristic entirely for all cases and move straight to the Python path (Fix 3)?

2. **Fix 1 scoping**: Should the base URL default be per-project (using `project.app_url` from the project context) or per-checklist? There's already a project context in `$route.query.projectId` — do you want the modal to pre-fill `project.app_url` when that context is present?

3. **Fix 3 env flag name**: `AGENT_GENERATION_MODE=python` confirmed as the intended switch?

4. **Sauce Demo cases (1–7)**: The heuristic generator has SauceDemo-specific code paths in `generateRunSpec.ts` that produce correct selectors for saucedemo.com. Do you want to keep those, or will they be deleted as part of the DSL cleanup?

5. **New Python path for blogspot/automationexercise cases (14–27)**: These cases have no credentials in `provided_inputs`. The Python path would send an `inputs = {}` dict to `wrapper.py`. The LLM would need to handle "no input required" cases cleanly — confirm the prompt handles this.

---

## Appendix: confirmed existing run spec findings

- **46 run spec files** in `backend/storage/app/agent-run-dsl/`
- Base URL distribution: 26× `127.0.0.1:4177` (local dev), 16× `saucedemo.com`, 2× `practicetestautomation.com`, 1× `example.test`, 1× `localhost:5173`
- **0 of 46** have `generation_mode: "python_script"` or `python_script_body`
- **0 of 46** have UTF-8 BOM
- **0 of 46** show mojibake in title or description fields
- **2 of 46** have wrong base URL (run-single-e69ab585, run-single-dc1bb5b1 — both from the `practicetestautomation.com` base URL leak)
