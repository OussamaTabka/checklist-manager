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

export interface RequiredInputDsl {
  key: string
  label: string
  kind: 'text' | 'email' | 'password' | 'textarea' | 'search' | 'file'
  required: boolean
  description?: string
  value?: string | null
}

export interface ExecutionDiagnosticDsl {
  code: string
  message: string
  severity: 'info' | 'warning' | 'error'
}

export interface PreflightCheckDsl {
  id: string
  kind: 'page_accessible' | 'element_visible' | 'element_attached' | 'url_contains' | 'input_available' | 'unsupported'
  label: string
  required: boolean
  selector?: Record<string, unknown>
  expected?: string
  input_key?: string
  failure_message: string
}

export interface GeneratedPlanDsl {
  title?: string
  intent_summary: string
  coverage_type: string
  preflight_checks: PreflightCheckDsl[]
  steps: Array<Record<string, unknown>>
  asserts: Array<Record<string, unknown>>
  expected_observations: string[]
  diagnostics: ExecutionDiagnosticDsl[]
}

export interface ExecutionProfileDsl {
  intent_summary: string
  coverage_type: string
  preconditions: string[]
  required_inputs: RequiredInputDsl[]
  expected_observations: string[]
  diagnostics: ExecutionDiagnosticDsl[]
  generation_confidence?: number
  last_generated_plan?: GeneratedPlanDsl
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
    execution_profile?: ExecutionProfileDsl
    generated_plan?: GeneratedPlanDsl
    preflight_checks?: PreflightCheckDsl[]
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
  generated_plan?: Record<string, unknown> | null
  failure_source?: {
    phase: 'planning' | 'preflight' | 'step' | 'assert' | 'runtime'
    reference: string
    message: string
  } | null
  artifacts: {
    trace_path: string | null
    screenshot_path: string | null
    video_path: string | null
  }
  execution_trace?: string[]
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
