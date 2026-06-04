import fs from 'node:fs/promises'
import path from 'node:path'
import { z } from 'zod'
import { config, getGenerationEngine, getOpenAIConfig } from './config.js'
import { RunSpecDslV1Schema, type GenerationMetadataDsl } from './dslSchema.js'
import { createLLMProvider } from './llm/provider.js'
import { generateRunCase } from './llm/runCaseGenerator.js'
import type { GeneratorChecklistItem } from './prompts/generatorPrompt.js'

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
  provided_inputs: z.record(z.string(), z.any()).optional().default({}),
  expected_result: z.record(z.string(), z.any()).optional().default({}),
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
  allow_empty: z.boolean().optional(),
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
  z.object({ type: z.literal('expect_text_contains'), selector: SelectorSchema, text: z.string() }),
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

type Scenario = 'login' | 'dashboard' | 'checklists' | 'projects' | 'users' | 'unknown'

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

function benchmarkHintText(input: GeneratorInput): string {
  const assertionKeywords = Array.isArray(input.expected_result.assertion_keywords)
    ? input.expected_result.assertion_keywords.filter((value): value is string => typeof value === 'string')
    : []

  return [
    normalizeText(input),
    typeof input.expected_result.visible_text_contains === 'string' ? input.expected_result.visible_text_contains.toLowerCase() : '',
    typeof input.expected_result.visible_text === 'string' ? input.expected_result.visible_text.toLowerCase() : '',
    assertionKeywords.join(' ').toLowerCase(),
  ].filter(Boolean).join('\n')
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

function getProvidedInputValue(input: GeneratorInput, key: string): string | null {
  const aliasesByKey: Record<string, string[]> = {
    email: ['email', 'username', 'user', 'login', 'identifier'],
    username: ['username', 'email', 'user', 'login', 'identifier'],
    user: ['user', 'username', 'email', 'login', 'identifier'],
    login: ['login', 'username', 'email', 'user', 'identifier'],
    identifier: ['identifier', 'username', 'email', 'user', 'login'],
  }

  const candidateKeys = aliasesByKey[key] ?? [key]

  for (const candidateKey of candidateKeys) {
    const value = input.provided_inputs[candidateKey]
    if (typeof value === 'string') {
      return value
    }
  }

  return null
}

function isSauceDemoTarget(input: GeneratorInput): boolean {
  const normalizedBaseUrl = normalizeBaseUrl(input.base_url)
  const { hostname, port } = new URL(normalizedBaseUrl)
  const normalizedHost = hostname.toLowerCase()
  const normalizedSourceApp = (input.source_app ?? '').toLowerCase()

  if (normalizedSourceApp.startsWith('sauce_demo')) {
    return true
  }

  if (normalizedHost.includes('saucedemo.com')) {
    return true
  }

  return normalizedHost === '127.0.0.1' && port === '4177'
}

function isRequiredFieldValidationScenario(input: GeneratorInput): boolean {
  const text = normalizeText(input)

  return mentionsAny(text, [
    /\bmissing\b/,
    /\brequired field\b/,
    /\brequired\b/,
    /\bblank field\b/,
    /\bempty field\b/,
    /\bleav(?:e|ing) field blank\b/,
    /\bleav(?:e|ing) .* blank\b/,
  ])
}

function isIntentionalBlankInput(input: GeneratorInput, key: string): boolean {
  const text = benchmarkHintText(input)
  const value = getProvidedInputValue(input, key)

  if (value === null || value !== '' || !isRequiredFieldValidationScenario(input)) {
    return false
  }

  const patternsByKey: Record<string, RegExp[]> = {
    email: [/\bmissing username\b/, /\bmissing username and password\b/, /\bmissing email\b/, /\bmissing login\b/, /\bmissing identifier\b/, /\bblank username\b/, /\bempty username\b/, /\busername is required\b/, /\bemail is required\b/],
    username: [/\bmissing username\b/, /\bblank username\b/, /\bempty username\b/, /\busername is required\b/],
    user: [/\bmissing username\b/, /\bblank username\b/, /\bempty username\b/],
    login: [/\bmissing login\b/, /\bblank login\b/, /\bempty login\b/],
    identifier: [/\bmissing identifier\b/, /\bblank identifier\b/, /\bempty identifier\b/],
    password: [/\bmissing password\b/, /\bmissing username and password\b/, /\bmissing credentials\b/, /\bblank password\b/, /\bempty password\b/, /\bpassword is required\b/],
  }

  const patterns = patternsByKey[key] ?? []
  return patterns.some((pattern) => pattern.test(text))
}

function isSauceDemoProtectedRouteCase(input: GeneratorInput): boolean {
  if (!isSauceDemoTarget(input)) {
    return false
  }

  const text = benchmarkHintText(input)
  return /\binventory page requires login\b|\bcart page requires login\b|\bwithout an authenticated session redirects to login\b/.test(text)
}

function sauceDemoProtectedPath(input: GeneratorInput): string {
  const text = benchmarkHintText(input)
  if (/\bcart\b/.test(text)) {
    return '/cart.html'
  }

  return '/inventory.html'
}

function isSauceDemoAddToCartCase(input: GeneratorInput): boolean {
  if (!isSauceDemoTarget(input)) {
    return false
  }

  const text = benchmarkHintText(input)
  return /\badd\b.*\bcart\b|\bcart badge\b/.test(text)
}

function isSauceDemoInventoryRemovalCase(input: GeneratorInput): boolean {
  return isSauceDemoTarget(input) && /\bremove\b.*\binventory page\b/.test(benchmarkHintText(input))
}

function isSauceDemoCartRemovalCase(input: GeneratorInput): boolean {
  return isSauceDemoTarget(input) && /\bremove\b.*\bcart page\b/.test(benchmarkHintText(input))
}

function isSauceDemoCartRetentionCase(input: GeneratorInput): boolean {
  return isSauceDemoTarget(input) && /\bcart retains\b|\bstill present after navigating to the cart page\b/.test(benchmarkHintText(input))
}

function isSauceDemoProductDetailCase(input: GeneratorInput): boolean {
  return isSauceDemoTarget(input) && /\bproduct detail\b|\bopens the product detail page\b/.test(benchmarkHintText(input))
}

function isSauceDemoBackToProductsCase(input: GeneratorInput): boolean {
  return isSauceDemoTarget(input) && /\bback to products\b/.test(benchmarkHintText(input))
}

function isSauceDemoSortingCase(input: GeneratorInput): boolean {
  return isSauceDemoTarget(input) && /\bsort\b/.test(benchmarkHintText(input))
}

function isSauceDemoAuthenticatedCatalogCase(input: GeneratorInput): boolean {
  return isSauceDemoAddToCartCase(input)
    || isSauceDemoInventoryRemovalCase(input)
    || isSauceDemoCartRemovalCase(input)
    || isSauceDemoCartRetentionCase(input)
    || isSauceDemoProductDetailCase(input)
    || isSauceDemoBackToProductsCase(input)
    || isSauceDemoSortingCase(input)
}

function sauceDemoProductSlugFromName(name: string | null | undefined): string {
  const normalized = (name ?? '').trim().toLowerCase()

  if (normalized.includes('bike light')) {
    return 'sauce-labs-bike-light'
  }

  if (normalized.includes('t-shirt')) {
    return 'test.allthethings()-t-shirt-(red)'
  }

  return 'sauce-labs-backpack'
}

function sauceDemoProductNameForSlug(slug: string): string {
  switch (slug) {
    case 'sauce-labs-bike-light':
      return 'Sauce Labs Bike Light'
    case 'test.allthethings()-t-shirt-(red)':
      return 'Test.allTheThings() T-Shirt (Red)'
    case 'sauce-labs-backpack':
    default:
      return 'Sauce Labs Backpack'
  }
}

function sauceDemoPrimaryProductSlug(input: GeneratorInput): string {
  const explicitProduct = input.provided_inputs.product
  if (typeof explicitProduct === 'string' && explicitProduct.trim() !== '') {
    return sauceDemoProductSlugFromName(explicitProduct)
  }

  const products = input.provided_inputs.products
  if (Array.isArray(products)) {
    const firstProduct = products.find((value): value is string => typeof value === 'string' && value.trim() !== '')
    if (firstProduct) {
      return sauceDemoProductSlugFromName(firstProduct)
    }
  }

  if (/\bbike light\b/.test(benchmarkHintText(input))) {
    return 'sauce-labs-bike-light'
  }

  return 'sauce-labs-backpack'
}

function sauceDemoProductSlugs(input: GeneratorInput): string[] {
  const products = input.provided_inputs.products
  if (Array.isArray(products)) {
    const resolved = products
      .filter((value): value is string => typeof value === 'string' && value.trim() !== '')
      .map((value) => sauceDemoProductSlugFromName(value))

    if (resolved.length > 0) {
      return Array.from(new Set(resolved))
    }
  }

  return [sauceDemoPrimaryProductSlug(input)]
}

function sauceDemoSortValue(input: GeneratorInput): string {
  const providedSort = input.provided_inputs.sort
  if (typeof providedSort === 'string' && providedSort.trim() !== '') {
    return providedSort.trim().toLowerCase()
  }

  const text = benchmarkHintText(input)
  if (/\bz to a\b/.test(text)) return 'za'
  if (/\blow to high\b/.test(text)) return 'lohi'
  if (/\bhigh to low\b/.test(text)) return 'hilo'

  return 'az'
}

function sauceDemoSortLabel(sortValue: string): string {
  switch (sortValue) {
    case 'za':
      return 'Name (Z to A)'
    case 'lohi':
      return 'Price (low to high)'
    case 'hilo':
      return 'Price (high to low)'
    case 'az':
    default:
      return 'Name (A to Z)'
  }
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

function mentionsAny(text: string, patterns: RegExp[]): boolean {
  return patterns.some((pattern) => pattern.test(text))
}

function quotedPhrasesFromText(text: string): string[] {
  return Array.from(text.matchAll(/["'“”‘’]([^"'“”‘’]{3,80})["'“”‘’]/g))
    .map((match) => match[1]?.trim() ?? '')
    .filter((value) => value.length >= 3)
}

function extractExpectedFeedbackText(input: GeneratorInput): string | null {
  const expectedVisibleText = input.expected_result.visible_text_contains
  if (typeof expectedVisibleText === 'string' && expectedVisibleText.trim() !== '') {
    return expectedVisibleText.trim()
  }

  const expectedVisibleTextExact = input.expected_result.visible_text
  if (typeof expectedVisibleTextExact === 'string' && expectedVisibleTextExact.trim() !== '') {
    return expectedVisibleTextExact.trim()
  }

  const assertionKeywords = input.expected_result.assertion_keywords
  if (Array.isArray(assertionKeywords)) {
    const firstKeyword = assertionKeywords.find((keyword) => typeof keyword === 'string' && keyword.trim() !== '')
    if (typeof firstKeyword === 'string') {
      return firstKeyword.trim()
    }
  }

  const text = normalizeText(input)
  if (!mentionsAny(text, [/\bmessage\b/, /\btoast\b/, /\balert\b/, /\berror\b/, /\bsuccess\b/, /\bconfirmation\b/])) {
    return null
  }

  const candidates = quotedPhrasesFromText([
    input.test_case_title,
    input.test_case_text,
    input.test_case_description,
    input.notes,
  ].filter(Boolean).join('\n'))

  if (candidates[0]) {
    return candidates[0]
  }

  if (isSauceDemoTarget(input)) {
    if (/\blocked out\b/.test(text)) return 'locked out'
    if (/\bmissing username\b|\busername is required\b/.test(text)) return 'Username is required'
    if (/\bmissing password\b|\bpassword is required\b/.test(text)) return 'Password is required'
    if (/\binvalid password\b|\binvalid username\b|\bdo not match\b/.test(text)) return 'Username and password do not match'
  }

  return null
}

function extractRequiredInputs(input: GeneratorInput, coverageType: CoverageType): RequiredInput[] {
  const text = normalizeText(input)
  const inputs: RequiredInput[] = []

  if (isSauceDemoProtectedRouteCase(input)) {
    return []
  }

  if (coverageType === 'auth_login') {
    const identityInput = requiredInput('email', 'Email or username', 'email', true, 'Credential used to authenticate the user.', getProvidedInputValue(input, 'email'))
    if (isIntentionalBlankInput(input, 'email')) {
      identityInput.allow_empty = true
    }
    const passwordInput = requiredInput('password', 'Password', 'password', true, 'Password used to authenticate the user.', getProvidedInputValue(input, 'password'))
    if (isIntentionalBlankInput(input, 'password')) {
      passwordInput.allow_empty = true
    }

    inputs.push(identityInput)
    inputs.push(passwordInput)
  }

  if (isSauceDemoAuthenticatedCatalogCase(input)) {
    inputs.push(requiredInput('email', 'Email or username', 'email', true, 'Credential used to authenticate the user.', getProvidedInputValue(input, 'email')))
    inputs.push(requiredInput('password', 'Password', 'password', true, 'Password used to authenticate the user.', getProvidedInputValue(input, 'password')))
  }

  if (coverageType === 'search_filter') {
    inputs.push(requiredInput('search_query', 'Search query', 'search', true, 'Value used to exercise search or filtering.'))
  }

  if (coverageType === 'upload') {
    inputs.push(requiredInput('upload_file', 'File to upload', 'file', true, 'Absolute path to the file that should be uploaded.'))
  }

  if (coverageType === 'form_interaction' || coverageType === 'validation') {
    if (/\bfull name\b|\bdisplay name\b|\bnom complet\b/.test(text)) {
      inputs.push(requiredInput('full_name', 'Full name', 'text', true, 'Value expected for the full name field.'))
    }
    if (/\bfirst name\b|\bprenom\b/.test(text)) {
      inputs.push(requiredInput('first_name', 'First name', 'text', true, 'Value expected for the first name field.'))
    }
    if (/\blast name\b|\bsurname\b|\bnom de famille\b/.test(text)) {
      inputs.push(requiredInput('last_name', 'Last name', 'text', true, 'Value expected for the last name field.'))
    }
    if (/\busername\b|\buser name\b|\bidentifiant\b/.test(text)) {
      inputs.push(requiredInput('username', 'Username', 'text', true, 'Value expected for the username field.'))
    }
    if (/\bname\b|\bnom\b/.test(text) && inputs.every((entry) => entry.key !== 'full_name' && entry.key !== 'first_name' && entry.key !== 'last_name')) {
      inputs.push(requiredInput('full_name', 'Full name', 'text', true, 'Value expected for the name field.'))
    }
    if (/\bemail\b/.test(text)) {
      inputs.push(requiredInput('email', 'Email', 'email', true, 'Value expected for the email field.'))
    }
    if (/\bpassword\b|\bmot de passe\b/.test(text)) {
      inputs.push(requiredInput('password', 'Password', 'password', true, 'Value expected for the password field.'))
    }
    if (/\bconfirm password\b|\bpassword confirmation\b|\bconfirm your password\b|\bconfirmation du mot de passe\b/.test(text)) {
      inputs.push(requiredInput('confirm_password', 'Confirm password', 'password', true, 'Confirmation value expected for the password confirmation field.'))
    }
    if (/\bphone\b|\bmobile\b|\btel\b|\btelephone\b/.test(text)) {
      inputs.push(requiredInput('phone', 'Phone number', 'text', true, 'Value expected for the phone field.'))
    }
    if (/\bsearch\b|\brecherche\b/.test(text) && !inputs.some((entry) => entry.key === 'search_query')) {
      inputs.push(requiredInput('search_query', 'Search query', 'search', true, 'Value expected for the search field.'))
    }
    if (/\bmessage\b|\bcomment\b|\bdescription\b|\bnotes?\b/.test(text)) {
      inputs.push(requiredInput('message', 'Message', 'textarea', true, 'Value expected for the long-form text field.'))
    }
    if (/\baddress\b|\badresse\b/.test(text)) {
      inputs.push(requiredInput('address', 'Address', 'text', true, 'Value expected for the address field.'))
    }
    if (/\bcity\b|\bville\b/.test(text)) {
      inputs.push(requiredInput('city', 'City', 'text', true, 'Value expected for the city field.'))
    }
    if (/\bzip\b|\bpostal\b|\bpostcode\b|\bcode postal\b/.test(text)) {
      inputs.push(requiredInput('postal_code', 'Postal code', 'text', true, 'Value expected for the postal code field.'))
    }
    if (/\bcompany\b|\bsociete\b|\borganisation\b/.test(text)) {
      inputs.push(requiredInput('company', 'Company', 'text', true, 'Value expected for the company field.'))
    }
    if (/\bprofile\b|\bprofil\b/.test(text) && /\bname\b|\bnom\b/.test(text)) {
      inputs.push(requiredInput('profile_name', 'Profile name', 'text', true, 'Value expected for the profile name field.'))
    }
    if (/\breservation\b|\bbooking\b|\breserver\b|\bbook\b/.test(text)) {
      inputs.push(requiredInput('reservation_name', 'Reservation name', 'text', true, 'Value expected for the reservation name or booking field.'))
      if (/\bdate\b|\bcheck[-\s]?in\b|\barrival\b|\bstart date\b/.test(text)) {
        inputs.push(requiredInput('start_date', 'Start date', 'text', true, 'Value expected for the reservation start date field.'))
      }
      if (/\bcheck[-\s]?out\b|\bdeparture\b|\bend date\b/.test(text)) {
        inputs.push(requiredInput('end_date', 'End date', 'text', true, 'Value expected for the reservation end date field.'))
      }
    }
    if (/\bpayment\b|\bcard\b|\bcheckout\b|\bpaiement\b/.test(text)) {
      inputs.push(requiredInput('card_number', 'Card number', 'text', true, 'Value expected for the card number field.'))
      if (/\bexpiry\b|\bexpiration\b|\bexpire\b/.test(text)) {
        inputs.push(requiredInput('card_expiry', 'Card expiry', 'text', true, 'Value expected for the card expiry field.'))
      }
      if (/\bcvv\b|\bcvc\b|\bsecurity code\b/.test(text)) {
        inputs.push(requiredInput('card_cvv', 'Card security code', 'text', true, 'Value expected for the card security code field.'))
      }
    }
    if (/\bupload\b|\battach\b|\bpi[eè]ce jointe\b/.test(text)) {
      inputs.push(requiredInput('upload_file', 'File to upload', 'file', true, 'Absolute path to the file that should be uploaded.'))
    }
    if (inputs.length === 0 && coverageType === 'form_interaction') {
      inputs.push(requiredInput('generic_text', 'Form value', 'text', true, 'Value used when the form field names are ambiguous.'))
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

function selectorForInput(input: RequiredInput): Selector {
  switch (input.key) {
    case 'email':
      return cssSelector('email')
    case 'password':
    case 'confirm_password':
      return cssSelector('password')
    case 'search_query':
      return cssSelector('search')
    case 'upload_file':
      return cssSelector('file')
    case 'message':
      return { by: 'css', value: 'textarea[name*="message" i], textarea[name*="comment" i], textarea[name*="description" i], textarea, [contenteditable="true"]' }
    case 'full_name':
    case 'profile_name':
      return { by: 'css', value: 'input[name*="full" i], input[id*="full" i], input[name*="name" i], input[id*="name" i]' }
    case 'first_name':
      return { by: 'css', value: 'input[name*="first" i], input[id*="first" i], input[name*="prenom" i], input[id*="prenom" i]' }
    case 'last_name':
      return { by: 'css', value: 'input[name*="last" i], input[id*="last" i], input[name*="surname" i], input[id*="surname" i], input[name*="family" i], input[id*="family" i]' }
    case 'username':
      return { by: 'css', value: 'input[name*="username" i], input[id*="username" i], input[name*="login" i], input[id*="login" i], input[name*="user" i], input[id*="user" i]' }
    case 'phone':
      return { by: 'css', value: 'input[type="tel"], input[name*="phone" i], input[id*="phone" i], input[name*="mobile" i], input[id*="mobile" i], input[name*="tel" i], input[id*="tel" i]' }
    case 'address':
      return { by: 'css', value: 'input[name*="address" i], input[id*="address" i], textarea[name*="address" i], textarea[id*="address" i]' }
    case 'city':
      return { by: 'css', value: 'input[name*="city" i], input[id*="city" i], input[name*="ville" i], input[id*="ville" i]' }
    case 'postal_code':
      return { by: 'css', value: 'input[name*="zip" i], input[id*="zip" i], input[name*="postal" i], input[id*="postal" i], input[name*="postcode" i], input[id*="postcode" i]' }
    case 'company':
      return { by: 'css', value: 'input[name*="company" i], input[id*="company" i], input[name*="organisation" i], input[id*="organisation" i]' }
    case 'reservation_name':
      return { by: 'css', value: 'input[name*="reservation" i], input[id*="reservation" i], input[name*="booking" i], input[id*="booking" i], input[name*="guest" i], input[id*="guest" i]' }
    case 'start_date':
      return { by: 'css', value: 'input[type="date"], input[name*="start" i], input[id*="start" i], input[name*="checkin" i], input[id*="checkin" i], input[name*="arrival" i], input[id*="arrival" i]' }
    case 'end_date':
      return { by: 'css', value: 'input[type="date"], input[name*="end" i], input[id*="end" i], input[name*="checkout" i], input[id*="checkout" i], input[name*="departure" i], input[id*="departure" i]' }
    case 'card_number':
      return { by: 'css', value: 'input[name*="card" i], input[id*="card" i], input[name*="number" i], input[id*="number" i], input[inputmode="numeric"]' }
    case 'card_expiry':
      return { by: 'css', value: 'input[name*="expiry" i], input[id*="expiry" i], input[name*="expiration" i], input[id*="expiration" i], input[placeholder*="MM" i]' }
    case 'card_cvv':
      return { by: 'css', value: 'input[name*="cvv" i], input[id*="cvv" i], input[name*="cvc" i], input[id*="cvc" i], input[name*="security" i], input[id*="security" i]' }
    default:
      return cssSelector(input.kind)
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

function actionSelectorForInput(input: GeneratorInput, coverageType: CoverageType): Selector {
  const text = normalizeText(input)

  if (coverageType === 'auth_login') {
    return { by: 'css', value: 'button[type="submit"], input[type="submit"], button:has-text("Login"), button:has-text("Sign in"), button:has-text("Log in"), [role="button"]:has-text("Login"), [role="button"]:has-text("Sign in")' }
  }

  if (/\bregister\b|\bsign up\b|\bcreate account\b|\binscription\b/.test(text)) {
    return { by: 'css', value: 'button[type="submit"], input[type="submit"], button:has-text("Register"), button:has-text("Sign up"), button:has-text("Create account"), [role="button"]:has-text("Register")' }
  }
  if (/\bpayment\b|\bpay\b|\bcheckout\b|\bpaiement\b/.test(text)) {
    return { by: 'css', value: 'button[type="submit"], input[type="submit"], button:has-text("Pay"), button:has-text("Checkout"), button:has-text("Place order"), [role="button"]:has-text("Pay")' }
  }
  if (/\bbooking\b|\breservation\b|\bbook\b|\breserver\b/.test(text)) {
    return { by: 'css', value: 'button[type="submit"], input[type="submit"], button:has-text("Book"), button:has-text("Reserve"), button:has-text("Confirm"), [role="button"]:has-text("Book")' }
  }
  if (/\bsearch\b|\bfilter\b|\brecherche\b/.test(text)) {
    return { by: 'css', value: 'button[type="submit"], input[type="submit"], button:has-text("Search"), button:has-text("Filter"), button:has-text("Apply"), [role="button"]:has-text("Search")' }
  }
  if (/\bupload\b|\battach\b|\bimport\b/.test(text)) {
    return { by: 'css', value: 'button[type="submit"], input[type="submit"], button:has-text("Upload"), button:has-text("Import"), button:has-text("Attach"), [role="button"]:has-text("Upload")' }
  }
  if (/\bsave\b|\bupdate\b|\bsubmit\b|\bsend\b|\bpublish\b|\bcomment\b|\bprofil\b|\bprofile\b/.test(text)) {
    return { by: 'css', value: 'button[type="submit"], input[type="submit"], button:has-text("Save"), button:has-text("Update"), button:has-text("Submit"), button:has-text("Send"), button:has-text("Publish"), [role="button"]:has-text("Save")' }
  }

  return primaryActionSelector(coverageType)
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

function buildPreflightChecks(input: GeneratorInput, coverageType: CoverageType, requiredInputs: RequiredInput[], scenario: Scenario, internalTarget: boolean): PreflightCheck[] {
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

  if (isSauceDemoProtectedRouteCase(input) || isSauceDemoAuthenticatedCatalogCase(input)) {
    return checks
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
      for (const input of requiredInputs) {
        checks.push({
          id: `field-${input.key}-visible`,
          kind: 'element_visible',
          label: `Field '${input.label}' is visible`,
          required: input.required,
          selector: selectorForInput(input),
          failure_message: `The target page does not expose the field '${input.label}' required by this test case.`,
        })
      }
      checks.push({
        id: 'form-submit-visible',
        kind: 'element_visible',
        label: 'A submit or primary action is visible',
        required: false,
        selector: { by: 'css', value: 'button[type="submit"], input[type="submit"], button, [role="button"]' },
        failure_message: 'The target page does not expose a visible action button for the expected form flow.',
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
      checks.push({
        id: 'search-results-area-visible',
        kind: 'element_attached',
        label: 'A result container is attached',
        required: false,
        selector: { by: 'css', value: 'table, [role="table"], [role="grid"], ul, ol, main, section, body' },
        failure_message: 'No result container was detected for the search flow.',
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
  const negativeCase = /invalid|wrong|incorrect|negative|unsuccessful|error|missing|required|blank|empty|locked out|cannot login|can't login|unable to login|denied|rejected|reject/.test(normalizeText(input))
  const expectedFeedbackText = extractExpectedFeedbackText(input)
  const isSauceDemoLogin = coverageType === 'auth_login' && isSauceDemoTarget(input)
  const identityInput = requiredInputs.find((entry) => entry.key === 'email') ?? requiredInput('email', 'Email', 'email', true, '')
  const passwordInput = requiredInputs.find((entry) => entry.key === 'password') ?? requiredInput('password', 'Password', 'password', true, '')
  const destination = internalTarget && scenario !== 'unknown' && coverageType === 'generic_ui'
    ? defaultDestinationForScenario(scenario)
    : internalTarget && coverageType === 'auth_login'
      ? defaultDestinationForScenario('login')
      : normalizedBaseUrl

  const steps: Step[] = [{ action: 'goto', url: destination }]
  const asserts: Assert[] = []
  const alertSelector: Selector = { by: 'css', value: '[role="alert"], [data-test="error"], h3[data-test="error"], .error-message-container, .error-message-container.error, button.error-button, .error, .alert, .invalid-feedback, .field-error, .toast, .notification, .success' }
  const successSelector: Selector = { by: 'css', value: '[role="alert"], .success, .toast, .notification, [data-testid*="success"], [data-testid*="toast"]' }
  const listSelector: Selector = { by: 'css', value: 'table, [role="table"], [role="grid"], ul, ol, [data-testid*="list"], [data-testid*="table"]' }
  const sauceDemoLoginSelector: Selector = { by: 'css', value: 'input[data-test="username"], #user-name, input[name="user-name"]' }
  const sauceDemoCartBadgeSelector: Selector = { by: 'css', value: '[data-test="shopping-cart-badge"], .shopping_cart_badge, .cart-badge' }
  const sauceDemoBackpackAddSelector: Selector = { by: 'css', value: '[data-test="add-to-cart-sauce-labs-backpack"]' }
  const sauceDemoBikeLightAddSelector: Selector = { by: 'css', value: '[data-test="add-to-cart-sauce-labs-bike-light"]' }
  const sauceDemoBackpackRemoveSelector: Selector = { by: 'css', value: '[data-test="remove-sauce-labs-backpack"]' }
  const sauceDemoBikeLightRemoveSelector: Selector = { by: 'css', value: '[data-test="remove-sauce-labs-bike-light"]' }
  const sauceDemoInventoryListSelector: Selector = { by: 'css', value: '[data-test="inventory-container"], .inventory_list' }
  const sauceDemoCartLinkSelector: Selector = { by: 'css', value: '[data-test="shopping-cart-link"]' }
  const sauceDemoSortSelector: Selector = { by: 'css', value: '[data-test="product-sort-container"]' }
  const sauceDemoBackToProductsSelector: Selector = { by: 'css', value: '[data-test="back-to-products"]' }
  const sauceDemoEmptyCartSelector: Selector = { by: 'css', value: '[data-test="empty-cart"]' }
  const sauceDemoProductTitleSelector = (slug: string): Selector => ({ by: 'css', value: `[data-test="item-${slug}-title"]` })

  const appendSauceDemoLogin = (): void => {
    steps.push({ action: 'goto', url: '/login' })
    steps.push({ action: 'wait_for_selector', selector: sauceDemoLoginSelector, state: 'visible', timeout_ms: 20000 })
    if (!identityInput.allow_empty) {
      steps.push({ action: 'fill', selector: selectorForInput(identityInput), input_key: 'email' })
    }
    if (!passwordInput.allow_empty) {
      steps.push({ action: 'fill', selector: selectorForInput(passwordInput), input_key: 'password' })
    }
    steps.push({ action: 'click', selector: actionSelectorForInput(input, 'auth_login') })
    steps.push({ action: 'wait_for_url', contains: '/inventory.html', timeout_ms: 20000 })
    steps.push({ action: 'wait_for_selector', selector: sauceDemoInventoryListSelector, state: 'visible', timeout_ms: 20000 })
  }

  if (isSauceDemoProtectedRouteCase(input)) {
    const protectedPath = sauceDemoProtectedPath(input)
    steps.push({ action: 'goto', url: protectedPath })
    steps.push({ action: 'wait_for_selector', selector: sauceDemoLoginSelector, state: 'visible', timeout_ms: 20000 })
    steps.push({ action: 'screenshot', name: 'protected-route-login-gate' })
    asserts.push({ type: 'expect_visible', selector: sauceDemoLoginSelector })
    asserts.push({ type: 'expect_visible', selector: { by: 'text', text: 'Swag Labs' } })
    return { steps, asserts, useAuth: false }
  }

  if (isSauceDemoAddToCartCase(input)) {
    appendSauceDemoLogin()
    const products = sauceDemoProductSlugs(input)
    for (const productSlug of products) {
      steps.push({
        action: 'click',
        selector: productSlug === 'sauce-labs-bike-light' ? sauceDemoBikeLightAddSelector : sauceDemoBackpackAddSelector,
      })
    }
    steps.push({ action: 'wait_for_selector', selector: sauceDemoCartBadgeSelector, state: 'visible', timeout_ms: 20000 })
    steps.push({ action: 'screenshot', name: 'cart-updated' })
    asserts.push({ type: 'expect_url_contains', value: '/inventory.html' })
    asserts.push({ type: 'expect_text_contains', selector: sauceDemoCartBadgeSelector, text: String(products.length) })
    for (const productSlug of products) {
      asserts.push({
        type: 'expect_visible',
        selector: productSlug === 'sauce-labs-bike-light' ? sauceDemoBikeLightRemoveSelector : sauceDemoBackpackRemoveSelector,
      })
    }
    return { steps, asserts, useAuth: false }
  }

  if (isSauceDemoInventoryRemovalCase(input)) {
    appendSauceDemoLogin()
    steps.push({ action: 'click', selector: sauceDemoBackpackAddSelector })
    steps.push({ action: 'wait_for_selector', selector: sauceDemoBackpackRemoveSelector, state: 'visible', timeout_ms: 20000 })
    steps.push({ action: 'click', selector: sauceDemoBackpackRemoveSelector })
    steps.push({ action: 'wait_for_selector', selector: sauceDemoBackpackAddSelector, state: 'visible', timeout_ms: 20000 })
    steps.push({ action: 'screenshot', name: 'inventory-item-removed' })
    asserts.push({ type: 'expect_visible', selector: sauceDemoBackpackAddSelector })
    asserts.push({ type: 'expect_text_contains', selector: sauceDemoBackpackAddSelector, text: 'Add to cart' })
    return { steps, asserts, useAuth: false }
  }

  if (isSauceDemoCartRemovalCase(input)) {
    appendSauceDemoLogin()
    steps.push({ action: 'click', selector: sauceDemoBackpackAddSelector })
    steps.push({ action: 'wait_for_selector', selector: sauceDemoCartBadgeSelector, state: 'visible', timeout_ms: 20000 })
    steps.push({ action: 'click', selector: sauceDemoCartLinkSelector })
    steps.push({ action: 'wait_for_url', contains: '/cart.html', timeout_ms: 20000 })
    steps.push({ action: 'wait_for_selector', selector: sauceDemoBackpackRemoveSelector, state: 'visible', timeout_ms: 20000 })
    steps.push({ action: 'click', selector: sauceDemoBackpackRemoveSelector })
    steps.push({ action: 'wait_for_selector', selector: sauceDemoEmptyCartSelector, state: 'visible', timeout_ms: 20000 })
    steps.push({ action: 'screenshot', name: 'cart-item-removed' })
    asserts.push({ type: 'expect_url_contains', value: '/cart.html' })
    asserts.push({ type: 'expect_text_contains', selector: { by: 'text', text: 'Your Cart' }, text: 'Your Cart' })
    asserts.push({ type: 'expect_visible', selector: sauceDemoEmptyCartSelector })
    return { steps, asserts, useAuth: false }
  }

  if (isSauceDemoCartRetentionCase(input)) {
    const productSlug = sauceDemoPrimaryProductSlug(input)
    appendSauceDemoLogin()
    steps.push({ action: 'click', selector: productSlug === 'sauce-labs-bike-light' ? sauceDemoBikeLightAddSelector : sauceDemoBackpackAddSelector })
    steps.push({ action: 'click', selector: sauceDemoCartLinkSelector })
    steps.push({ action: 'wait_for_url', contains: '/cart.html', timeout_ms: 20000 })
    steps.push({ action: 'wait_for_selector', selector: sauceDemoProductTitleSelector(productSlug), state: 'visible', timeout_ms: 20000 })
    steps.push({ action: 'screenshot', name: 'cart-retains-item' })
    asserts.push({ type: 'expect_url_contains', value: '/cart.html' })
    asserts.push({ type: 'expect_text_contains', selector: sauceDemoProductTitleSelector(productSlug), text: sauceDemoProductNameForSlug(productSlug) })
    return { steps, asserts, useAuth: false }
  }

  if (isSauceDemoProductDetailCase(input)) {
    const productSlug = sauceDemoPrimaryProductSlug(input)
    appendSauceDemoLogin()
    steps.push({ action: 'click', selector: sauceDemoProductTitleSelector(productSlug) })
    steps.push({ action: 'wait_for_url', contains: `/inventory-item.html?id=${productSlug}`, timeout_ms: 20000 })
    steps.push({ action: 'wait_for_selector', selector: sauceDemoBackToProductsSelector, state: 'visible', timeout_ms: 20000 })
    steps.push({ action: 'screenshot', name: 'product-detail-page' })
    asserts.push({ type: 'expect_url_contains', value: `/inventory-item.html?id=${productSlug}` })
    asserts.push({ type: 'expect_text_contains', selector: sauceDemoBackToProductsSelector, text: 'Back to products' })
    return { steps, asserts, useAuth: false }
  }

  if (isSauceDemoBackToProductsCase(input)) {
    const productSlug = sauceDemoPrimaryProductSlug(input)
    appendSauceDemoLogin()
    steps.push({ action: 'click', selector: sauceDemoProductTitleSelector(productSlug) })
    steps.push({ action: 'wait_for_url', contains: `/inventory-item.html?id=${productSlug}`, timeout_ms: 20000 })
    steps.push({ action: 'click', selector: sauceDemoBackToProductsSelector })
    steps.push({ action: 'wait_for_url', contains: '/inventory.html', timeout_ms: 20000 })
    steps.push({ action: 'wait_for_selector', selector: sauceDemoInventoryListSelector, state: 'visible', timeout_ms: 20000 })
    steps.push({ action: 'screenshot', name: 'back-to-products' })
    asserts.push({ type: 'expect_url_contains', value: '/inventory.html' })
    asserts.push({ type: 'expect_visible', selector: sauceDemoInventoryListSelector })
    asserts.push({ type: 'expect_text_contains', selector: { by: 'text', text: 'Products' }, text: 'Products' })
    return { steps, asserts, useAuth: false }
  }

  if (isSauceDemoSortingCase(input)) {
    const sortValue = sauceDemoSortValue(input)
    appendSauceDemoLogin()
    steps.push({ action: 'goto', url: `/inventory.html?sort=${sortValue}` })
    steps.push({ action: 'wait_for_selector', selector: sauceDemoSortSelector, state: 'visible', timeout_ms: 20000 })
    steps.push({ action: 'screenshot', name: `inventory-sort-${sortValue}` })
    asserts.push({ type: 'expect_url_contains', value: `/inventory.html?sort=${sortValue}` })
    asserts.push({ type: 'expect_text_contains', selector: sauceDemoSortSelector, text: sauceDemoSortLabel(sortValue) })
    return { steps, asserts, useAuth: false }
  }

  switch (coverageType) {
    case 'auth_login':
      steps.push({ action: 'wait_for_selector', selector: cssSelector('email'), state: 'visible', timeout_ms: 20000 })
      if (!identityInput.allow_empty) {
        steps.push({ action: 'fill', selector: selectorForInput(identityInput), input_key: 'email' })
      }
      if (!passwordInput.allow_empty) {
        steps.push({ action: 'fill', selector: selectorForInput(passwordInput), input_key: 'password' })
      }
      steps.push({ action: 'click', selector: actionSelectorForInput(input, 'auth_login') })
      if (!negativeCase) {
        steps.push({ action: 'wait_for_url', contains: isSauceDemoLogin ? '/inventory.html' : internalTarget ? '/dashboard' : new URL(normalizedBaseUrl).hostname, timeout_ms: 20000 })
        steps.push({ action: 'screenshot', name: 'post-login' })
        asserts.push({ type: 'expect_url_contains', value: isSauceDemoLogin ? '/inventory.html' : internalTarget ? '/dashboard' : new URL(normalizedBaseUrl).hostname })
        if (isSauceDemoLogin) {
          asserts.push({ type: 'expect_visible', selector: { by: 'text', text: 'Products' } })
        }
      } else {
        steps.push({ action: 'wait_for_selector', selector: alertSelector, state: 'visible', timeout_ms: 20000 })
        steps.push({ action: 'screenshot', name: 'login-error-state' })
        asserts.push({ type: 'expect_visible', selector: alertSelector })
        if (expectedFeedbackText) {
          asserts.push({ type: 'expect_text_contains', selector: alertSelector, text: expectedFeedbackText })
        }
      }
      break
    case 'form_interaction':
      for (const requiredInput of requiredInputs) {
        steps.push({ action: 'wait_for_selector', selector: selectorForInput(requiredInput), state: 'visible', timeout_ms: 15000 })
        if (requiredInput.kind === 'file') {
          steps.push({ action: 'set_file', selector: selectorForInput(requiredInput), input_key: requiredInput.key })
        } else {
          steps.push({ action: 'fill', selector: selectorForInput(requiredInput), input_key: requiredInput.key })
        }
      }
      steps.push({ action: 'click', selector: actionSelectorForInput(input, 'form_interaction') })
      steps.push({ action: 'wait_for_selector', selector: successSelector, state: 'visible', timeout_ms: 15000 })
      steps.push({ action: 'screenshot', name: 'form-submission-result' })
      asserts.push({ type: 'expect_visible', selector: successSelector })
      if (expectedFeedbackText) {
        asserts.push({ type: 'expect_text_contains', selector: successSelector, text: expectedFeedbackText })
      }
      break
    case 'validation':
      steps.push({ action: 'wait_for_selector', selector: { by: 'css', value: 'form, [role="form"]' }, state: 'visible', timeout_ms: 15000 })
      steps.push({ action: 'click', selector: actionSelectorForInput(input, 'validation') })
      steps.push({ action: 'wait_for_selector', selector: { by: 'css', value: '[role="alert"], .error, .invalid-feedback, .field-error, [aria-invalid="true"]' }, state: 'visible', timeout_ms: 15000 })
      steps.push({ action: 'screenshot', name: 'validation-feedback' })
      asserts.push({ type: 'expect_visible', selector: { by: 'css', value: '[role="alert"], .error, .invalid-feedback, .field-error, [aria-invalid="true"]' } })
      if (expectedFeedbackText) {
        asserts.push({ type: 'expect_text_contains', selector: alertSelector, text: expectedFeedbackText })
      }
      break
    case 'search_filter':
      steps.push({ action: 'wait_for_selector', selector: cssSelector('search'), state: 'visible', timeout_ms: 15000 })
      steps.push({ action: 'fill', selector: selectorForInput(requiredInputs.find((entry) => entry.key === 'search_query') ?? requiredInput('search_query', 'Search query', 'search', true, '')), input_key: 'search_query' })
      steps.push({ action: 'press', selector: cssSelector('search'), key: 'Enter' })
      steps.push({ action: 'wait_for_selector', selector: listSelector, state: 'attached', timeout_ms: 15000 })
      steps.push({ action: 'screenshot', name: 'search-results' })
      asserts.push({ type: 'expect_visible', selector: listSelector })
      break
    case 'table_listing':
      steps.push({ action: 'wait_for_selector', selector: listSelector, state: 'visible', timeout_ms: 15000 })
      steps.push({ action: 'screenshot', name: 'table-listing' })
      asserts.push({ type: 'expect_visible', selector: listSelector })
      break
    case 'upload':
      steps.push({ action: 'wait_for_selector', selector: cssSelector('file'), state: 'visible', timeout_ms: 15000 })
      steps.push({ action: 'set_file', selector: cssSelector('file'), input_key: 'upload_file' })
      steps.push({ action: 'click', selector: actionSelectorForInput(input, 'upload') })
      steps.push({ action: 'wait_for_selector', selector: successSelector, state: 'visible', timeout_ms: 15000 })
      steps.push({ action: 'screenshot', name: 'upload-result' })
      asserts.push({ type: 'expect_visible', selector: successSelector })
      if (expectedFeedbackText) {
        asserts.push({ type: 'expect_text_contains', selector: successSelector, text: expectedFeedbackText })
      }
      break
    case 'modal_dialog':
      steps.push({ action: 'click', selector: actionSelectorForInput(input, 'modal_dialog') })
      steps.push({ action: 'wait_for_selector', selector: { by: 'css', value: '[role="dialog"], .modal, .dialog, [aria-modal="true"]' }, state: 'visible', timeout_ms: 15000 })
      steps.push({ action: 'screenshot', name: 'modal-dialog' })
      asserts.push({ type: 'expect_visible', selector: { by: 'css', value: '[role="dialog"], .modal, .dialog, [aria-modal="true"]' } })
      break
    case 'navigation':
      steps.push({ action: 'click', selector: { by: 'css', value: 'nav a[href], header a[href], a[href]' } })
      steps.push({ action: 'wait_for_selector', selector: { by: 'css', value: 'body' }, state: 'visible', timeout_ms: 15000 })
      steps.push({ action: 'screenshot', name: 'navigation-result' })
      asserts.push({ type: 'expect_visible', selector: { by: 'css', value: 'body' } })
      break
    case 'button_action':
      steps.push({ action: 'click', selector: actionSelectorForInput(input, 'button_action') })
      steps.push({ action: 'wait_for_selector', selector: { by: 'css', value: '[role="alert"], .toast, .notification, body' }, state: 'visible', timeout_ms: 15000 })
      steps.push({ action: 'screenshot', name: 'button-action-result' })
      asserts.push({ type: 'expect_visible', selector: { by: 'css', value: '[role="alert"], .toast, .notification, body' } })
      break
    case 'redirection':
      steps.push({ action: 'click', selector: { by: 'css', value: 'a[href], button, [role="button"]' } })
      steps.push({ action: 'wait_for_url', contains: new URL(normalizedBaseUrl).hostname, timeout_ms: 20000 })
      steps.push({ action: 'screenshot', name: 'redirection-result' })
      asserts.push({ type: 'expect_url_contains', value: new URL(normalizedBaseUrl).hostname })
      break
    case 'feedback_message':
      steps.push({ action: 'click', selector: actionSelectorForInput(input, 'feedback_message') })
      steps.push({ action: 'wait_for_selector', selector: alertSelector, state: 'visible', timeout_ms: 15000 })
      steps.push({ action: 'screenshot', name: 'feedback-message' })
      asserts.push({ type: 'expect_visible', selector: alertSelector })
      if (expectedFeedbackText) {
        asserts.push({ type: 'expect_text_contains', selector: alertSelector, text: expectedFeedbackText })
      }
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
  const preflightChecks = buildPreflightChecks(input, coverageType, requiredInputs, scenario, internalTarget)
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

function buildLLMChecklistItem(input: GeneratorInput): GeneratorChecklistItem {
  return {
    external_id: input.external_id,
    test_case_title: input.test_case_title,
    test_case_description: input.test_case_description,
    test_case_text: input.test_case_text,
    base_url: input.base_url,
    use_auth: input.use_auth,
    environment_name: input.environment_name,
    notes: input.notes,
    priority: input.priority,
    criticality: input.criticality,
    current_status: input.current_status,
    target_type: input.target_type,
    source_app: input.source_app,
    provided_inputs: input.provided_inputs,
    expected_result: input.expected_result,
  }
}

function buildRunSpecFromGeneratedCase(
  input: GeneratorInput,
  runCase: z.infer<typeof RunSpecDslV1Schema>['cases'][number],
): z.infer<typeof RunSpecDslV1Schema> {
  return {
    schema_version: '1.0',
    run_id: input.run_id,
    target: {
      base_url: normalizeBaseUrl(input.base_url),
    },
    runtime: buildRuntimeConfig(input),
    cases: [runCase],
  }
}

function withGenerationMetadata(
  runSpec: z.infer<typeof RunSpecDslV1Schema>,
  metadata: GenerationMetadataDsl,
): z.infer<typeof RunSpecDslV1Schema> {
  return {
    ...runSpec,
    generation_metadata: metadata,
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
  const raw = (await fs.readFile(inputPath, 'utf8')).replace(/^\uFEFF/, '')
  const parsedInput = InputSchema.parse(JSON.parse(raw))

  const generationEngine = getGenerationEngine()
  process.stderr.write(`[agent] Generation engine: ${generationEngine}\n`)

  let runSpec: z.infer<typeof RunSpecDslV1Schema>

  if (generationEngine === 'openai') {
    const providerConfig = getOpenAIConfig()

    try {
      process.stderr.write(
        `[agent] LLM provider config: provider=openai model=${providerConfig.model} api_key_present=${providerConfig.apiKey !== '' ? 'yes' : 'no'}\n`,
      )

      const provider = createLLMProvider({
        provider: 'openai',
        config: providerConfig,
      })
      const generated = await generateRunCase(provider, buildLLMChecklistItem(parsedInput))
      process.stderr.write(
        `[llm-gen] provider=openai external_id=${parsedInput.external_id} duration_ms=${generated.durationMs} provider_retries=${generated.providerRetries} correction_retries=${generated.correctionRetries}\n`,
      )

      runSpec = withGenerationMetadata(
        buildRunSpecFromGeneratedCase(parsedInput, generated.runCase),
        {
          engine: 'openai',
          requested_engine: 'openai',
          model: generated.model,
          fallback_used: false,
        },
      )
    } catch (error) {
      throw error
    }
  } else {
    runSpec = buildRunSpecFromPlan(parsedInput)
  }

  const validatedRunSpec = RunSpecDslV1Schema.parse(runSpec)
  process.stdout.write(`${JSON.stringify(validatedRunSpec)}\n`)
}

main().catch((error) => {
  const message = error instanceof Error ? error.message : String(error)
  process.stderr.write(`[agent] generateRunSpec failed: ${message}\n`)
  process.exit(3)
})
