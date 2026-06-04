import { z } from 'zod'

export const RuntimeTraceModeSchema = z.enum(['retain-on-failure', 'off'])
export const RuntimeVideoModeSchema = z.enum(['retain-on-failure', 'off'])
export const RuntimeScreenshotModeSchema = z.enum(['only-on-failure', 'off'])
export const RunBrowserTargetSchema = z.enum(['chromium', 'firefox', 'webkit', 'msedge'])

export const SelectorByTestIdSchema = z
  .object({
    by: z.literal('testid'),
    id: z.string().min(1),
  })
  .strict()

export const SelectorByRoleSchema = z
  .object({
    by: z.literal('role'),
    role: z.string().min(1),
    name: z.string().min(1),
    exact: z.boolean().optional(),
  })
  .strict()

export const SelectorByLabelSchema = z
  .object({
    by: z.literal('label'),
    text: z.string().min(1),
  })
  .strict()

export const SelectorByTextSchema = z
  .object({
    by: z.literal('text'),
    text: z.string().min(1),
    exact: z.boolean().optional(),
  })
  .strict()

export const SelectorByCssSchema = z
  .object({
    by: z.literal('css'),
    value: z.string().min(1),
  })
  .strict()

export const SelectorDslSchema = z.discriminatedUnion('by', [
  SelectorByTestIdSchema,
  SelectorByRoleSchema,
  SelectorByLabelSchema,
  SelectorByTextSchema,
  SelectorByCssSchema,
])

export const StepGotoSchema = z
  .object({
    action: z.literal('goto'),
    url: z
      .string()
      .min(1)
      .regex(/^(https?:\/\/|\/)/, 'goto url must be absolute (http/https) or root-relative (/...)'),
  })
  .strict()

export const StepClickSchema = z
  .object({
    action: z.literal('click'),
    selector: SelectorDslSchema,
  })
  .strict()

export const StepFillSchema = z
  .object({
    action: z.literal('fill'),
    selector: SelectorDslSchema,
    value: z.string().min(1).optional(),
    input_key: z.string().min(1).optional(),
  })
  .strict()
  .superRefine((value, ctx) => {
    if (value.value === undefined && value.input_key === undefined) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        message: "fill requires at least one of 'value' or 'input_key'",
      })
    }
  })

export const StepPressSchema = z
  .object({
    action: z.literal('press'),
    selector: SelectorDslSchema,
    key: z.string().min(1),
  })
  .strict()

export const StepWaitForUrlSchema = z
  .object({
    action: z.literal('wait_for_url'),
    contains: z.string().min(1),
    timeout_ms: z.number().int().positive().optional(),
  })
  .strict()

export const StepWaitForSelectorSchema = z
  .object({
    action: z.literal('wait_for_selector'),
    selector: SelectorDslSchema,
    state: z.enum(['visible', 'hidden', 'attached', 'detached']),
    timeout_ms: z.number().int().positive().optional(),
  })
  .strict()

export const StepScreenshotSchema = z
  .object({
    action: z.literal('screenshot'),
    name: z.string().min(1),
  })
  .strict()

export const StepSetFileSchema = z
  .object({
    action: z.literal('set_file'),
    selector: SelectorDslSchema,
    file_path: z.string().min(1).optional(),
    input_key: z.string().min(1).optional(),
  })
  .strict()
  .superRefine((value, ctx) => {
    if (value.file_path === undefined && value.input_key === undefined) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        message: "set_file requires at least one of 'file_path' or 'input_key'",
      })
    }
  })

export const StepDslSchema = z.discriminatedUnion('action', [
  StepGotoSchema,
  StepClickSchema,
  StepFillSchema,
  StepPressSchema,
  StepWaitForUrlSchema,
  StepWaitForSelectorSchema,
  StepScreenshotSchema,
  StepSetFileSchema,
])

export const AssertExpectVisibleSchema = z
  .object({
    type: z.literal('expect_visible'),
    selector: SelectorDslSchema,
  })
  .strict()

export const AssertExpectHiddenSchema = z
  .object({
    type: z.literal('expect_hidden'),
    selector: SelectorDslSchema,
  })
  .strict()

export const AssertExpectTextSchema = z
  .object({
    type: z.literal('expect_text'),
    selector: SelectorDslSchema,
    text: z.string().min(1),
  })
  .strict()

export const AssertExpectTextContainsSchema = z
  .object({
    type: z.literal('expect_text_contains'),
    selector: SelectorDslSchema,
    text: z.string().min(1),
  })
  .strict()

export const AssertExpectUrlContainsSchema = z
  .object({
    type: z.literal('expect_url_contains'),
    value: z.string().min(1),
  })
  .strict()

export const AssertExpectTitleSchema = z
  .object({
    type: z.literal('expect_title'),
    value: z.string().min(1),
  })
  .strict()

export const AssertDslSchema = z.discriminatedUnion('type', [
  AssertExpectVisibleSchema,
  AssertExpectHiddenSchema,
  AssertExpectTextSchema,
  AssertExpectTextContainsSchema,
  AssertExpectUrlContainsSchema,
  AssertExpectTitleSchema,
])

export const RequiredInputDslSchema = z
  .object({
    key: z.string().min(1),
    label: z.string().min(1),
    kind: z.enum(['text', 'email', 'password', 'textarea', 'search', 'file']),
    required: z.boolean(),
    allow_empty: z.boolean().optional(),
    description: z.string().min(1).optional(),
    value: z.string().nullable().optional(),
  })
  .strict()

export const ExecutionDiagnosticDslSchema = z
  .object({
    code: z.string().min(1),
    message: z.string().min(1),
    severity: z.enum(['info', 'warning', 'error']),
  })
  .strict()

export const PreflightCheckDslSchema = z
  .object({
    id: z.string().min(1),
    kind: z.enum([
      'page_accessible',
      'element_visible',
      'element_attached',
      'url_contains',
      'input_available',
      'unsupported',
    ]),
    label: z.string().min(1),
    required: z.boolean(),
    selector: SelectorDslSchema.optional(),
    expected: z.string().min(1).optional(),
    input_key: z.string().min(1).optional(),
    failure_message: z.string().min(1),
  })
  .strict()

export const GeneratedPlanDslSchema = z
  .object({
    title: z.string().min(1).optional(),
    intent_summary: z.string().min(1),
    coverage_type: z.string().min(1),
    preflight_checks: z.array(PreflightCheckDslSchema).optional(),
    steps: z.array(StepDslSchema),
    asserts: z.array(AssertDslSchema),
    expected_observations: z.array(z.string().min(1)),
    diagnostics: z.array(ExecutionDiagnosticDslSchema).optional(),
  })
  .strict()

export const ExecutionProfileDslSchema = z
  .object({
    intent_summary: z.string().min(1),
    coverage_type: z.string().min(1),
    preconditions: z.array(z.string().min(1)).optional(),
    required_inputs: z.array(RequiredInputDslSchema).optional(),
    expected_observations: z.array(z.string().min(1)),
    diagnostics: z.array(ExecutionDiagnosticDslSchema).optional(),
    generation_confidence: z.number().optional(),
    last_generated_plan: GeneratedPlanDslSchema.optional(),
  })
  .strict()

export const RunCaseDslSchema = z
  .object({
    external_id: z.number().int().positive(),
    title: z.string().min(1),
    severity: z.enum(['minor', 'major', 'critical']).optional(),
    use_auth: z.boolean().optional(),
    execution_profile: ExecutionProfileDslSchema.optional(),
    generated_plan: GeneratedPlanDslSchema.optional(),
    preflight_checks: z.array(PreflightCheckDslSchema).optional(),
    steps: z.array(StepDslSchema),
    asserts: z.array(AssertDslSchema),
    viewport: z
      .object({
        width: z.number().int().positive(),
        height: z.number().int().positive(),
      })
      .strict()
      .optional(),
    browser: RunBrowserTargetSchema.optional(),
  })
  .strict()

export const RunRuntimeDslSchema = z
  .object({
    headless: z.boolean(),
    slow_mo_ms: z.number().int().nonnegative().optional(),
    hold_open_ms: z.number().int().nonnegative().optional(),
    browsers: z.array(RunBrowserTargetSchema).min(1).optional(),
    timeout_ms: z.number().int().positive(),
    viewport: z
      .object({
        width: z.number().int().positive(),
        height: z.number().int().positive(),
      })
      .strict(),
    trace: RuntimeTraceModeSchema,
    video: RuntimeVideoModeSchema,
    screenshot: RuntimeScreenshotModeSchema,
  })
  .strict()

export const RunAuthUiDslSchema = z
  .object({
    mode: z.literal('ui'),
  })
  .strict()

export const RunAuthApiDslSchema = z
  .object({
    mode: z.literal('api'),
    csrf_cookie_url: z.string().min(1),
    login_url: z.string().min(1),
    verify_url: z.string().min(1).optional(),
    username_env: z.string().min(1),
    password_env: z.string().min(1),
    username_field: z.string().min(1).optional(),
    password_field: z.string().min(1).optional(),
    body_format: z.enum(['json', 'form']).optional(),
  })
  .strict()

export const RunAuthDslSchema = z.discriminatedUnion('mode', [
  RunAuthUiDslSchema,
  RunAuthApiDslSchema,
])

export const GenerationMetadataDslSchema = z
  .object({
    engine: z.enum(['claude', 'gemini', 'openai', 'heuristic']),
    requested_engine: z.enum(['claude', 'gemini', 'openai', 'heuristic']).optional(),
    model: z.string().min(1).optional(),
    fallback_used: z.boolean(),
    fallback_reason: z.string().min(1).optional(),
  })
  .strict()

export const RunSpecDslV1Schema = z
  .object({
    schema_version: z.literal('1.0'),
    run_id: z.string().min(1),
    target: z
      .object({
        base_url: z.string().url(),
      })
      .strict(),
    runtime: RunRuntimeDslSchema,
    cases: z.array(RunCaseDslSchema).min(1),
    auth: RunAuthDslSchema.optional(),
    generation_metadata: GenerationMetadataDslSchema.optional(),
  })
  .strict()

export type SelectorDsl = z.infer<typeof SelectorDslSchema>
export type StepDsl = z.infer<typeof StepDslSchema>
export type AssertDsl = z.infer<typeof AssertDslSchema>
export type RequiredInputDsl = z.infer<typeof RequiredInputDslSchema>
export type ExecutionDiagnosticDsl = z.infer<typeof ExecutionDiagnosticDslSchema>
export type PreflightCheckDsl = z.infer<typeof PreflightCheckDslSchema>
export type GeneratedPlanDsl = z.infer<typeof GeneratedPlanDslSchema>
export type ExecutionProfileDsl = z.infer<typeof ExecutionProfileDslSchema>
export type RunCaseDsl = z.infer<typeof RunCaseDslSchema>
export type RunRuntimeDsl = z.infer<typeof RunRuntimeDslSchema>
export type RunAuthDsl = z.infer<typeof RunAuthDslSchema>
export type GenerationMetadataDsl = z.infer<typeof GenerationMetadataDslSchema>
export type RunSpecDslV1 = z.infer<typeof RunSpecDslV1Schema>
