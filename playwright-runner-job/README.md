# Playwright Runner Job (TypeScript, Docker)

This runner uses **Model B**: one Docker container execution for one run.

- Input: `RunRequest DSL v1` JSON file (default `/work/run.json`)
- Output: `RunResult DSL v1` JSON file (default `/work/result.json`)
- Artifacts: default `/work/artifacts/<external_id>/`
- Browser for MVP: **Chromium only**

The container is not an HTTP service. It starts, executes, writes result/artifacts, and exits.

## Exit Codes

- `0`: run finished (even if some cases are failed)
- `2`: invalid_spec
- `3`: runner_error

## Runtime Paths

Environment variables:

- `RUN_JSON_PATH` (default: `/work/run.json`)
- `RESULT_JSON_PATH` (default: `/work/result.json`)
- `ARTIFACTS_DIR` (default: `/work/artifacts`)

## Build

```bash
docker build -t checklist-playwright-job ./playwright-runner-job
```

## Run (PowerShell)

1. Prepare a working folder, for example `runs/12345`
2. Put your request file at `runs/12345/run.json`
3. Execute:

```powershell
docker run --rm `
  --mount type=bind,source="${PWD}\runs\12345",target=/work `
  -e RUN_JSON_PATH=/work/run.json `
  -e RESULT_JSON_PATH=/work/result.json `
  -e ARTIFACTS_DIR=/work/artifacts `
  -e E2E_EMAIL="tester@example.com" `
  -e E2E_PASSWORD="secret" `
  checklist-playwright-job
```

After completion:

- `runs/12345/result.json`
- `runs/12345/artifacts/<external_id>/...`

## Local Node Usage (without Docker)

```bash
cd playwright-runner-job
npm install
npm run build
RUN_JSON_PATH=./examples/run.sample.json RESULT_JSON_PATH=./result.json node dist/main.js
```

## Notes on Determinism

- One browser instance per run.
- One context + one page per case (strict isolation).
- Per-case `try/catch` so one case error does not stop all cases.
- Env template replacement in string values with `${ENV_VAR}`.
- Missing env var in a case marks that case as `blocked` with `error_type="missing_env_var"`.

## Included Sample

- `examples/run.sample.json`

You can copy this file into your mounted `/work/run.json` and adapt selectors/URLs.
