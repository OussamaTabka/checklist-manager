export type CaseStatus = 'passed' | 'failed' | 'blocked' | 'skipped'

export type ResultErrorType =
  | 'missing_env_var'
  | 'selector_not_found'
  | 'navigation_timeout'
  | 'url_unreachable'
  | 'assertion_failed'
  | 'authentication_failed'
  | 'timeout'
  | 'script_generation_failed'
  | 'precondition_failed'
  | 'unsupported_test_case'
  | 'input_data_missing'
  | 'ambiguous_target'
  | 'unexpected_error'
  | 'unknown'

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
  generated_plan?: Record<string, unknown> | null
  failure_source?: {
    phase: 'planning' | 'preflight' | 'step' | 'assert' | 'runtime'
    reference: string
    message: string
  } | null
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

export class PreconditionFailureError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'PreconditionFailureError'
  }
}

export class UnsupportedTestCaseError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'UnsupportedTestCaseError'
  }
}

export class InputDataMissingError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'InputDataMissingError'
  }
}

export class AmbiguousTargetError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'AmbiguousTargetError'
  }
}

export interface NormalizedError {
  error_type: ResultErrorType
  error_message: string
}

function isUrlReachabilityError(lower: string): boolean {
  return [
    'err_name_not_resolved',
    'err_connection_refused',
    'err_connection_timed_out',
    'err_connection_closed',
    'err_internet_disconnected',
    'econnrefused',
    'enotfound',
    'net::',
    'dns',
    'socket hang up',
  ].some((pattern) => lower.includes(pattern))
}

function isAuthenticationError(lower: string): boolean {
  return [
    'api login failed',
    'auth verify failed',
    'csrf bootstrap failed',
    'authentication failed',
    'unauthorized',
    'forbidden',
    'invalid credentials',
    'login failed',
  ].some((pattern) => lower.includes(pattern))
}

function isSelectorError(lower: string): boolean {
  return lower.includes('selector') || lower.includes('locator')
}

export function normalizeError(error: unknown): NormalizedError {
  if (error instanceof MissingEnvVarError) {
    return {
      error_type: 'missing_env_var',
      error_message: `A required environment value is missing for the automated run: ${error.variableName}.`,
    }
  }

  if (error instanceof AssertionFailureError) {
    return {
      error_type: 'assertion_failed',
      error_message: error.message,
    }
  }

  if (error instanceof PreconditionFailureError) {
    return {
      error_type: 'precondition_failed',
      error_message: error.message,
    }
  }

  if (error instanceof UnsupportedTestCaseError) {
    return {
      error_type: 'unsupported_test_case',
      error_message: error.message,
    }
  }

  if (error instanceof InputDataMissingError) {
    return {
      error_type: 'input_data_missing',
      error_message: error.message,
    }
  }

  if (error instanceof AmbiguousTargetError) {
    return {
      error_type: 'ambiguous_target',
      error_message: error.message,
    }
  }

  const message = error instanceof Error ? error.message : String(error)

  const lower = message.toLowerCase()

  if (isAuthenticationError(lower)) {
    return {
      error_type: 'authentication_failed',
      error_message: 'Authentication failed before or during the automated test flow.',
    }
  }

  if (isUrlReachabilityError(lower)) {
    return {
      error_type: 'url_unreachable',
      error_message: 'The target URL could not be reached by Playwright.',
    }
  }

  if (lower.includes('timeout')) {
    if (
      lower.includes('page.goto') ||
      lower.includes('waitforurl') ||
      lower.includes('navigation')
    ) {
      return {
        error_type: 'timeout',
        error_message: 'The target page did not finish loading in time.',
      }
    }

    if (isSelectorError(lower) || lower.includes('waitfor')) {
      return {
        error_type: 'selector_not_found',
        error_message: 'A required element did not appear before the timeout expired.',
      }
    }

    return {
      error_type: 'timeout',
      error_message: 'The automated step timed out before completion.',
    }
  }

  if (isSelectorError(lower)) {
    return {
      error_type: 'selector_not_found',
      error_message: 'A required button, field, or selector was not found on the page.',
    }
  }

  return {
    error_type: 'unknown',
    error_message: message || 'An unknown Playwright error occurred during the automated run.',
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
