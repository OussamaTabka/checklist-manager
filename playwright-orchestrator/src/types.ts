export type RuntimeTraceMode = 'retain-on-failure' | 'off'
export type RuntimeVideoMode = 'retain-on-failure' | 'off'
export type RuntimeScreenshotMode = 'only-on-failure' | 'off'

type RunAuthApi = {
  mode: 'api'
  csrf_cookie_url: string
  login_url: string
  verify_url?: string
  username_env: string
  password_env: string
  username_field?: string
  password_field?: string
  body_format?: 'json' | 'form'
}

type RunAuthUi = {
  mode: 'ui'
}

export interface RunRequestDslV1 {
  schema_version: '1.0'
  run_id: string
  target: {
    base_url: string
  }
  runtime: {
    headless: boolean
    timeout_ms: number
    viewport: {
      width: number
      height: number
    }
    trace: RuntimeTraceMode
    video: RuntimeVideoMode
    screenshot: RuntimeScreenshotMode
  }
  cases: Array<{
    external_id: number
    title: string
    severity?: 'minor' | 'major' | 'critical'
    use_auth?: boolean
    steps: Array<Record<string, unknown>>
    asserts: Array<Record<string, unknown>>
  }>
  auth?: RunAuthApi | RunAuthUi
}

export type CaseStatus = 'passed' | 'failed' | 'blocked' | 'skipped'

export interface RunnerCaseResult {
  external_id: number
  status: CaseStatus
  attempt: number
  duration_ms: number
  error_type: string | null
  error_message: string | null
  artifacts: {
    trace_path: string | null
    screenshot_path: string | null
    video_path: string | null
  }
}

export interface RunnerResultV1 {
  schema_version: '1.0'
  run_id: string
  runner_run_id: string
  base_url_used?: string
  status: 'done' | 'invalid_spec' | 'runner_error'
  summary: {
    passed: number
    failed: number
    blocked: number
    skipped: number
  }
  results: RunnerCaseResult[]
}

export interface CreateRunResponse {
  run_id: string
  status: string
}
