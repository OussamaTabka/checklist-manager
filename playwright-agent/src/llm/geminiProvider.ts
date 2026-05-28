import {
  GoogleGenerativeAI,
  type GenerateContentResult,
} from '@google/generative-ai'
import fs from 'node:fs/promises'
import path from 'node:path'
import type { GeminiConfig } from '../config.js'
import type {
  LLMGenerationRequest,
  LLMGenerationResult,
  LLMProvider,
} from './types.js'
import { LLMProviderError } from './types.js'

function stripMarkdownFences(text: string): string {
  const trimmed = text.trim()
  const fencedMatch = trimmed.match(/^```(?:json)?\s*([\s\S]*?)\s*```$/i)
  return fencedMatch ? fencedMatch[1]!.trim() : trimmed
}

function extractBalancedJsonObject(text: string, startIndex: number): string | null {
  if (text[startIndex] !== '{') {
    return null
  }

  let depth = 0
  let inString = false
  let escaped = false

  for (let index = startIndex; index < text.length; index += 1) {
    const char = text[index]

    if (escaped) {
      escaped = false
      continue
    }

    if (char === '\\') {
      escaped = true
      continue
    }

    if (char === '"') {
      inString = !inString
      continue
    }

    if (inString) {
      continue
    }

    if (char === '{') {
      depth += 1
      continue
    }

    if (char === '}') {
      depth -= 1
      if (depth === 0) {
        return text.slice(startIndex, index + 1)
      }
    }
  }

  return null
}

function looksLikeRawPlaywrightCode(text: string): boolean {
  const normalized = text.trim()
  if (normalized === '') {
    return false
  }

  return /(?:import\s+\{[^}]*test[^}]*\}\s+from\s+['"]@playwright\/test['"]|await\s+page\.|page\.(?:goto|click|fill|locator|getByRole|getByText|getByTestId)|test\(['"`])/.test(normalized)
}

async function maybeWriteDebugRawResponse(rawResponse: string): Promise<string | undefined> {
  if (process.env.AGENT_DEBUG_GEMINI !== 'true') {
    return undefined
  }

  const debugPath = path.resolve(process.cwd(), 'tmp', 'gemini-last-raw-response.txt')
  await fs.mkdir(path.dirname(debugPath), { recursive: true })
  await fs.writeFile(debugPath, rawResponse, 'utf8')
  return debugPath
}

async function parseJsonObjectOrThrow(rawResponse: string, model: string): Promise<unknown> {
  const cleaned = stripMarkdownFences(rawResponse).trim()

  if (cleaned === '') {
    const debugFilePath = await maybeWriteDebugRawResponse(rawResponse)
    throw new LLMProviderError('Gemini returned empty response', {
      provider: 'gemini',
      model,
      reason: 'empty_response',
      rawResponse,
      debugFilePath,
    })
  }

  if (looksLikeRawPlaywrightCode(cleaned)) {
    const debugFilePath = await maybeWriteDebugRawResponse(rawResponse)
    throw new LLMProviderError('Gemini returned raw Playwright code instead of JSON', {
      provider: 'gemini',
      model,
      reason: 'raw_playwright_code',
      rawResponse,
      debugFilePath,
    })
  }

  const candidates: string[] = []

  if (cleaned.startsWith('{')) {
    candidates.push(cleaned)
  }

  for (let index = 0; index < cleaned.length; index += 1) {
    if (cleaned[index] !== '{') {
      continue
    }

    const candidate = extractBalancedJsonObject(cleaned, index)
    if (candidate && !candidates.includes(candidate)) {
      candidates.push(candidate)
    }
  }

  if (candidates.length === 0) {
    const debugFilePath = await maybeWriteDebugRawResponse(rawResponse)
    throw new LLMProviderError('Gemini returned invalid JSON', {
      provider: 'gemini',
      model,
      reason: 'invalid_json',
      rawResponse,
      debugFilePath,
    })
  }

  for (const candidate of candidates) {
    try {
      const parsed = JSON.parse(candidate)
      if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
        const debugFilePath = await maybeWriteDebugRawResponse(rawResponse)
        throw new LLMProviderError('Gemini returned non-object JSON response', {
          provider: 'gemini',
          model,
          reason: 'non_object_response',
          rawResponse,
          debugFilePath,
        })
      }

      return parsed
    } catch (error) {
      if (error instanceof LLMProviderError) {
        throw error
      }
    }
  }

  const debugFilePath = await maybeWriteDebugRawResponse(rawResponse)
  throw new LLMProviderError('Gemini returned invalid JSON', {
    provider: 'gemini',
    model,
    reason: 'invalid_json',
    rawResponse,
    debugFilePath,
  })
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => {
    setTimeout(resolve, ms)
  })
}

function computeBackoffDelayMs(attemptIndex: number): number {
  const baseMs = 1_000 * (2 ** attemptIndex)
  const jitterFactor = 0.75 + (Math.random() * 0.5)
  return Math.max(250, Math.round(baseMs * jitterFactor))
}

function isRetryableGeminiError(error: unknown): boolean {
  const maybeError = error as {
    status?: number
    errorDetails?: Array<{ reason?: string }>
    message?: string
    name?: string
  } | undefined

  const reasons = Array.isArray(maybeError?.errorDetails)
    ? maybeError?.errorDetails.map((detail) => detail.reason).filter(Boolean)
    : []

  return maybeError?.status === 429
    || maybeError?.status === 500
    || maybeError?.status === 502
    || maybeError?.status === 503
    || maybeError?.status === 504
    || reasons.includes('RESOURCE_EXHAUSTED')
    || reasons.includes('UNAVAILABLE')
    || reasons.includes('DEADLINE_EXCEEDED')
    || maybeError?.name === 'AbortError'
}

function classifyGeminiReason(error: unknown): 'api_error' | 'timeout' | 'rate_limit' {
  const maybeError = error as {
    status?: number
    errorDetails?: Array<{ reason?: string }>
    name?: string
  } | undefined

  const reasons = Array.isArray(maybeError?.errorDetails)
    ? maybeError?.errorDetails.map((detail) => detail.reason).filter(Boolean)
    : []

  if (maybeError?.name === 'AbortError') {
    return 'timeout'
  }

  if (maybeError?.status === 429 || reasons.includes('RESOURCE_EXHAUSTED')) {
    return 'rate_limit'
  }

  return 'api_error'
}

function buildGeminiErrorMessage(model: string, apiKeyPresent: boolean, error: unknown): string {
  const maybeError = error as {
    status?: number
    errorDetails?: Array<{ reason?: string }>
    message?: string
    name?: string
  } | undefined

  const firstReason = Array.isArray(maybeError?.errorDetails)
    ? maybeError.errorDetails.find((detail) => typeof detail.reason === 'string')?.reason
    : undefined

  const parts = [
    'Gemini API error',
    `model=${model}`,
    `api_key_present=${apiKeyPresent ? 'yes' : 'no'}`,
  ]

  if (typeof maybeError?.status === 'number') {
    parts.push(`http_status=${maybeError.status}`)
  }

  if (firstReason) {
    parts.push(`error_code=${firstReason}`)
  }

  if (maybeError?.name) {
    parts.push(`error_name=${maybeError.name}`)
  }

  if (maybeError?.message) {
    parts.push(`message=${maybeError.message}`)
  }

  return parts.join('; ')
}

async function runWithTimeout<T>(operation: Promise<T>, timeoutMs: number): Promise<T> {
  let timeoutHandle: NodeJS.Timeout | undefined

  try {
    const timeoutPromise = new Promise<T>((_, reject) => {
      timeoutHandle = setTimeout(() => {
        const error = new Error(`Gemini request aborted after ${timeoutMs}ms`)
        error.name = 'AbortError'
        reject(error)
      }, timeoutMs)
    })

    return await Promise.race([operation, timeoutPromise])
  } finally {
    if (timeoutHandle) {
      clearTimeout(timeoutHandle)
    }
  }
}

export function createGeminiProvider(config: GeminiConfig): LLMProvider {
  const client = new GoogleGenerativeAI(config.apiKey)
  const model = client.getGenerativeModel({ model: config.model })

  return {
    name: 'gemini',
    model: config.model,
    async generateJSON(request: LLMGenerationRequest): Promise<LLMGenerationResult> {
      const timeoutMs = request.timeoutMs ?? config.timeoutMs
      const maxRetries = request.maxRetries ?? config.maxRetries
      const startedAt = Date.now()
      let attempt = 0

      while (true) {
        try {
          const result = await runWithTimeout<GenerateContentResult>(
            model.generateContent({
              contents: [
                {
                  role: 'user',
                  parts: [{ text: request.prompt }],
                },
              ],
              generationConfig: {
                temperature: request.temperature ?? 0,
                responseMimeType: 'application/json',
              },
            }),
            timeoutMs,
          )

          const rawResponse = result.response.text().trim()
          const parsed = await parseJsonObjectOrThrow(rawResponse, config.model)

          return {
            data: parsed,
            rawResponse,
            durationMs: Date.now() - startedAt,
            retries: attempt,
            model: config.model,
            provider: 'gemini',
          }
        } catch (error) {
          if (error instanceof LLMProviderError) {
            throw error
          }

          if (isRetryableGeminiError(error) && attempt < maxRetries) {
            const backoffDelayMs = computeBackoffDelayMs(attempt)
            attempt += 1
            await sleep(backoffDelayMs)
            continue
          }

          throw new LLMProviderError(buildGeminiErrorMessage(config.model, config.apiKey !== '', error), {
            provider: 'gemini',
            model: config.model,
            reason: classifyGeminiReason(error),
            retries: attempt,
            httpStatus: typeof (error as { status?: unknown })?.status === 'number'
              ? ((error as { status: number }).status)
              : undefined,
            errorCode: Array.isArray((error as { errorDetails?: Array<{ reason?: string }> })?.errorDetails)
              ? (error as { errorDetails: Array<{ reason?: string }> }).errorDetails.find((detail) => typeof detail.reason === 'string')?.reason
              : undefined,
            apiKeyPresent: config.apiKey !== '',
          })
        }
      }
    },
  }
}
