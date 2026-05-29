import 'dotenv/config'
import type { LLMProviderName } from './llm/types.js'

export type GenerationEngine = LLMProviderName | 'heuristic'

export type GeminiConfig = {
  apiKey: string
  model: string
  timeoutMs: number
  maxRetries: number
}

export type ClaudeConfig = {
  apiKey: string
  model: string
  maxTokens: number
  timeoutMs: number
  maxRetries: number
}

export type OpenAIConfig = {
  apiKey: string
  model: string
  apiUrl: string
  maxTokens: number
  timeoutMs: number
  maxRetries: number
}

function readEnv(name: string, fallback: string): string {
  const raw = process.env[name]
  if (raw == null) {
    return fallback
  }

  const value = raw.trim()
  if (!value || value.toLowerCase() === 'undefined' || value.toLowerCase() === 'null') {
    return fallback
  }

  return value
}

function readOptionalEnv(name: string): string | undefined {
  const raw = process.env[name]
  if (raw == null) {
    return undefined
  }

  const value = raw.trim()
  if (!value || value.toLowerCase() === 'undefined' || value.toLowerCase() === 'null') {
    return undefined
  }

  return value
}

function readEnvBoolean(name: string, fallback: boolean): boolean {
  const raw = process.env[name]
  if (raw == null) {
    return fallback
  }

  const value = raw.trim().toLowerCase()
  if (!value || value === 'undefined' || value === 'null') {
    return fallback
  }

  if (['1', 'true', 'yes', 'on'].includes(value)) {
    return true
  }

  if (['0', 'false', 'no', 'off'].includes(value)) {
    return false
  }

  return fallback
}

function readEnvInt(name: string, fallback: number): number {
  const raw = process.env[name]
  if (raw == null) {
    return fallback
  }

  const parsed = Number(raw)
  if (!Number.isFinite(parsed)) {
    return fallback
  }

  return Math.max(0, Math.floor(parsed))
}

function clampInt(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value))
}

export function getLLMProvider(): LLMProviderName {
  const raw = readOptionalEnv('LLM_PROVIDER')
  if (raw && raw !== 'openai' && raw !== 'codex') {
    process.stderr.write(`[llm] LLM_PROVIDER="${raw}" ignored — provider is locked to openai\n`)
  }
  return 'openai'
}

export function getGenerationEngine(): GenerationEngine {
  const llmProviderRaw = readOptionalEnv('LLM_PROVIDER')
  if (llmProviderRaw && llmProviderRaw !== 'openai' && llmProviderRaw !== 'codex') {
    process.stderr.write(`[llm] LLM_PROVIDER="${llmProviderRaw}" ignored — provider is locked to openai\n`)
  }

  const engineRaw = readOptionalEnv('GENERATION_ENGINE')
  if (engineRaw && engineRaw !== 'openai' && engineRaw !== 'codex') {
    process.stderr.write(`[llm] GENERATION_ENGINE="${engineRaw}" ignored — provider is locked to openai\n`)
  }

  return 'openai'
}

export function getClaudeConfig(): ClaudeConfig {
  const apiKey = readOptionalEnv('CLAUDE_API_KEY')
  if (!apiKey) {
    throw new Error('CLAUDE_API_KEY is required when LLM_PROVIDER=claude')
  }

  return {
    apiKey,
    model: readEnv('CLAUDE_MODEL', 'claude-3-5-sonnet-latest'),
    maxTokens: clampInt(readEnvInt('CLAUDE_MAX_TOKENS', 4000), 256, 8192),
    timeoutMs: clampInt(readEnvInt('CLAUDE_TIMEOUT_MS', 30000), 5000, 60000),
    maxRetries: clampInt(readEnvInt('CLAUDE_MAX_RETRIES', 2), 0, 4),
  }
}

export function getGeminiConfig(): GeminiConfig {
  const apiKey = readOptionalEnv('GEMINI_API_KEY')
  if (!apiKey) {
    throw new Error('GEMINI_API_KEY is required when LLM_PROVIDER=gemini')
  }

  return {
    apiKey,
    model: readEnv('GEMINI_MODEL', 'gemini-2.0-flash'),
    timeoutMs: clampInt(readEnvInt('GEMINI_TIMEOUT_MS', 30000), 5000, 60000),
    maxRetries: clampInt(readEnvInt('GEMINI_MAX_RETRIES', 2), 0, 4),
  }
}

export function getOpenAIConfig(): OpenAIConfig {
  const apiKey = readOptionalEnv('AGENT_CODEX_API_KEY')
    ?? readOptionalEnv('CODEX_API_KEY')
    ?? readOptionalEnv('AGENT_OPENAI_API_KEY')
    ?? readOptionalEnv('OPENAI_API_KEY')
  if (!apiKey) {
    throw new Error('AGENT_CODEX_API_KEY, CODEX_API_KEY, AGENT_OPENAI_API_KEY, or OPENAI_API_KEY is required when LLM_PROVIDER=openai')
  }

  return {
    apiKey,
    model: readOptionalEnv('AGENT_OPENAI_MODEL')
      ?? readOptionalEnv('CODEX_MODEL')
      ?? readEnv('OPENAI_MODEL', 'gpt-5.3-codex'),
    apiUrl: readOptionalEnv('AGENT_OPENAI_API_URL')
      ?? readOptionalEnv('OPENAI_BASE_URL')
      ?? readEnv('OPENAI_API_URL', 'https://codex.sale/v1'),
    maxTokens: clampInt(readEnvInt('AGENT_OPENAI_MAX_TOKENS', readEnvInt('OPENAI_MAX_TOKENS', 4000)), 256, 8192),
    timeoutMs: clampInt(readEnvInt('AGENT_OPENAI_TIMEOUT_MS', readEnvInt('OPENAI_TIMEOUT_MS', 30000)), 5000, 60000),
    maxRetries: clampInt(readEnvInt('AGENT_OPENAI_MAX_RETRIES', readEnvInt('OPENAI_MAX_RETRIES', 2)), 0, 4),
  }
}

export const config = {
  generationEngine: getGenerationEngine(),
  runHeadless: readEnvBoolean('RUN_HEADLESS', false),
  runSlowMoMs: readEnvInt('RUN_SLOW_MO_MS', 300),
  runHoldOpenMs: readEnvInt('RUN_HOLD_OPEN_MS', 12000),
  orchestratorDir: readEnv('ORCHESTRATOR_DIR', '../playwright-orchestrator'),
  targetBaseUrl: readEnv('TARGET_BASE_URL', 'http://host.docker.internal:5173'),
} as const
