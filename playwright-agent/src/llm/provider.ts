import type { ClaudeConfig, GeminiConfig, OpenAIConfig } from '../config.js'
import { createClaudeProvider } from './claudeProvider.js'
import { createGeminiProvider } from './geminiProvider.js'
import { createOpenAIProvider } from './openaiProvider.js'
import type { LLMProvider } from './types.js'

export function createLLMProvider(options: {
  provider: 'claude' | 'gemini' | 'openai'
  config: ClaudeConfig | GeminiConfig | OpenAIConfig
}): LLMProvider {
  switch (options.provider) {
    case 'claude':
      return createClaudeProvider(options.config as ClaudeConfig)
    case 'gemini':
      return createGeminiProvider(options.config as GeminiConfig)
    case 'openai':
      return createOpenAIProvider(options.config as OpenAIConfig)
    default: {
      const exhaustiveCheck: never = options.provider
      throw new Error(`Unsupported LLM provider: ${String(exhaustiveCheck)}`)
    }
  }
}
