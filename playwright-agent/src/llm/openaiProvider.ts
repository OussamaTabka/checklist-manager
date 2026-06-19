import fs from 'node:fs/promises'
import path from 'node:path'
import type { OpenAIConfig } from '../config.js'
import type {
  LLMGenerationRequest,
  LLMGenerationResult,
  LLMProvider,
} from './types.js'
import { LLMProviderError } from './types.js'

type OpenAIChatResponse = {
  choices?: Array<{
    message?: {
      content?: string | Array<{ type?: string; text?: string }>
    }
  }>
  error?: {
    type?: string
    code?: string
    message?: string
  }
}

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
  if (process.env.AGENT_DEBUG_OPENAI !== 'true') {
    return undefined
  }

  const debugPath = path.resolve(process.cwd(), 'tmp', 'openai-last-raw-response.txt')
  await fs.mkdir(path.dirname(debugPath), { recursive: true })
  await fs.writeFile(debugPath, rawResponse, 'utf8')
  return debugPath
}

async function parseJsonObjectOrThrow(rawResponse: string, model: string): Promise<unknown> {
  const cleaned = stripMarkdownFences(rawResponse).trim()

  if (cleaned === '') {
    const debugFilePath = await maybeWriteDebugRawResponse(rawResponse)
    throw new LLMProviderError('OpenAI returned empty response', {
      provider: 'openai',
      model,
      reason: 'empty_response',
      rawResponse,
      debugFilePath,
    })
  }

  if (!cleaned.startsWith('{') && looksLikeRawPlaywrightCode(cleaned)) {
    const debugFilePath = await maybeWriteDebugRawResponse(rawResponse)
    throw new LLMProviderError('OpenAI returned raw Playwright code instead of JSON', {
      provider: 'openai',
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
    throw new LLMProviderError('OpenAI returned invalid JSON', {
      provider: 'openai',
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
        throw new LLMProviderError('OpenAI returned non-object JSON response', {
          provider: 'openai',
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
  throw new LLMProviderError('OpenAI returned invalid JSON', {
    provider: 'openai',
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

function extractResponseText(body: OpenAIChatResponse): string {
  const content = body.choices?.[0]?.message?.content

  if (typeof content === 'string') {
    return content.trim()
  }

  if (Array.isArray(content)) {
    return content
      .map((chunk) => (typeof chunk?.text === 'string' ? chunk.text : ''))
      .join('\n')
      .trim()
  }

  return ''
}

function classifyOpenAIReason(error: unknown): 'api_error' | 'timeout' | 'rate_limit' {
  const maybeError = error as {
    name?: string
    httpStatus?: number
  } | undefined

  if (maybeError?.name === 'AbortError') {
    return 'timeout'
  }

  if (maybeError?.httpStatus === 429) {
    return 'rate_limit'
  }

  return 'api_error'
}

function isRetryableOpenAIError(error: unknown): boolean {
  const maybeError = error as {
    name?: string
    httpStatus?: number
  } | undefined

  return maybeError?.name === 'AbortError'
    || maybeError?.httpStatus === 429
    || maybeError?.httpStatus === 500
    || maybeError?.httpStatus === 502
    || maybeError?.httpStatus === 503
    || maybeError?.httpStatus === 504
}

function buildOpenAIErrorMessage(model: string, apiKeyPresent: boolean, error: unknown): string {
  const maybeError = error as {
    name?: string
    message?: string
    httpStatus?: number
    errorCode?: string
  } | undefined

  const parts = [
    'OpenAI API error',
    `model=${model}`,
    `api_key_present=${apiKeyPresent ? 'yes' : 'no'}`,
  ]

  if (typeof maybeError?.httpStatus === 'number') {
    parts.push(`http_status=${maybeError.httpStatus}`)
  }

  if (maybeError?.errorCode) {
    parts.push(`error_code=${maybeError.errorCode}`)
  }

  if (maybeError?.name) {
    parts.push(`error_name=${maybeError.name}`)
  }

  if (maybeError?.message) {
    parts.push(`message=${maybeError.message}`)
  }

  return parts.join('; ')
}

function withTimeoutSignal(timeoutMs: number): AbortSignal {
  const controller = new AbortController()
  const handle = setTimeout(() => controller.abort(), timeoutMs)
  controller.signal.addEventListener('abort', () => clearTimeout(handle), { once: true })
  return controller.signal
}

function resolveChatCompletionsUrl(apiUrl: string): string {
  const trimmed = apiUrl.trim().replace(/\/+$/, '')

  if (trimmed.endsWith('/chat/completions')) {
    return trimmed
  }

  if (trimmed.endsWith('/models')) {
    return `${trimmed.slice(0, -'/models'.length)}/chat/completions`
  }

  return `${trimmed}/chat/completions`
}

export function createOpenAIProvider(config: OpenAIConfig): LLMProvider {
  return {
    name: 'openai',
    model: config.model,
    async generateJSON(request: LLMGenerationRequest): Promise<LLMGenerationResult> {
      const timeoutMs = request.timeoutMs ?? config.timeoutMs
      const maxRetries = request.maxRetries ?? config.maxRetries
      const temperature = request.temperature ?? 0
      const maxTokens = request.maxTokens ?? config.maxTokens
      const systemPrompt = request.systemPrompt
        ?? 'You are a QA automation planner. Return only one valid JSON object. No markdown. No explanation.'
      const startedAt = Date.now()
      let attempt = 0

      while (true) {
        try {
          const response = await fetch(resolveChatCompletionsUrl(config.apiUrl), {
            method: 'POST',
            headers: {
              Authorization: `Bearer ${config.apiKey}`,
              'Content-Type': 'application/json',
            },
            body: JSON.stringify({
              model: config.model,
              messages: [
                { role: 'system', content: systemPrompt },
                { role: 'user', content: request.prompt },
              ],
              temperature,
              max_tokens: maxTokens,
              response_format: { type: 'json_object' },
            }),
            signal: withTimeoutSignal(timeoutMs),
          })

          const bodyText = await response.text()
          const responseBody = (bodyText ? JSON.parse(bodyText) : {}) as OpenAIChatResponse

          if (!response.ok) {
            const error = new Error(
              responseBody.error?.message
                ?? `OpenAI request failed with status ${response.status}`,
            ) as Error & { httpStatus?: number; errorCode?: string }
            error.httpStatus = response.status
            const openAiErrorCode = responseBody.error?.code ?? responseBody.error?.type
            if (typeof openAiErrorCode === 'string' && openAiErrorCode.trim() !== '') {
              error.errorCode = openAiErrorCode
            }
            throw error
          }

          const rawResponse = extractResponseText(responseBody)
          const parsed = await parseJsonObjectOrThrow(rawResponse, config.model)

          return {
            data: parsed,
            rawResponse,
            durationMs: Date.now() - startedAt,
            retries: attempt,
            model: config.model,
            provider: 'openai',
          }
        } catch (error) {
          if (attempt < maxRetries && isRetryableOpenAIError(error)) {
            const delayMs = computeBackoffDelayMs(attempt)
            await sleep(delayMs)
            attempt += 1
            continue
          }

          if (error instanceof LLMProviderError) {
            throw error
          }

          const maybeError = error as {
            httpStatus?: number
            errorCode?: string
          } | undefined

          throw new LLMProviderError(buildOpenAIErrorMessage(config.model, config.apiKey !== '', error), {
            provider: 'openai',
            model: config.model,
            reason: classifyOpenAIReason(error),
            rawResponse: undefined,
            retries: attempt,
            httpStatus: maybeError?.httpStatus,
            errorCode: maybeError?.errorCode,
            apiKeyPresent: config.apiKey !== '',
          })
        }
      }
    },
  }
}
