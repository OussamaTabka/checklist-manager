import fs from 'node:fs/promises'
import path from 'node:path'
import { z } from 'zod'
import { getOpenAIConfig } from '../config.js'
import { createLLMProvider } from '../llm/provider.js'
import { LLMProviderError } from '../llm/types.js'
import { buildHealerPrompt, HEALER_SYSTEM_PROMPT } from '../prompts/healerPrompt.js'
import type { HealPayload } from '../prompts/healerPrompt.js'

// ── Input schema ───────────────────────────────────────────────────────────────

const HealPayloadSchema = z.object({
  original_script: z.string().min(1),
  test_case: z.object({
    title: z.string().min(1),
    description: z.string(),
    priority: z.string().optional(),
    criticality: z.string().optional(),
  }),
  user_story: z.object({
    reference: z.string(),
    title: z.string(),
    as_a: z.string(),
    i_want_that: z.string(),
    so_that: z.string(),
    business_rules: z.string(),
  }),
  failure: z.object({
    error_type: z.enum(['selector_not_found', 'navigation_timeout', 'assertion_failed']),
    error_message: z.string().min(1),
    last_action_attempted: z.string().optional(),
  }),
  page_dom_snippet: z.string().optional(),
  screenshot_path: z.string().optional(),
  run_context: z.object({
    run_id: z.string().min(1),
    external_id: z.number().int().positive(),
    base_url: z.string().url(),
    provided_inputs: z.record(z.string(), z.unknown()).default({}),
    execution_profile: z.record(z.string(), z.unknown()).default({}),
    human_readable_steps: z.array(z.string()).default([]),
  }),
})

// ── LLM response schema ────────────────────────────────────────────────────────

const HealerResponseSchema = z.object({
  diagnosis: z.string().min(1),
  python_script_body: z
    .string()
    .min(1)
    .refine(
      (body) => !/^\s*(?:import|from)\s+\S+/m.test(body),
      { message: 'python_script_body must not contain import statements' },
    )
    .refine(
      (body) => !/(?:sync_playwright|async_playwright)\s*\(/.test(body),
      { message: 'python_script_body must not start its own Playwright session' },
    )
    .refine(
      (body) => !/\.launch\s*\(/.test(body),
      { message: 'python_script_body must not launch a browser' },
    ),
})

// ── Run-spec builder ───────────────────────────────────────────────────────────

function buildHealedRunSpec(
  payload: HealPayload,
  diagnosis: string,
  pythonScriptBody: string,
): Record<string, unknown> {
  const { run_context } = payload
  const planSteps = run_context.human_readable_steps.map((s) => ({ action: s }))

  return {
    schema_version: '1.0',
    run_id: run_context.run_id,
    generation_mode: 'python_script',
    target: { base_url: run_context.base_url },
    runtime: {
      headless: true,
      slow_mo_ms: 0,
      hold_open_ms: 0,
      timeout_ms: 30000,
      viewport: { width: 1280, height: 720 },
      trace: 'off',
      video: 'off',
      screenshot: 'off',
    },
    generation_metadata: {
      engine: 'python-gen-healed',
      requested_engine: 'openai',
      heal_diagnosis: diagnosis,
    },
    cases: [
      {
        external_id: run_context.external_id,
        title: payload.test_case.title,
        severity: 'critical',
        use_auth: false,
        execution_profile: {
          ...run_context.execution_profile,
          diagnostics: [],
        },
        python_script_body: pythonScriptBody,
        human_readable_steps: run_context.human_readable_steps,
        provided_inputs: run_context.provided_inputs,
        preflight_checks: [],
        steps: planSteps,
        asserts: [],
      },
    ],
  }
}

// ── Entry point (CLI) ──────────────────────────────────────────────────────────

function usageAndExit(): never {
  process.stderr.write('Usage: node dist/python-gen/healScript.js --input <path-to-heal-input.json>\n')
  process.exit(2)
}

async function main(): Promise<void> {
  const inputFlagIndex = process.argv.findIndex((v) => v === '--input')
  if (inputFlagIndex === -1 || !process.argv[inputFlagIndex + 1]) {
    usageAndExit()
  }

  const inputPath = path.resolve(process.argv[inputFlagIndex + 1] as string)
  const raw = (await fs.readFile(inputPath, 'utf8')).replace(/^﻿/, '') // strip BOM
  const payload = HealPayloadSchema.parse(JSON.parse(raw)) as HealPayload

  process.stderr.write(
    `[healer] healing run_id=${payload.run_context.run_id} ` +
    `external_id=${payload.run_context.external_id} ` +
    `error_type=${payload.failure.error_type}\n`,
  )

  const providerConfig = getOpenAIConfig()
  process.stderr.write(
    `[healer] provider=openai model=${providerConfig.model} ` +
    `api_key_present=${providerConfig.apiKey !== '' ? 'yes' : 'no'}\n`,
  )

  const provider = createLLMProvider({ provider: 'openai', config: providerConfig })

  let llmResult
  try {
    llmResult = await provider.generateJSON({
      prompt: buildHealerPrompt(payload),
      systemPrompt: HEALER_SYSTEM_PROMPT,
      temperature: 0.1,
    })
  } catch (error) {
    if (error instanceof LLMProviderError) {
      process.stderr.write(`[healer] LLM provider error: ${error.message}\n`)
    } else {
      const message = error instanceof Error ? error.message : String(error)
      process.stderr.write(`[healer] LLM call failed: ${message}\n`)
    }
    process.exit(2)
  }

  let validated
  try {
    validated = HealerResponseSchema.parse(llmResult.data)
  } catch (error) {
    if (error instanceof z.ZodError) {
      const firstIssue = error.issues[0]
      process.stderr.write(
        `[healer] invalid LLM response shape: ${firstIssue?.message ?? 'validation failed'}\n`,
      )
    } else {
      process.stderr.write(
        `[healer] response validation error: ${error instanceof Error ? error.message : String(error)}\n`,
      )
    }
    process.exit(3)
  }

  process.stderr.write(
    `[healer] success model=${llmResult.model} retries=${llmResult.retries} ` +
    `diagnosis="${validated.diagnosis}"\n`,
  )

  const runSpec = buildHealedRunSpec(payload, validated.diagnosis, validated.python_script_body)
  process.stdout.write(`${JSON.stringify(runSpec)}\n`)
}

main().catch((error: unknown) => {
  const message = error instanceof Error ? error.message : String(error)
  process.stderr.write(`[healer] healScript failed: ${message}\n`)
  process.exit(3)
})
