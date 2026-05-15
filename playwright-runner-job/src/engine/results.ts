export type CaseStatus = 'passed' | 'failed' | 'blocked' | 'skipped'

export type ResultErrorType =
  | 'missing_env_var'
  | 'selector_not_found'
  | 'navigation_timeout'
  | 'assertion_failed'
  | 'precondition_failed'
  | 'unsupported_test_case'
  | 'input_data_missing'
  | 'ambiguous_target'
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
