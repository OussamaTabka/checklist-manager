# Agent Test Examples

## 1. Generate a rich UI execution plan

Use:

```powershell
node .\playwright-agent\dist\generateRunSpec.js --input .\samples\agent-example-form-success.input.json
```

Expected outcome:
- `execution_profile` is present
- `required_inputs` includes values like name, email, phone, message
- `preflight_checks` includes a visible form check
- `generated_plan.steps` contains more than a simple `goto`

## 2. Verify blocked non-UI behavior

Use:

```powershell
node .\playwright-agent\dist\generateRunSpec.js --input .\samples\agent-example-non-ui-blocked.input.json
```

Expected outcome:
- `coverage_type` is `unsupported_non_ui`
- `preflight_checks` contains an `unsupported` check
- the runner should block this case during preflight instead of pretending it is a valid UI script

## 3. Run the generated UI sample locally

1. Generate a run spec:

```powershell
node .\playwright-agent\dist\generateRunSpec.js --input .\samples\agent-example-form-success.input.json > .\playwright-orchestrator\tmp\demo-form-success.run.json
```

2. Execute it with the local runner:

```powershell
$env:RUN_JSON_PATH = (Resolve-Path .\playwright-orchestrator\tmp\demo-form-success.run.json).Path
$env:RESULT_JSON_PATH = (Resolve-Path .\playwright-runner-job\tmp\live-trace-verify\result.json).Path
$env:ARTIFACTS_DIR = (Resolve-Path .\playwright-runner-job\tmp\live-trace-verify).Path + '\artifacts'
$env:LIVE_TRACE_PATH = (Resolve-Path .\playwright-runner-job\tmp\live-trace-verify).Path + '\live-trace.json'
node .\playwright-runner-job\dist\main.js
```

3. Inspect:
- `playwright-orchestrator\tmp\demo-form-success.run.json`
- `playwright-runner-job\tmp\live-trace-verify\result.json`
- `playwright-runner-job\tmp\live-trace-verify\live-trace.json`

## What to look for in the result

- `generated_plan`
- `failure_source` when blocked or failed
- `execution_trace` with `Planning`, `Preflight`, `Step`, `Assert`, and `Failure analysis`
