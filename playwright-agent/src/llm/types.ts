export type LLMProviderName = 'claude' | 'gemini' | 'openai'

export type LLMFailureReason =
  | 'invalid_json'
  | 'empty_response'
  | 'api_error'
  | 'timeout'
  | 'rate_limit'
  | 'non_object_response'
  | 'raw_playwright_code'

export type LLMGenerationRequest = {
  prompt: string
  systemPrompt?: string
  temperature?: number
  maxTokens?: number
  timeoutMs?: number
  maxRetries?: number
}

export type LLMGenerationResult = {
  data: unknown
  rawResponse: string
  durationMs: number
  retries: number
  model: string
  provider: LLMProviderName
}

export interface LLMProvider {
  readonly name: LLMProviderName
  readonly model: string
  generateJSON(request: LLMGenerationRequest): Promise<LLMGenerationResult>
}

export class LLMProviderError extends Error {
  public readonly provider: LLMProviderName
  public readonly model: string
  public readonly reason: LLMFailureReason
  public readonly rawResponse: string | undefined
  public readonly debugFilePath: string | undefined
  public readonly retries: number | undefined
  public readonly httpStatus: number | undefined
  public readonly errorCode: string | undefined
  public readonly apiKeyPresent: boolean | undefined

  public constructor(message: string, options: {
    provider: LLMProviderName
    model: string
    reason: LLMFailureReason
    rawResponse?: string | undefined
    debugFilePath?: string | undefined
    retries?: number | undefined
    httpStatus?: number | undefined
    errorCode?: string | undefined
    apiKeyPresent?: boolean | undefined
  }) {
    super(message)
    this.name = 'LLMProviderError'
    this.provider = options.provider
    this.model = options.model
    this.reason = options.reason
    this.rawResponse = options.rawResponse
    this.debugFilePath = options.debugFilePath
    this.retries = options.retries
    this.httpStatus = options.httpStatus
    this.errorCode = options.errorCode
    this.apiKeyPresent = options.apiKeyPresent
  }
}
