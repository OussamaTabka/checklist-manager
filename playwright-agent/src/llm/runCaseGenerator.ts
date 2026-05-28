import { z, type ZodIssue } from 'zod'
import { RunCaseDslSchema, type RunCaseDsl } from '../dslSchema.js'
import { buildGeneratorPrompt, type GeneratorChecklistItem } from '../prompts/generatorPrompt.js'
import { LLMProviderError, type LLMProvider } from './types.js'

export type GenerateRunCaseResult = {
  runCase: RunCaseDsl
  durationMs: number
  providerRetries: number
  correctionRetries: number
  rawResponses: string[]
  model: string
  provider: string
}

export class RunCaseGenerationFailedError extends Error {
  public readonly issues: readonly ZodIssue[]
  public readonly rawResponse: string
  public readonly reason: string
  public readonly debugFilePath: string | undefined
  public readonly model: string
  public readonly provider: string

  public constructor(message: string, options: {
    issues: readonly ZodIssue[]
    rawResponse: string
    reason: string
    debugFilePath?: string
    model: string
    provider: string
  }) {
    super(message)
    this.name = 'RunCaseGenerationFailedError'
    this.issues = options.issues
    this.rawResponse = options.rawResponse
    this.reason = options.reason
    this.debugFilePath = options.debugFilePath
    this.model = options.model
    this.provider = options.provider
  }
}

function mapCriticalityToSeverity(criticality: string | undefined): RunCaseDsl['severity'] | undefined {
  if (!criticality) {
    return undefined
  }

  switch (criticality.trim().toLowerCase()) {
    case 'critical':
      return 'critical'
    case 'high':
      return 'major'
    case 'medium':
      return 'major'
    case 'low':
      return 'minor'
    default:
      return undefined
  }
}

function applyDeterministicSeverity(runCase: RunCaseDsl, checklistItem: GeneratorChecklistItem): RunCaseDsl {
  const severity = mapCriticalityToSeverity(checklistItem.criticality)
  if (!severity) {
    return runCase
  }

  return {
    ...runCase,
    severity,
  }
}

function formatIssuePath(path: readonly PropertyKey[]): string {
  if (path.length === 0) {
    return '<root>'
  }

  return path
    .map((segment, index) => {
      if (typeof segment === 'number') {
        return `[${segment}]`
      }
      if (typeof segment === 'symbol') {
        return index === 0 ? segment.toString() : `.${segment.toString()}`
      }
      return index === 0 ? segment : `.${segment}`
    })
    .join('')
}

function buildCorrectionPrompt(
  checklistItem: GeneratorChecklistItem,
  lastRawResponse: string,
  zodIssues: readonly ZodIssue[],
): string {
  const issueLines = zodIssues.map((issue, index) => {
    const path = formatIssuePath(issue.path)
    return `${index + 1}. path=${path} message=${issue.message}`
  })

  return [
    'Correct the following RunCaseDsl JSON so it fully satisfies the schema and output rules.',
    `Checklist title: ${checklistItem.test_case_title}`,
    '',
    'Original invalid JSON output:',
    '```json',
    lastRawResponse,
    '```',
    '',
    'Validation errors:',
    ...issueLines,
    '',
    'Return ONLY the full corrected JSON object.',
    'Do not return a diff.',
    'Do not explain the fixes.',
    'Do not wrap the JSON in markdown fences.',
  ].join('\n')
}

export async function generateRunCase(
  provider: LLMProvider,
  checklistItem: GeneratorChecklistItem,
): Promise<GenerateRunCaseResult> {
  const startedAt = Date.now()
  const rawResponses: string[] = []
  let providerRetries = 0
  let correctionRetries = 0

  const initialPrompt = buildGeneratorPrompt(checklistItem)

  try {
    const result = await provider.generateJSON({ prompt: initialPrompt, temperature: 0.1 })
    rawResponses.push(result.rawResponse)
    providerRetries += result.retries

    const validated = RunCaseDslSchema.parse(result.data)
    return {
      runCase: applyDeterministicSeverity(validated, checklistItem),
      durationMs: Date.now() - startedAt,
      providerRetries,
      correctionRetries,
      rawResponses,
      model: result.model,
      provider: result.provider,
    }
  } catch (error) {
    if (error instanceof LLMProviderError) {
      throw error
    }

    if (!(error instanceof z.ZodError)) {
      throw error
    }

    let lastIssues = error.issues
    let lastRawResponse = rawResponses[rawResponses.length - 1] ?? ''

    while (correctionRetries < 2) {
      correctionRetries += 1
      const correctionPrompt = buildCorrectionPrompt(checklistItem, lastRawResponse, lastIssues)

      try {
        const corrected = await provider.generateJSON({ prompt: correctionPrompt, temperature: 0.1 })
        rawResponses.push(corrected.rawResponse)
        providerRetries += corrected.retries
        const validated = RunCaseDslSchema.parse(corrected.data)

        return {
          runCase: applyDeterministicSeverity(validated, checklistItem),
          durationMs: Date.now() - startedAt,
          providerRetries,
          correctionRetries,
          rawResponses,
          model: corrected.model,
          provider: corrected.provider,
        }
      } catch (correctionError) {
        if (correctionError instanceof LLMProviderError) {
          throw correctionError
        }

        if (!(correctionError instanceof z.ZodError)) {
          throw correctionError
        }

        lastIssues = correctionError.issues
        lastRawResponse = rawResponses[rawResponses.length - 1] ?? lastRawResponse
      }
    }

    throw new RunCaseGenerationFailedError(
      `${provider.name} failed to produce a valid RunCaseDsl after correction retries`,
      {
        issues: lastIssues,
        rawResponse: lastRawResponse,
        reason: 'schema_validation_failed',
        model: provider.model,
        provider: provider.name,
      },
    )
  }
}
