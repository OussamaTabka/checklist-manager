import fs from 'node:fs/promises'
import path from 'node:path'
import { z } from 'zod'
import type { ZodIssue } from 'zod'
import { getOpenAIConfig } from '../config.js'
import { createLLMProvider } from '../llm/provider.js'
import { LLMProviderError } from '../llm/types.js'
import type { LLMProvider } from '../llm/types.js'
import { buildScriptPrompt } from '../prompts/scriptPrompt.js'
import type { GeneratorChecklistItem } from '../prompts/scriptPrompt.js'

// ── Input schema — mirrors generateRunSpec.ts (same Laravel contract) ──────────

const InputSchema = z.object({
  run_id: z.string().min(1),
  external_id: z.number().int().positive(),
  test_case_title: z.string().min(1),
  test_case_description: z.string().optional().default(''),
  test_case_text: z.string().min(1),
  base_url: z.string().url(),
  use_auth: z.boolean().optional().default(true),
  environment_name: z.string().optional().default(''),
  notes: z.string().optional().default(''),
  priority: z.string().optional().default(''),
  criticality: z.string().optional().default(''),
  current_status: z.string().optional().default(''),
  project_version_id: z.number().int().positive().optional(),
  target_type: z.string().optional().default('version_item'),
  source_app: z.string().optional().default(''),
  user_story: z.record(z.string(), z.any()).nullable().optional(),
  checklist_business_rules: z.array(z.string()).optional().default([]),
  project: z.record(z.string(), z.any()).nullable().optional(),
  provided_inputs: z.record(z.string(), z.any()).optional().default({}),
  expected_result: z.record(z.string(), z.any()).optional().default({}),
})

type GeneratorInput = z.infer<typeof InputSchema>

// ── LLM response schema — python_script_body validated lightly (free string) ──

const RequiredInputSchema = z.object({
  key: z.string().min(1),
  label: z.string().min(1),
  kind: z.enum(['text', 'email', 'password', 'search', 'file', 'textarea']),
  required: z.boolean(),
  description: z.string().default(''),
})

const GeneratedScriptSchema = z.object({
  execution_profile: z.object({
    intent_summary: z.string().min(1),
    coverage_type: z.string().min(1),
    preconditions: z.array(z.string()).default([]),
    required_inputs: z.array(RequiredInputSchema).default([]),
    expected_observations: z.array(z.string()).min(1),
  }),
  python_script_body: z
    .string()
    .min(1)
    .refine(
      (body) => !/^\s*(?:import|from)\s+\S+/m.test(body),
      { message: 'python_script_body must not contain import statements — use only the injected page, expect, inputs variables' },
    )
    .refine(
      (body) => !/(?:sync_playwright|async_playwright)\s*\(/.test(body),
      { message: 'python_script_body must not start its own Playwright session; use the provided page object' },
    )
    .refine(
      (body) => !/\.launch\s*\(/.test(body),
      { message: 'python_script_body must not launch a browser; the wrapper owns the browser lifecycle' },
    ),
  human_readable_steps: z.array(z.string().min(1)).min(1),
})

type GeneratedScript = z.infer<typeof GeneratedScriptSchema>

// ── Error class ────────────────────────────────────────────────────────────────

export class ScriptGenerationFailedError extends Error {
  public readonly issues: readonly ZodIssue[]
  public readonly rawResponse: string
  public readonly reason: string
  public readonly model: string
  public readonly provider: string

  public constructor(message: string, options: {
    issues: readonly ZodIssue[]
    rawResponse: string
    reason: string
    model: string
    provider: string
  }) {
    super(message)
    this.name = 'ScriptGenerationFailedError'
    this.issues = options.issues
    this.rawResponse = options.rawResponse
    this.reason = options.reason
    this.model = options.model
    this.provider = options.provider
  }
}

// ── Correction prompt ──────────────────────────────────────────────────────────

function formatIssuePath(p: readonly PropertyKey[]): string {
  if (p.length === 0) return '<root>'
  return p
    .map((segment, i) => {
      if (typeof segment === 'number') return `[${segment}]`
      if (typeof segment === 'symbol') return i === 0 ? segment.toString() : `.${segment.toString()}`
      return i === 0 ? String(segment) : `.${String(segment)}`
    })
    .join('')
}

function buildCorrectionPrompt(
  item: GeneratorChecklistItem,
  lastRaw: string,
  zodIssues: readonly ZodIssue[],
): string {
  const issueLines = zodIssues.map((issue, i) => {
    const p = formatIssuePath(issue.path)
    return `${i + 1}. path=${p} message=${issue.message}`
  })

  return [
    'Correct the following JSON so it fully satisfies the Python-script generation contract.',
    `Checklist title: ${item.test_case_title}`,
    '',
    'Original invalid JSON:',
    '```json',
    lastRaw || '(empty)',
    '```',
    '',
    'Validation errors:',
    ...issueLines,
    '',
    'Requirements:',
    '  - Root object keys must be exactly: execution_profile, python_script_body, human_readable_steps.',
    '  - python_script_body must not import modules, must not launch a browser,',
    '    must only use: page, expect, inputs (async Playwright Python API, await keywords are fine).',
    '  - human_readable_steps must be a non-empty array of short French sentences.',
    '',
    'Return ONLY the corrected JSON. No markdown. No explanation.',
  ].join('\n')
}

// ── Generation with retry/correction loop ─────────────────────────────────────

async function generateWithRetry(
  provider: LLMProvider,
  item: GeneratorChecklistItem,
): Promise<{ generated: GeneratedScript; model: string; providerRetries: number; correctionRetries: number }> {
  const initialPrompt = buildScriptPrompt(item)
  const rawResponses: string[] = []
  let providerRetries = 0
  let correctionRetries = 0

  // First attempt
  try {
    const result = await provider.generateJSON({
      prompt: initialPrompt,
      systemPrompt: 'You are a QA automation engineer that writes Playwright Python async scripts. Return only one valid JSON object. No markdown. No explanation.',
      temperature: 0.1,
    })
    rawResponses.push(result.rawResponse)
    providerRetries += result.retries

    const validated = GeneratedScriptSchema.parse(result.data)
    process.stderr.write(`[python-gen] attempt 1 succeeded model=${result.model} retries=${result.retries}\n`)
    return { generated: validated, model: result.model, providerRetries, correctionRetries }
  } catch (error) {
    if (error instanceof LLMProviderError) {
      process.stderr.write(`[python-gen] LLM provider error on attempt 1: ${error.message}\n`)
      throw error
    }
    if (!(error instanceof z.ZodError)) throw error

    let lastIssues = error.issues
    let lastRaw = rawResponses[rawResponses.length - 1] ?? ''

    // Up to 2 correction retries
    while (correctionRetries < 2) {
      correctionRetries += 1
      const correctionPrompt = buildCorrectionPrompt(item, lastRaw, lastIssues)

      process.stderr.write(
        `[python-gen] invalid response (correction ${correctionRetries}/2): ` +
        `${lastIssues[0]?.message ?? 'validation failed'}\n`,
      )

      try {
        const corrected = await provider.generateJSON({
          prompt: correctionPrompt,
          systemPrompt: 'You are a QA automation engineer. Return only a corrected JSON object. No markdown.',
          temperature: 0.1,
        })
        rawResponses.push(corrected.rawResponse)
        providerRetries += corrected.retries

        const validated = GeneratedScriptSchema.parse(corrected.data)
        process.stderr.write(`[python-gen] correction ${correctionRetries} succeeded model=${corrected.model}\n`)
        return { generated: validated, model: corrected.model, providerRetries, correctionRetries }
      } catch (correctionError) {
        if (correctionError instanceof LLMProviderError) throw correctionError
        if (!(correctionError instanceof z.ZodError)) throw correctionError

        lastIssues = correctionError.issues
        lastRaw = rawResponses[rawResponses.length - 1] ?? lastRaw
      }
    }

    process.stderr.write(`[python-gen] generateScript failed after 2 correction retries\n`)
    throw new ScriptGenerationFailedError(
      `${provider.name} failed to produce a valid Python script after 2 correction retries`,
      {
        issues: lastIssues,
        rawResponse: lastRaw,
        reason: 'schema_validation_failed',
        model: provider.model,
        provider: provider.name,
      },
    )
  }
}

// ── Output builder ─────────────────────────────────────────────────────────────

/**
 * Maps human_readable_steps strings to step objects the Vue modal can render.
 *
 * ChecklistDetailView.vue:699 — summarizePlanStep() default branch:
 *   `return step.action || 'Action'`
 *
 * Any string that is not a DSL action keyword (goto, click, fill, …) falls
 * through to that default and is printed verbatim.  Setting action = sentence
 * makes "Actions prévues" show the French human-readable sentences instead of
 * empty DSL steps.
 */
function humanStepsToPlanSteps(steps: readonly string[]): Array<{ action: string }> {
  return steps.map((sentence) => ({ action: sentence }))
}

function mapCriticalityToSeverity(criticality: string): 'critical' | 'major' | 'minor' {
  switch (criticality.trim().toLowerCase()) {
    case 'critical': return 'critical'
    case 'high':
    case 'medium': return 'major'
    case 'low': return 'minor'
    default: return 'critical'
  }
}

function buildRunSpecOutput(input: GeneratorInput, generated: GeneratedScript): Record<string, unknown> {
  const planSteps = humanStepsToPlanSteps(generated.human_readable_steps)

  return {
    schema_version: '1.0',
    run_id: input.run_id,
    generation_mode: 'python_script',
    target: {
      base_url: input.base_url,
    },
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
      engine: 'python-gen',
      requested_engine: 'openai',
    },
    cases: [
      {
        external_id: input.external_id,
        title: input.test_case_title,
        severity: mapCriticalityToSeverity(input.criticality ?? ''),
        use_auth: false,
        // execution_profile consumed by extractExecutionProfile() in PHP →
        // stored on checklist_items.execution_profile → shown in the Vue modal.
        execution_profile: {
          ...generated.execution_profile,
          diagnostics: [],
        },
        // Execution payload read by wrapper.py
        python_script_body: generated.python_script_body,
        human_readable_steps: generated.human_readable_steps,
        // provided_inputs embedded for the manual CLI path so wrapper.py can build
        // the inputs dict without going through Laravel's mergeProvidedInputsIntoExecutionProfile.
        // Laravel ignores this field; it uses required_inputs[].value instead.
        provided_inputs: input.provided_inputs,
        preflight_checks: [],
        // steps: human_readable steps as { action } objects so that
        // extractExecutionProfile() builds last_generated_plan.steps and the
        // Vue modal renders them under "Actions prévues".
        steps: planSteps,
        asserts: [],
      },
    ],
  }
}

// ── Entry point (CLI) ──────────────────────────────────────────────────────────

function usageAndExit(): never {
  process.stderr.write('Usage: node dist/python-gen/generateScript.js --input <path-to-input.json>\n')
  process.exit(2)
}

async function main(): Promise<void> {
  const inputFlagIndex = process.argv.findIndex((v) => v === '--input')
  if (inputFlagIndex === -1 || !process.argv[inputFlagIndex + 1]) {
    usageAndExit()
  }

  const inputPath = path.resolve(process.argv[inputFlagIndex + 1] as string)
  const raw = (await fs.readFile(inputPath, 'utf8')).replace(/^﻿/, '') // strip BOM
  const parsedInput = InputSchema.parse(JSON.parse(raw))

  process.stderr.write(
    `[python-gen] generating Python script external_id=${parsedInput.external_id} ` +
    `title="${parsedInput.test_case_title}"\n`,
  )

  const providerConfig = getOpenAIConfig()
  process.stderr.write(
    `[python-gen] provider=openai model=${providerConfig.model} ` +
    `api_key_present=${providerConfig.apiKey !== '' ? 'yes' : 'no'}\n`,
  )

  const provider = createLLMProvider({ provider: 'openai', config: providerConfig })

  const checklistItem: GeneratorChecklistItem = {
    external_id: parsedInput.external_id,
    test_case_title: parsedInput.test_case_title,
    test_case_description: parsedInput.test_case_description,
    test_case_text: parsedInput.test_case_text,
    base_url: parsedInput.base_url,
    use_auth: parsedInput.use_auth,
    environment_name: parsedInput.environment_name,
    notes: parsedInput.notes,
    priority: parsedInput.priority,
    criticality: parsedInput.criticality,
    current_status: parsedInput.current_status,
    target_type: parsedInput.target_type,
    source_app: parsedInput.source_app,
    provided_inputs: parsedInput.provided_inputs,
    expected_result: parsedInput.expected_result,
  }

  const { generated, model, providerRetries, correctionRetries } =
    await generateWithRetry(provider, checklistItem)

  process.stderr.write(
    `[python-gen] script ready model=${model} providerRetries=${providerRetries} ` +
    `correctionRetries=${correctionRetries} body_chars=${generated.python_script_body.length} ` +
    `steps=${generated.human_readable_steps.length} ` +
    `required_inputs=${generated.execution_profile.required_inputs.length}\n`,
  )

  const runSpec = buildRunSpecOutput(parsedInput, generated)
  process.stdout.write(`${JSON.stringify(runSpec)}\n`)
}

main().catch((error: unknown) => {
  const message = error instanceof Error ? error.message : String(error)
  process.stderr.write(`[python-gen] generateScript failed: ${message}\n`)
  process.exit(3)
})
