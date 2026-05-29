import type { OpenAIConfig } from '../config.js'
import { createOpenAIProvider } from './openaiProvider.js'
import type { LLMProvider } from './types.js'

export function createLLMProvider(options: {
  provider: 'openai'
  config: OpenAIConfig
}): LLMProvider {
  process.stderr.write('[llm] Provider locked to openai (gpt-5.3-codex via codex.sale)\n')
  return createOpenAIProvider(options.config)
}
