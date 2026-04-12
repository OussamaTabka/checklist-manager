export type CaseStatus = 'passed' | 'failed' | 'blocked' | 'skipped'

export type ResultErrorType =
  | 'missing_env_var'
  | 'selector_not_found'
  | 'navigation_timeout'
  | 'assertion_failed'
  | 'unexpected_error'

export interface CaseArtifacts {
  trace_path: string | null
  screenshot_path: string | null
  video_path: string | null
}

export interface CaseResultV1 {
  external_id: number
  status: CaseStatus
  attempt: number
  duration_ms: number
  error_type: ResultErrorType | null
  error_message: string | null
  artifacts: CaseArtifacts
  execution_trace?: string[]
}

export interface RunSummary {
  passed: number
  failed: number
  blocked: number
  skipped: number
}

export interface RunResultV1 {
  schema_version: '1.0'
  run_id: string
  runner_run_id: string
  base_url_used?: string
  status: 'done' | 'invalid_spec' | 'runner_error'
  summary: RunSummary
  results: CaseResultV1[]
}

export class MissingEnvVarError extends Error {
  public readonly variableName: string

  constructor(variableName: string) {
    super(`Missing required environment variable: ${variableName}`)
    this.name = 'MissingEnvVarError'
    this.variableName = variableName
  }
}

export class AssertionFailureError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'AssertionFailureError'
  }
}

export interface NormalizedError {
  error_type: ResultErrorType
  error_message: string
}

export function normalizeError(error: unknown): NormalizedError {
  if (error instanceof MissingEnvVarError) {
    return {
      error_type: 'missing_env_var',
      error_message: error.message,
    }
  }

  if (error instanceof AssertionFailureError) {
    return {
      error_type: 'assertion_failed',
      error_message: error.message,
    }
  }

  const message = error instanceof Error ? error.message : String(error)

  const lower = message.toLowerCase()

  if (lower.includes('timeout')) {
    if (
      lower.includes('page.goto') ||
      lower.includes('waitforurl') ||
      lower.includes('navigation')
    ) {
      return {
        error_type: 'navigation_timeout',
        error_message: message,
      }
    }

    if (lower.includes('selector') || lower.includes('locator') || lower.includes('waitfor')) {
      return {
        error_type: 'selector_not_found',
        error_message: message,
      }
    }
  }

  if (lower.includes('selector') || lower.includes('locator')) {
    return {
      error_type: 'selector_not_found',
      error_message: message,
    }
  }

  if (lower.includes('navigation') && lower.includes('timeout')) {
    return {
      error_type: 'navigation_timeout',
      error_message: message,
    }
  }

  return {
    error_type: 'unexpected_error',
    error_message: message,
  }
}

export function summarizeResults(results: CaseResultV1[]): RunSummary {
  return results.reduce<RunSummary>(
    (acc, result) => {
      acc[result.status] += 1
      return acc
    },
    {
      passed: 0,
      failed: 0,
      blocked: 0,
      skipped: 0,
    },
  )
}
