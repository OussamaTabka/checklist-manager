import fs from 'node:fs/promises'
import path from 'node:path'
import { z } from 'zod'
import { config } from './config.js'
import { classifyItem, type Scenario } from './classify.js'

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
})

type GeneratorInput = z.infer<typeof InputSchema>

const SelectorSchema = z.discriminatedUnion('by', [
  z.object({ by: z.literal('testid'), id: z.string().min(1) }),
  z.object({ by: z.literal('role'), role: z.string().min(1), name: z.string().min(1), exact: z.boolean().optional() }),
  z.object({ by: z.literal('label'), text: z.string().min(1) }),
  z.object({ by: z.literal('text'), text: z.string().min(1), exact: z.boolean().optional() }),
  z.object({ by: z.literal('css'), value: z.string().min(1) }),
])

const RequiredInputSchema = z.object({
  key: z.string().min(1),
  label: z.string().min(1),
  kind: z.enum(['text', 'email', 'password', 'textarea', 'search', 'file']),
  required: z.boolean(),
  description: z.string().optional(),
  value: z.string().nullable().optional(),
})

const DiagnosticSchema = z.object({
  code: z.string().min(1),
  message: z.string().min(1),
  severity: z.enum(['info', 'warning', 'error']),
})

const PreflightCheckSchema = z.object({
  id: z.string().min(1),
  kind: z.enum(['page_accessible', 'element_visible', 'element_attached', 'url_contains', 'input_available', 'unsupported']),
  label: z.string().min(1),
  required: z.boolean(),
  selector: SelectorSchema.optional(),
  expected: z.string().optional(),
  input_key: z.string().optional(),
  failure_message: z.string().min(1),
})

const StepSchema = z.discriminatedUnion('action', [
  z.object({ action: z.literal('goto'), url: z.string().min(1) }),
  z.object({ action: z.literal('click'), selector: SelectorSchema }),
  z.object({ action: z.literal('fill'), selector: SelectorSchema, value: z.string().optional(), input_key: z.string().optional() }),
  z.object({ action: z.literal('set_file'), selector: SelectorSchema, file_path: z.string().optional(), input_key: z.string().optional() }),
  z.object({ action: z.literal('press'), selector: SelectorSchema, key: z.string().min(1) }),
  z.object({ action: z.literal('wait_for_url'), contains: z.string().min(1), timeout_ms: z.number().int().positive().optional() }),
  z.object({ action: z.literal('wait_for_selector'), selector: SelectorSchema, state: z.enum(['visible', 'hidden', 'attached', 'detached']), timeout_ms: z.number().int().positive().optional() }),
  z.object({ action: z.literal('screenshot'), name: z.string().min(1) }),
])

const AssertSchema = z.discriminatedUnion('type', [
  z.object({ type: z.literal('expect_visible'), selector: SelectorSchema }),
  z.object({ type: z.literal('expect_hidden'), selector: SelectorSchema }),
  z.object({ type: z.literal('expect_text'), selector: SelectorSchema, text: z.string() }),
  z.object({ type: z.literal('expect_url_contains'), value: z.string().min(1) }),
  z.object({ type: z.literal('expect_title'), value: z.string().min(1) }),
])

const GeneratedPlanSchema = z.object({
  title: z.string().optional(),
  intent_summary: z.string().min(1),
  coverage_type: z.string().min(1),
  preflight_checks: z.array(PreflightCheckSchema),
  steps: z.array(StepSchema),
  asserts: z.array(AssertSchema),
  expected_observations: z.array(z.string()),
  diagnostics: z.array(DiagnosticSchema),
})

const ExecutionProfileSchema = z.object({
  intent_summary: z.string().min(1),
  coverage_type: z.string().min(1),
  preconditions: z.array(z.string()),
  required_inputs: z.array(RequiredInputSchema),
  expected_observations: z.array(z.string()),
  diagnostics: z.array(DiagnosticSchema),
  generation_confidence: z.number().optional(),
  last_generated_plan: GeneratedPlanSchema.optional(),
})

const RunSpecSchema = z.object({
  schema_version: z.literal('1.0'),
  run_id: z.string().min(1),
  target: z.object({
    base_url: z.string().url(),
  }),
  runtime: z.object({
    headless: z.boolean(),
    slow_mo_ms: z.number().int().nonnegative().optional(),
    hold_open_ms: z.number().int().nonnegative().optional(),
    browsers: z.array(z.enum(['chromium', 'firefox', 'webkit', 'msedge'])).min(1).optional(),
    timeout_ms: z.number().int().positive(),
    viewport: z.object({
      width: z.number().int().positive(),
      height: z.number().int().positive(),
    }),
    trace: z.enum(['retain-on-failure', 'off']),
    video: z.enum(['retain-on-failure', 'off']),
    screenshot: z.enum(['only-on-failure', 'off']),
  }),
  cases: z.array(
    z.object({
      external_id: z.number().int().positive(),
      title: z.string().min(1),
      severity: z.enum(['critical', 'major', 'minor']),
      use_auth: z.boolean(),
      execution_profile: ExecutionProfileSchema,
      generated_plan: GeneratedPlanSchema,
      preflight_checks: z.array(PreflightCheckSchema),
      steps: z.array(StepSchema).min(1),
      asserts: z.array(AssertSchema),
    }),
  ).min(1),
})

type CoverageType =
  | 'auth_login'
  | 'form_interaction'
  | 'validation'
  | 'navigation'
  | 'search_filter'
  | 'table_listing'
  | 'upload'
  | 'modal_dialog'
  | 'redirection'
  | 'feedback_message'
  | 'button_action'
  | 'generic_ui'
  | 'unsupported_non_ui'

type RequiredInput = z.infer<typeof RequiredInputSchema>
type Diagnostic = z.infer<typeof DiagnosticSchema>
type PreflightCheck = z.infer<typeof PreflightCheckSchema>
type Selector = z.infer<typeof SelectorSchema>
type Step = z.infer<typeof StepSchema>
type Assert = z.infer<typeof AssertSchema>

function normalizeBaseUrl(url: string): string {
  const parsed = new URL(url)
  parsed.pathname = parsed.pathname.replace(/\/$/, '')
  return parsed.toString().replace(/\/$/, '')
}

function isInternalChecklistTarget(baseUrl: string): boolean {
  const { hostname } = new URL(baseUrl)
  const normalizedHost = hostname.toLowerCase()
  return normalizedHost === 'localhost' || normalizedHost === '127.0.0.1' || normalizedHost === 'host.docker.internal' || normalizedHost.endsWith('.local')
}

function normalizeText(input: GeneratorInput): string {
  return [
    input.test_case_title,
    input.test_case_text,
    input.test_case_description,
    input.notes,
  ].filter(Boolean).join('\n').toLowerCase()
}

function hasAny(text: string, patterns: RegExp[]): boolean {
  return patterns.some((pattern) => pattern.test(text))
}

function inferCoverageType(input: GeneratorInput): CoverageType {
  const text = normalizeText(input)

  const hasUiCue = hasAny(text, [
    /\bpage\b/, /\bbutton\b/, /\bform\b/, /\bmodal\b/, /\bdialog\b/, /\bfield\b/, /\binput\b/, /\btable\b/, /\bsearch\b/, /\bfilter\b/, /\bclick\b/, /\bsubmit\b/,
  ])

  const hasNonUiCue = hasAny(text, [
    /\bapi\b/, /\bendpoint\b/, /\brequest\b/, /\bresponse\b/, /\bstatus code\b/, /\bheaders?\b/, /\bbearer\b/, /\bcors\b/, /\brate limit\b/, /\bperformance\b/, /\bload testing\b/, /\bdatabase\b/, /\bsql\b/,
  ])

  if (hasNonUiCue && !hasUiCue) return 'unsupported_non_ui'
  if (hasAny(text, [/\blogin\b/, /\bsign in\b/, /\bauthentication\b/, /\bcredentials\b/])) return 'auth_login'
  if (hasAny(text, [/\bupload\b/, /\battach file\b/, /\bimport file\b/])) return 'upload'
  if (hasAny(text, [/\bmodal\b/, /\bdialog\b/, /\bpopup\b/])) return 'modal_dialog'
  if (hasAny(text, [/\bsearch\b/, /\bfilter\b/])) return 'search_filter'
  if (hasAny(text, [/\btable\b/, /\blist\b/, /\bgrid\b/])) return 'table_listing'
  if (hasAny(text, [/\bvalidation\b/, /\brequired\b/, /\binvalid\b/, /\berror message\b/, /\bformat\b/])) return 'validation'
  if (hasAny(text, [/\bform\b/, /\bfill\b/, /\bsubmit\b/, /\bfield\b/])) return 'form_interaction'
  if (hasAny(text, [/\bredirect\b/, /\bredirection\b/, /\bnavigate to another page\b/])) return 'redirection'
  if (hasAny(text, [/\bsuccess message\b/, /\btoast\b/, /\bconfirmation\b/, /\berror state\b/])) return 'feedback_message'
  if (hasAny(text, [/\bbutton\b/, /\bclick\b/, /\baction\b/])) return 'button_action'
  if (hasAny(text, [/\bnavigation\b/, /\blink\b/, /\bmenu\b/])) return 'navigation'
  return 'generic_ui'
}

function inferScenarioFromText(input: GeneratorInput): Scenario {
  const text = normalizeText(input)
  const matched = new Set<Scenario>()

  if (/\blogin\b|\bauth/.test(text)) matched.add('login')
  if (/\bdashboard\b|\bstats?\b|\bsummary\b/.test(text)) matched.add('dashboard')
  if (/\bchecklists?\b|\btest case catalog\b/.test(text)) matched.add('checklists')
  if (/\bprojects?\b|\breleases?\b|\bversions?\b/.test(text)) matched.add('projects')
  if (/\busers?\b|\broles?\b|\bpermissions?\b/.test(text)) matched.add('users')

  if (matched.size === 1) return Array.from(matched)[0] as Scenario
  return 'unknown'
}

function buildIntentSummary(input: GeneratorInput, coverageType: CoverageType): string {
  const title = input.test_case_title.trim()
  switch (coverageType) {
    case 'auth_login':
      return `Authenticate the user and validate the login outcome described by '${title}'.`
    case 'form_interaction':
      return `Fill the target form and submit it according to '${title}'.`
    case 'validation':
      return `Trigger validation behavior and confirm the expected validation feedback for '${title}'.`
    case 'search_filter':
      return `Exercise search or filter behavior and verify the resulting content for '${title}'.`
    case 'table_listing':
      return `Verify that the expected table or listing content is reachable and visible for '${title}'.`
    case 'upload':
      return `Upload the required file and verify the upload-related outcome for '${title}'.`
    case 'modal_dialog':
      return `Open the target modal or dialog and verify the expected behavior for '${title}'.`
    case 'navigation':
      return `Navigate through the target UI flow described by '${title}'.`
    case 'button_action':
      return `Execute the primary button or action flow described by '${title}'.`
    case 'redirection':
      return `Trigger the target redirection flow and verify the destination for '${title}'.`
    case 'feedback_message':
      return `Trigger the UI message state and verify the expected feedback for '${title}'.`
    case 'unsupported_non_ui':
      return `The test case '${title}' mainly targets non-UI behavior and should be blocked instead of producing a misleading Playwright script.`
    default:
      return `Interpret the UI-oriented test case '${title}' and produce the safest executable browser plan possible.`
  }
}

function requiredInput(key: string, label: string, kind: RequiredInput['kind'], required: boolean, description: string, value: string | null = null): RequiredInput {
  return { key, label, kind, required, description, value }
}

function dedupeRequiredInputs(inputs: RequiredInput[]): RequiredInput[] {
  const seen = new Map<string, RequiredInput>()
  for (const input of inputs) {
    if (!seen.has(input.key)) {
      seen.set(input.key, input)
    }
  }
  return Array.from(seen.values())
}

function extractRequiredInputs(input: GeneratorInput, coverageType: CoverageType): RequiredInput[] {
  const text = normalizeText(input)
  const inputs: RequiredInput[] = []

  if (coverageType === 'auth_login') {
    inputs.push(requiredInput('email', 'Email or username', 'email', true, 'Credential used to authenticate the user.', 'qa.user@example.com'))
    inputs.push(requiredInput('password', 'Password', 'password', true, 'Password used to authenticate the user.', 'Password123!'))
  }

  if (coverageType === 'search_filter') {
    inputs.push(requiredInput('search_query', 'Search query', 'search', true, 'Value used to exercise search or filtering.', 'sample'))
  }

  if (coverageType === 'upload') {
    inputs.push(requiredInput('upload_file', 'File to upload', 'file', true, 'Absolute path to the file that should be uploaded.'))
  }

  if (coverageType === 'form_interaction' || coverageType === 'validation') {
    if (/\bname\b|\bfull name\b/.test(text)) {
      inputs.push(requiredInput('full_name', 'Full name', 'text', coverageType === 'form_interaction', 'Representative value for the name field.', 'QA Tester'))
    }
    if (/\bemail\b/.test(text)) {
      inputs.push(requiredInput('email', 'Email', 'email', coverageType === 'form_interaction', 'Representative value for the email field.', 'qa.user@example.com'))
    }
    if (/\bpassword\b/.test(text)) {
      inputs.push(requiredInput('password', 'Password', 'password', false, 'Representative password value.', 'Password123!'))
    }
    if (/\bphone\b|\bmobile\b/.test(text)) {
      inputs.push(requiredInput('phone', 'Phone number', 'text', false, 'Representative phone number value.', '+21620000111'))
    }
    if (/\bmessage\b|\bcomment\b|\bdescription\b/.test(text)) {
      inputs.push(requiredInput('message', 'Message', 'textarea', false, 'Representative long-form text value.', 'Automated test submission'))
    }
    if (inputs.length === 0 && coverageType === 'form_interaction') {
      inputs.push(requiredInput('generic_text', 'Form value', 'text', true, 'Representative value used when the form field names are ambiguous.', 'Sample value'))
    }
  }

  return dedupeRequiredInputs(inputs)
}

function extractPreconditions(input: GeneratorInput, coverageType: CoverageType, internalTarget: boolean): string[] {
  const preconditions = [
    `Target base URL must be reachable: ${normalizeBaseUrl(input.base_url)}`,
  ]

  if (coverageType === 'auth_login') {
    preconditions.push('The login page or authentication form must be available on the target website.')
  }

  if (coverageType === 'form_interaction' || coverageType === 'validation') {
    preconditions.push('A relevant form must be present on the target page before the test continues.')
  }

  if (coverageType === 'table_listing') {
    preconditions.push('The target page must render at least one table, grid, or list-like structure.')
  }

  if (internalTarget) {
    preconditions.push('The checklist-manager target routes should be available with the expected test ids or visible UI elements.')
  }

  if (input.use_auth !== false && coverageType !== 'auth_login') {
    preconditions.push('Authenticated session bootstrap must succeed when the case requires an authenticated flow.')
  }

  return preconditions
}

function buildExpectedObservations(input: GeneratorInput, coverageType: CoverageType): string[] {
  const observations: string[] = []
  const text = normalizeText(input)

  if (coverageType === 'auth_login') {
    observations.push(/invalid|wrong|incorrect|negative|unsuccessful/.test(text)
      ? 'An authentication error message should be visible and the user should remain on an unauthenticated page.'
      : 'A successful post-login destination should be reached and authenticated UI content should become visible.')
  }

  if (coverageType === 'validation') {
    observations.push('Validation feedback should become visible after the form is submitted or the invalid field interaction occurs.')
  }

  if (coverageType === 'search_filter') {
    observations.push('Search or filter actions should update the visible content without breaking page navigation.')
  }

  if (coverageType === 'upload') {
    observations.push('The upload control should accept the provided file and show an upload-related success or state change.')
  }

  if (coverageType === 'unsupported_non_ui') {
    observations.push('The run should stop with a blocked status and explain that the case is not safely automatable as a UI script.')
  }

  if (observations.length === 0) {
    observations.push(`The UI behavior described by '${input.test_case_title}' should be observable without relying on a generic smoke assertion only.`)
  }

  return observations
}

function buildDiagnostics(input: GeneratorInput, coverageType: CoverageType, internalTarget: boolean): Diagnostic[] {
  const diagnostics: Diagnostic[] = []
  const text = normalizeText(input)

  if (coverageType === 'unsupported_non_ui') {
    diagnostics.push({
      code: 'UNSUPPORTED_NON_UI',
      message: 'This test case appears to target API, performance, security, or database behavior instead of a UI browser interaction.',
      severity: 'error',
    })
  }

  if ((coverageType === 'form_interaction' || coverageType === 'validation') && !/\bform\b|\bfield\b|\binput\b/.test(text)) {
    diagnostics.push({
      code: 'AMBIGUOUS_FORM_TARGET',
      message: 'The case suggests form behavior, but the target fields are not described precisely. The runner will rely on broad selectors and may block during preflight.',
      severity: 'warning',
    })
  }

  if (!internalTarget && /\bdashboard\b|\bprojects\b|\busers\b/.test(text)) {
    diagnostics.push({
      code: 'EXTERNAL_TARGET_DOMAIN',
      message: 'The test case mentions internal checklist-manager concepts while the target is an external website. The generated plan will stay on the provided base URL.',
      severity: 'warning',
    })
  }

  if (coverageType === 'generic_ui') {
    diagnostics.push({
      code: 'GENERIC_UI_FALLBACK',
      message: 'The case was only partially actionable, so the generated plan uses a safe generic UI validation instead of a misleading minimal script.',
      severity: 'info',
    })
  }

  return diagnostics
}

function cssSelector(kind: string): Selector {
  switch (kind) {
    case 'email':
      return { by: 'css', value: 'input[type="email"], input[name*="email" i], input[id*="email" i], input[name*="user" i], input[id*="user" i]' }
    case 'password':
      return { by: 'css', value: 'input[type="password"], input[name*="password" i], input[id*="password" i]' }
    case 'search':
      return { by: 'css', value: 'input[type="search"], input[name*="search" i], input[id*="search" i], input[placeholder*="search" i]' }
    case 'textarea':
      return { by: 'css', value: 'textarea, [contenteditable="true"]' }
    case 'file':
      return { by: 'css', value: 'input[type="file"]' }
    default:
      return { by: 'css', value: 'input[type="text"], input:not([type]), textarea' }
  }
}

function primaryActionSelector(coverageType: CoverageType): Selector {
  switch (coverageType) {
    case 'auth_login':
      return { by: 'css', value: 'button[type="submit"], input[type="submit"], button:has-text("Login"), button:has-text("Sign in"), button:has-text("Log in")' }
    case 'search_filter':
      return { by: 'css', value: 'button[type="submit"], button:has-text("Search"), button:has-text("Filter"), button:has-text("Apply")' }
    case 'modal_dialog':
      return { by: 'css', value: 'button, [role="button"]' }
    default:
      return { by: 'css', value: 'button[type="submit"], input[type="submit"], button:has-text("Submit"), button:has-text("Save"), button:has-text("Send"), button:has-text("Continue")' }
  }
}

function defaultDestinationForScenario(scenario: Scenario): string {
  switch (scenario) {
    case 'dashboard':
      return '/dashboard'
    case 'checklists':
      return '/checklists'
    case 'projects':
      return '/projects'
    case 'users':
      return '/users'
    case 'login':
      return '/login'
    default:
      return '/'
  }
}

function buildPreflightChecks(coverageType: CoverageType, requiredInputs: RequiredInput[], scenario: Scenario, internalTarget: boolean): PreflightCheck[] {
  const checks: PreflightCheck[] = [
    {
      id: 'page-accessible',
      kind: 'page_accessible',
      label: 'Initial target page is reachable',
      required: true,
      failure_message: 'The target page could not be reached before executing the generated test plan.',
    },
  ]

  if (coverageType === 'unsupported_non_ui') {
    checks.push({
      id: 'unsupported-case',
      kind: 'unsupported',
      label: 'Case is a supported UI automation target',
      required: true,
      failure_message: 'This case mainly targets non-UI behavior. The run is blocked to avoid a misleading Playwright script.',
    })
    return checks
  }

  for (const input of requiredInputs) {
    checks.push({
      id: `input-${input.key}`,
      kind: 'input_available',
      label: `Input '${input.label}' has a value`,
      required: input.required,
      input_key: input.key,
      failure_message: `Missing required test data for '${input.label}'. Provide a value before running the test.`,
    })
  }

  switch (coverageType) {
    case 'auth_login':
      checks.push({
        id: 'login-user-field',
        kind: 'element_visible',
        label: 'Login user field is visible',
        required: true,
        selector: cssSelector('email'),
        failure_message: 'The target page does not expose a visible email or username field required by this login case.',
      })
      checks.push({
        id: 'login-password-field',
        kind: 'element_visible',
        label: 'Login password field is visible',
        required: true,
        selector: cssSelector('password'),
        failure_message: 'The target page does not expose a visible password field required by this login case.',
      })
      break
    case 'form_interaction':
    case 'validation':
      checks.push({
        id: 'form-visible',
        kind: 'element_visible',
        label: 'A form is visible on the page',
        required: true,
        selector: { by: 'css', value: 'form, [role="form"]' },
        failure_message: 'The test case expects form interaction, but no visible form was found on the target page.',
      })
      break
    case 'table_listing':
      checks.push({
        id: 'table-visible',
        kind: 'element_visible',
        label: 'A table or listing is visible on the page',
        required: true,
        selector: { by: 'css', value: 'table, [role="table"], [role="grid"], ul, ol' },
        failure_message: 'The test case expects a table or listing, but none was visible on the target page.',
      })
      break
    case 'search_filter':
      checks.push({
        id: 'search-field-visible',
        kind: 'element_visible',
        label: 'A search or filter field is visible',
        required: true,
        selector: cssSelector('search'),
        failure_message: 'The test case expects a search or filter input, but none was visible on the target page.',
      })
      break
    case 'upload':
      checks.push({
        id: 'file-input-visible',
        kind: 'element_visible',
        label: 'A file input is visible',
        required: true,
        selector: cssSelector('file'),
        failure_message: 'The test case expects a file upload control, but no visible file input was found on the target page.',
      })
      break
    case 'navigation':
      checks.push({
        id: 'navigation-link-visible',
        kind: 'element_visible',
        label: 'A navigation link or menu item is visible',
        required: true,
        selector: { by: 'css', value: 'nav a[href], header a[href], a[href]' },
        failure_message: 'The test case expects navigation links, but no visible navigable link was found on the target page.',
      })
      break
    default:
      break
  }

  if (internalTarget && scenario !== 'unknown') {
    checks.push({
      id: 'scenario-url',
      kind: 'url_contains',
      label: 'Internal route remains aligned with the requested scenario',
      required: false,
      expected: defaultDestinationForScenario(scenario),
      failure_message: `The current internal route does not align with the inferred scenario '${scenario}'.`,
    })
  }

  return checks
}

function buildStepsAndAsserts(input: GeneratorInput, coverageType: CoverageType, scenario: Scenario, internalTarget: boolean): { steps: Step[]; asserts: Assert[]; useAuth: boolean } {
  const normalizedBaseUrl = normalizeBaseUrl(input.base_url)
  const requiredInputs = extractRequiredInputs(input, coverageType)
  const negativeCase = /invalid|wrong|incorrect|negative|unsuccessful|error/.test(normalizeText(input))
  const destination = internalTarget && scenario !== 'unknown' && coverageType === 'generic_ui'
    ? defaultDestinationForScenario(scenario)
    : normalizedBaseUrl

  const steps: Step[] = [{ action: 'goto', url: destination }]
  const asserts: Assert[] = []

  switch (coverageType) {
    case 'auth_login':
      steps.push({ action: 'fill', selector: cssSelector('email'), input_key: 'email' })
      steps.push({ action: 'fill', selector: cssSelector('password'), input_key: 'password' })
      steps.push({ action: 'click', selector: primaryActionSelector('auth_login') })
      if (!negativeCase) {
        steps.push({ action: 'wait_for_url', contains: internalTarget ? '/dashboard' : new URL(normalizedBaseUrl).hostname, timeout_ms: 20000 })
        asserts.push({ type: 'expect_url_contains', value: internalTarget ? '/dashboard' : new URL(normalizedBaseUrl).hostname })
      } else {
        asserts.push({ type: 'expect_visible', selector: { by: 'css', value: '[role="alert"], .error, .alert, .invalid-feedback, [data-testid*="error"]' } })
      }
      break
    case 'form_interaction':
      for (const requiredInput of requiredInputs) {
        if (requiredInput.kind === 'file') {
          steps.push({ action: 'set_file', selector: cssSelector(requiredInput.kind), input_key: requiredInput.key })
        } else {
          steps.push({ action: 'fill', selector: cssSelector(requiredInput.kind), input_key: requiredInput.key })
        }
      }
      steps.push({ action: 'click', selector: primaryActionSelector('form_interaction') })
      asserts.push({ type: 'expect_visible', selector: { by: 'css', value: '[role="alert"], .success, .toast, .notification, body' } })
      break
    case 'validation':
      steps.push({ action: 'click', selector: primaryActionSelector('validation') })
      asserts.push({ type: 'expect_visible', selector: { by: 'css', value: '[role="alert"], .error, .invalid-feedback, .field-error, [aria-invalid="true"]' } })
      break
    case 'search_filter':
      steps.push({ action: 'fill', selector: cssSelector('search'), input_key: 'search_query' })
      steps.push({ action: 'press', selector: cssSelector('search'), key: 'Enter' })
      asserts.push({ type: 'expect_visible', selector: { by: 'css', value: 'body' } })
      break
    case 'table_listing':
      asserts.push({ type: 'expect_visible', selector: { by: 'css', value: 'table, [role="table"], [role="grid"], ul, ol' } })
      break
    case 'upload':
      steps.push({ action: 'set_file', selector: cssSelector('file'), input_key: 'upload_file' })
      steps.push({ action: 'click', selector: primaryActionSelector('upload') })
      asserts.push({ type: 'expect_visible', selector: { by: 'css', value: '[role="alert"], .success, .uploaded, body' } })
      break
    case 'modal_dialog':
      steps.push({ action: 'click', selector: primaryActionSelector('modal_dialog') })
      asserts.push({ type: 'expect_visible', selector: { by: 'css', value: '[role="dialog"], .modal, .dialog, [aria-modal="true"]' } })
      break
    case 'navigation':
      steps.push({ action: 'click', selector: { by: 'css', value: 'nav a[href], header a[href], a[href]' } })
      asserts.push({ type: 'expect_visible', selector: { by: 'css', value: 'body' } })
      break
    case 'button_action':
      steps.push({ action: 'click', selector: { by: 'css', value: 'button, [role="button"], input[type="button"], input[type="submit"]' } })
      asserts.push({ type: 'expect_visible', selector: { by: 'css', value: 'body' } })
      break
    case 'redirection':
      steps.push({ action: 'click', selector: { by: 'css', value: 'a[href], button, [role="button"]' } })
      asserts.push({ type: 'expect_url_contains', value: new URL(normalizedBaseUrl).hostname })
      break
    case 'feedback_message':
      steps.push({ action: 'click', selector: primaryActionSelector('feedback_message') })
      asserts.push({ type: 'expect_visible', selector: { by: 'css', value: '[role="alert"], .toast, .notification, .success, .error' } })
      break
    case 'generic_ui':
      steps.push({ action: 'wait_for_selector', selector: { by: 'css', value: 'body' }, state: 'visible', timeout_ms: 20000 })
      steps.push({ action: 'screenshot', name: 'generic-ui-baseline' })
      asserts.push({ type: 'expect_visible', selector: { by: 'css', value: 'body' } })
      break
    case 'unsupported_non_ui':
      asserts.push({ type: 'expect_visible', selector: { by: 'css', value: 'body' } })
      break
    default:
      asserts.push({ type: 'expect_visible', selector: { by: 'css', value: 'body' } })
      break
  }

  if (asserts.length === 0) {
    asserts.push({ type: 'expect_visible', selector: { by: 'css', value: 'body' } })
  }

  return {
    steps,
    asserts,
    useAuth: coverageType === 'auth_login' ? false : input.use_auth !== false,
  }
}

function inferRuntimeBrowsers(input: GeneratorInput): Array<'chromium' | 'firefox' | 'webkit' | 'msedge'> | undefined {
  const text = normalizeText(input)
  const requested = new Set<'chromium' | 'firefox' | 'webkit' | 'msedge'>()
  if (/\bchrome\b|\bchromium\b/.test(text)) requested.add('chromium')
  if (/\bfirefox\b/.test(text)) requested.add('firefox')
  if (/\bsafari\b|\bwebkit\b/.test(text)) requested.add('webkit')
  if (/\bedge\b|\bmsedge\b/.test(text)) requested.add('msedge')
  if (/cross\s*-?\s*browser|compatibility/.test(text) && requested.size === 0) {
    requested.add('chromium')
    requested.add('firefox')
    requested.add('webkit')
    requested.add('msedge')
  }
  return requested.size > 0 ? Array.from(requested) : undefined
}

function buildRuntimeConfig(input: GeneratorInput) {
  return {
    headless: config.runHeadless,
    slow_mo_ms: config.runSlowMoMs,
    hold_open_ms: config.runHoldOpenMs,
    browsers: inferRuntimeBrowsers(input),
    timeout_ms: 30000,
    viewport: { width: 1280, height: 720 },
    trace: 'retain-on-failure' as const,
    video: 'retain-on-failure' as const,
    screenshot: 'only-on-failure' as const,
  }
}

function buildCasePlan(input: GeneratorInput, scenarioOverride?: Scenario) {
  const normalizedBaseUrl = normalizeBaseUrl(input.base_url)
  const internalTarget = isInternalChecklistTarget(normalizedBaseUrl)
  const coverageType = inferCoverageType(input)
  const scenario = scenarioOverride ?? inferScenarioFromText(input)
  const requiredInputs = extractRequiredInputs(input, coverageType)
  const preconditions = extractPreconditions(input, coverageType, internalTarget)
  const expectedObservations = buildExpectedObservations(input, coverageType)
  const diagnostics = buildDiagnostics(input, coverageType, internalTarget)
  const stepsAndAsserts = buildStepsAndAsserts(input, coverageType, scenario, internalTarget)
  const preflightChecks = buildPreflightChecks(coverageType, requiredInputs, scenario, internalTarget)
  const intentSummary = buildIntentSummary(input, coverageType)

  const generatedPlan = {
    title: input.test_case_title,
    intent_summary: intentSummary,
    coverage_type: coverageType,
    preflight_checks: preflightChecks,
    steps: stepsAndAsserts.steps,
    asserts: stepsAndAsserts.asserts,
    expected_observations: expectedObservations,
    diagnostics: diagnostics,
  }

  const executionProfile = {
    intent_summary: intentSummary,
    coverage_type: coverageType,
    preconditions: preconditions,
    required_inputs: requiredInputs,
    expected_observations: expectedObservations,
    diagnostics: diagnostics,
    generation_confidence: coverageType === 'generic_ui' ? 0.55 : coverageType === 'unsupported_non_ui' ? 0.9 : 0.8,
    last_generated_plan: generatedPlan,
  }

  return {
    executionProfile,
    generatedPlan,
    preflightChecks,
    steps: stepsAndAsserts.steps,
    asserts: stepsAndAsserts.asserts,
    useAuth: stepsAndAsserts.useAuth,
  }
}

type ClassificationOutcome = {
  scenario: Scenario
  confidence?: number
  source: string
  modelUsed?: string
}

function isOllamaTimeoutError(err: unknown): err is Error {
  return err instanceof Error && /timed out/i.test(err.message)
}

async function classifyWithFallback(input: GeneratorInput): Promise<ClassificationOutcome> {
  const description = [
    input.test_case_text,
    input.test_case_description ? `Description: ${input.test_case_description}` : '',
    input.notes ? `Notes: ${input.notes}` : '',
    input.priority ? `Priority: ${input.priority}` : '',
    input.criticality ? `Criticality: ${input.criticality}` : '',
  ].filter(Boolean).join('\n')

  let modelUsed = config.ollamaModel

  try {
    const primary = await classifyItem({
      baseUrl: config.ollamaBaseUrl,
      model: modelUsed,
      timeoutMs: config.ollamaTimeoutMs,
      title: input.test_case_title,
      description,
      context: {
        external_id: input.external_id,
        project_version_id: input.project_version_id,
        environment_name: input.environment_name,
      },
    })

    return {
      scenario: primary.scenario,
      source: 'primary',
      modelUsed,
      ...(typeof primary.confidence === 'number' ? { confidence: primary.confidence } : {}),
    }
  } catch (error) {
    const fallbackModel = config.ollamaFallbackModel
    if (fallbackModel.length > 0 && fallbackModel !== modelUsed && isOllamaTimeoutError(error)) {
      modelUsed = fallbackModel
      const fallback = await classifyItem({
        baseUrl: config.ollamaBaseUrl,
        model: modelUsed,
        timeoutMs: config.ollamaTimeoutMs,
        title: input.test_case_title,
        description,
        context: {
          external_id: input.external_id,
          project_version_id: input.project_version_id,
          environment_name: input.environment_name,
        },
      })

      return {
        scenario: fallback.scenario,
        source: 'fallback',
        modelUsed,
        ...(typeof fallback.confidence === 'number' ? { confidence: fallback.confidence } : {}),
      }
    }

    return {
      scenario: inferScenarioFromText(input),
      confidence: 0,
      source: 'heuristic',
    }
  }
}

function buildRunSpecFromPlan(input: GeneratorInput, scenarioOverride?: Scenario) {
  const normalizedBaseUrl = normalizeBaseUrl(input.base_url)
  const plan = buildCasePlan(input, scenarioOverride)

  return {
    schema_version: '1.0' as const,
    run_id: input.run_id,
    target: {
      base_url: normalizedBaseUrl,
    },
    runtime: buildRuntimeConfig(input),
    cases: [
      {
        external_id: input.external_id,
        title: input.test_case_title,
        severity: 'critical' as const,
        use_auth: plan.useAuth,
        execution_profile: plan.executionProfile,
        generated_plan: plan.generatedPlan,
        preflight_checks: plan.preflightChecks,
        steps: plan.steps,
        asserts: plan.asserts,
      },
    ],
  }
}

function usageAndExit(): never {
  process.stderr.write('Usage: node dist/generateRunSpec.js --input <path-to-input.json>\n')
  process.exit(2)
}

async function main(): Promise<void> {
  const inputFlagIndex = process.argv.findIndex((value) => value === '--input')
  if (inputFlagIndex === -1 || !process.argv[inputFlagIndex + 1]) {
    usageAndExit()
  }

  const inputPath = path.resolve(process.argv[inputFlagIndex + 1] as string)
  const raw = await fs.readFile(inputPath, 'utf8')
  const parsedInput = InputSchema.parse(JSON.parse(raw))

  const generationEngine = config.generationEngine === 'classification' ? 'classification' : 'playwright-models'
  process.stderr.write(`[agent] Generation engine: ${generationEngine}\n`)

  let runSpec: ReturnType<typeof buildRunSpecFromPlan>

  if (generationEngine === 'classification') {
    const classification = await classifyWithFallback(parsedInput)
    process.stderr.write(
      `[agent] Classification outcome: scenario=${classification.scenario}; source=${classification.source}; confidence=${typeof classification.confidence === 'number' ? classification.confidence.toFixed(3) : 'n/a'}\n`,
    )
    runSpec = buildRunSpecFromPlan(parsedInput, classification.scenario)
  } else {
    runSpec = buildRunSpecFromPlan(parsedInput)
  }

  const validatedRunSpec = RunSpecSchema.parse(runSpec)
  process.stdout.write(`${JSON.stringify(validatedRunSpec)}\n`)
}

main().catch((error) => {
  const message = error instanceof Error ? error.message : String(error)
  process.stderr.write(`[agent] generateRunSpec failed: ${message}\n`)
  process.exit(3)
})
