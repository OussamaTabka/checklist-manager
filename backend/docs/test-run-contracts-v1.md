# Test Run Contracts v1

This document freezes the initial payload contracts between Agent, Runner, and Laravel.

## A) RunRequest (Agent -> Runner)

```json
{
  "schema_version": "1.0",
  "run_id": "9f6d7c36-12c3-4d8e-8f4e-65895d8f8fbb",
  "base_url": "{{APP_BASE_URL}}",
  "cases": [
    {
      "external_id": 1234,
      "title": "Login should succeed",
      "steps": [
        { "action": "goto", "path": "/login" },
        {
          "action": "fill",
          "selector": { "by": "testid", "testid": "auth-login-email-input" },
          "value": "${E2E_EMAIL}"
        }
      ],
      "asserts": [
        { "assert": "expect_url_contains", "value": "/dashboard" }
      ]
    }
  ]
}
```

## B) RunResult (Runner -> Agent/Laravel)

```json
{
  "run_id": "9f6d7c36-12c3-4d8e-8f4e-65895d8f8fbb",
  "results": [
    {
      "external_id": 1234,
      "status": "passed",
      "error_type": null,
      "error_message": null,
      "duration_ms": 2345,
      "artifacts": {
        "trace": ["https://artifacts.local/run/1234/trace.zip"],
        "screenshot": ["https://artifacts.local/run/1234/failure.png"],
        "video": ["https://artifacts.local/run/1234/video.webm"]
      }
    }
  ]
}
```

## Status mapping to version_items.status

- `passed` -> `Passed`
- `failed` -> `Failed`
- `blocked` -> `Blocked`
- `skipped` -> `Not Tested`
