import Anthropic from '@anthropic-ai/sdk'
import fs from 'node:fs/promises'
import path from 'node:path'
import type { ClaudeConfig } from '../config.js'
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
  if (process.env.AGENT_DEBUG_CLAUDE !== 'true') {
    return undefined
  }

  const debugPath = path.resolve(process.cwd(), 'tmp', 'claude-last-raw-response.txt')
  await fs.mkdir(path.dirname(debugPath), { recursive: true })
  await fs.writeFile(debugPath, rawResponse, 'utf8')
  return debugPath
}

function parseJsonObjectOrThrow(rawResponse: string, model: string): Promise<unknown> {
  return (async () => {
    const cleaned = stripMarkdownFences(rawResponse).trim()

    if (cleaned === '') {
      const debugFilePath = await maybeWriteDebugRawResponse(rawResponse)
      throw new LLMProviderError('Claude returned empty response', {
        provider: 'claude',
        model,
        reason: 'empty_response',
        rawResponse,
        debugFilePath,
      })
    }

    if (looksLikeRawPlaywrightCode(cleaned)) {
      const debugFilePath = await maybeWriteDebugRawResponse(rawResponse)
      throw new LLMProviderError('Claude returned raw Playwright code instead of JSON', {
        provider: 'claude',
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
      throw new LLMProviderError('Claude returned invalid JSON', {
        provider: 'claude',
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
          throw new LLMProviderError('Claude returned non-object JSON response', {
            provider: 'claude',
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
    throw new LLMProviderError('Claude returned invalid JSON', {
      provider: 'claude',
      model,
      reason: 'invalid_json',
      rawResponse,
      debugFilePath,
    })
  })()
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

function isRetryableAnthropicError(error: unknown): boolean {
  const maybeError = error as {
    status?: number
    error?: { type?: string }
    name?: string
  } | undefined

  const status = maybeError?.status
  const type = maybeError?.error?.type
  const name = maybeError?.name

  return status === 429
    || status === 500
    || status === 502
    || status === 503
    || status === 504
    || type === 'rate_limit_error'
    || type === 'api_error'
    || name === 'AbortError'
}

function buildAnthropicErrorMessage(model: string, apiKeyPresent: boolean, error: unknown): string {
  const maybeError = error as {
    status?: number
    error?: { type?: string; message?: string }
    message?: string
    name?: string
  } | undefined

  const parts = [
    'Claude API error',
    `model=${model}`,
    `api_key_present=${apiKeyPresent ? 'yes' : 'no'}`,
  ]

  if (typeof maybeError?.status === 'number') {
    parts.push(`http_status=${maybeError.status}`)
  }

  if (maybeError?.error?.type) {
    parts.push(`error_code=${maybeError.error.type}`)
  }

  if (maybeError?.name) {
    parts.push(`error_name=${maybeError.name}`)
  }

  if (maybeError?.error?.message) {
    parts.push(`message=${maybeError.error.message}`)
  } else if (maybeError?.message) {
    parts.push(`message=${maybeError.message}`)
  }

  return parts.join('; ')
}

function classifyAnthropicReason(error: unknown): 'api_error' | 'timeout' | 'rate_limit' {
  const maybeError = error as {
    status?: number
    error?: { type?: string }
    name?: string
  } | undefined

  if (maybeError?.name === 'AbortError') {
    return 'timeout'
  }

  if (maybeError?.status === 429 || maybeError?.error?.type === 'rate_limit_error') {
    return 'rate_limit'
  }

  return 'api_error'
}

async function runWithTimeout<T>(operation: Promise<T>, timeoutMs: number): Promise<T> {
  let timeoutHandle: NodeJS.Timeout | undefined

  try {
    const timeoutPromise = new Promise<T>((_, reject) => {
      timeoutHandle = setTimeout(() => {
        const error = new Error(`Claude request aborted after ${timeoutMs}ms`)
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

export function createClaudeProvider(config: ClaudeConfig): LLMProvider {
  const client = new Anthropic({
    apiKey: config.apiKey,
    maxRetries: 0,
  })

  return {
    name: 'claude',
    model: config.model,
    async generateJSON(request: LLMGenerationRequest): Promise<LLMGenerationResult> {
      const temperature = request.temperature ?? 0
      const maxTokens = request.maxTokens ?? config.maxTokens
      const timeoutMs = request.timeoutMs ?? config.timeoutMs
      const maxRetries = request.maxRetries ?? config.maxRetries
      const systemPrompt = request.systemPrompt
        ?? 'You are a QA automation planner. Return only one valid JSON object. No markdown. No explanation.'

      const startedAt = Date.now()
      let attempt = 0

      while (true) {
        try {
          const message = await runWithTimeout(
            client.messages.create({
              model: config.model,
              max_tokens: maxTokens,
              temperature,
              system: systemPrompt,
              messages: [
                {
                  role: 'user',
                  content: request.prompt,
                },
              ],
            }),
            timeoutMs,
          )

          const text = message.content
            .filter((block): block is Anthropic.TextBlock => block.type === 'text')
            .map((block) => block.text)
            .join('\n')
            .trim()

          const parsed = await parseJsonObjectOrThrow(text, config.model)

          return {
            data: parsed,
            rawResponse: text,
            durationMs: Date.now() - startedAt,
            retries: attempt,
            model: config.model,
            provider: 'claude',
          }
        } catch (error) {
          if (error instanceof LLMProviderError) {
            throw error
          }

          if (!isRetryableAnthropicError(error) || attempt >= maxRetries) {
            const anyError = error as { status?: number; error?: { type?: string } } | undefined
            throw new LLMProviderError(
              buildAnthropicErrorMessage(config.model, config.apiKey !== '', error),
              {
                provider: 'claude',
                model: config.model,
                reason: classifyAnthropicReason(error),
                httpStatus: anyError?.status,
                errorCode: anyError?.error?.type,
                apiKeyPresent: config.apiKey !== '',
              },
            )
          }

          const backoffDelayMs = computeBackoffDelayMs(attempt)
          attempt += 1
          await sleep(backoffDelayMs)
        }
      }
    },
  }
}
