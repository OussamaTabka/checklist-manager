import { z } from 'zod'
import { RunCaseDslSchema } from '../dslSchema.js'

const RUN_CASE_SCHEMA_JSON = JSON.stringify(z.toJSONSchema(RunCaseDslSchema), null, 2)

export type GeneratorChecklistItem = {
  external_id: number
  test_case_title: string
  test_case_description?: string
  test_case_text: string
  base_url: string
  use_auth?: boolean
  environment_name?: string
  notes?: string
  priority?: string
  criticality?: string
  current_status?: string
  target_type?: string
  source_app?: string
  provided_inputs?: Record<string, unknown>
  expected_result?: Record<string, unknown>
}

function safeJson(value: unknown): string {
  return JSON.stringify(value ?? {}, null, 2)
}

function legalVocabularySection(): string {
  return [
    'Preferred selector strategy:',
    '- Prefer testid when a stable test id actually exists.',
    '- Otherwise prefer role selectors for interactive controls and obvious landmarks.',
    '- Then prefer label selectors when the element has a visible accessible label.',
    '- Then prefer text selectors for stable visible copy.',
    '- Use css only as a last resort when no better selector exists.',
    '',
    'Legal selector variants:',
    '- {"by":"testid","id":"..."}',
    '- {"by":"role","role":"...","name":"...","exact":true|false}',
    '- {"by":"label","text":"..."}',
    '- {"by":"text","text":"...","exact":true|false}',
    '- {"by":"css","value":"..."}',
    '',
    'Legal step action values:',
    '- goto',
    '- click',
    '- fill',
    '- press',
    '- wait_for_url',
    '- wait_for_selector',
    '- screenshot',
    '- set_file',
    '',
    'Legal assert type values:',
    '- expect_visible',
    '- expect_hidden',
    '- expect_text',
    '- expect_text_contains',
    '- expect_url_contains',
    '- expect_title',
    '',
    'Supported scenario families to reason about:',
    '- authentication',
    '- authorization_access_control',
    '- form_required_fields',
    '- form_validation_error',
    '- form_success_submission',
    '- search_functionality',
    '- filter_functionality',
    '- sorting_functionality',
    '- pagination_functionality',
    '- file_upload_validation',
    '- file_download_export',
    '- modal_dialog_behavior',
    '- notification_feedback',
    '- error_page_handling',
    '- navigation_routing',
    '- payment_checkout',
    '- responsive_layout',
    '- table_data_display',
    '- loading_state_behavior',
    '',
    'Preflight mindset:',
    '- Verify the target page is reachable before deeper interaction.',
    '- Verify the expected form, search field, button, table, modal, or upload area exists before using it.',
    '- If critical UI is missing, produce a conservative step/assert sequence that surfaces the missing state instead of inventing interactions.',
    '',
    'Execution profile rules:',
    '- execution_profile.intent_summary is required',
    '- execution_profile.coverage_type is required',
    '- execution_profile.expected_observations is required',
    '- Do not emit execution_profile.required_inputs',
    '- Do not emit execution_profile.diagnostics',
    '- Do not emit execution_profile.generation_confidence',
    '- Do not emit execution_profile.last_generated_plan',
    '',
    'Forbidden fields:',
    '- Do not emit auth',
    '- Do not emit schema_version',
    '- Do not emit run_id',
    '- Do not emit target',
    '- Do not emit runtime',
    '- Do not emit generated_plan',
    '- Do not emit preflight_checks',
  ].join('\n')
}

function fewShotExamples(): string {
  const example1Output = safeJson({
    external_id: 101,
    title: 'User can log in with valid credentials',
    use_auth: false,
    execution_profile: {
      intent_summary: 'Verify that a valid user can sign in successfully.',
      coverage_type: 'happy_path',
      expected_observations: [
        'The login form accepts the provided credentials.',
        'The user is redirected to the inventory page.',
        'The inventory page shows logged-in content.',
      ],
    },
    steps: [
      { action: 'goto', url: 'https://www.saucedemo.com/' },
      { action: 'wait_for_selector', selector: { by: 'role', role: 'textbox', name: 'Username' }, state: 'visible', timeout_ms: 15000 },
      { action: 'fill', selector: { by: 'role', role: 'textbox', name: 'Username' }, input_key: 'email' },
      { action: 'fill', selector: { by: 'role', role: 'textbox', name: 'Password' }, input_key: 'password' },
      { action: 'click', selector: { by: 'role', role: 'button', name: 'Login' } },
      { action: 'wait_for_url', contains: '/inventory.html', timeout_ms: 20000 },
      { action: 'screenshot', name: 'post-login' },
    ],
    asserts: [
      { type: 'expect_url_contains', value: '/inventory.html' },
      { type: 'expect_visible', selector: { by: 'text', text: 'Products' } },
    ],
  })

  const example2Output = safeJson({
    external_id: 102,
    title: 'User sees validation message when password is missing',
    use_auth: false,
    execution_profile: {
      intent_summary: 'Verify that the login form rejects submission when the password is missing.',
      coverage_type: 'negative',
      expected_observations: [
        'The username field accepts the provided username.',
        'Submitting without a password does not log the user in.',
        'An error message becomes visible.',
      ],
    },
    steps: [
      { action: 'goto', url: 'https://www.saucedemo.com/' },
      { action: 'wait_for_selector', selector: { by: 'role', role: 'textbox', name: 'Username' }, state: 'visible', timeout_ms: 15000 },
      { action: 'fill', selector: { by: 'role', role: 'textbox', name: 'Username' }, input_key: 'email' },
      { action: 'click', selector: { by: 'role', role: 'button', name: 'Login' } },
      { action: 'wait_for_selector', selector: { by: 'css', value: '[data-test="error"]' }, state: 'visible', timeout_ms: 15000 },
      { action: 'screenshot', name: 'missing-password-error' },
    ],
    asserts: [
      { type: 'expect_visible', selector: { by: 'css', value: '[data-test="error"]' } },
      { type: 'expect_text_contains', selector: { by: 'css', value: '[data-test="error"]' }, text: 'Password is required' },
    ],
  })

  const example3Output = safeJson({
    external_id: 103,
    title: 'User can add a product to cart',
    use_auth: false,
    execution_profile: {
      intent_summary: 'Verify that a logged-in user can add a product to the cart.',
      coverage_type: 'happy_path',
      expected_observations: [
        'The user reaches the inventory page after login.',
        'The selected product can be added to the cart.',
        'The cart badge updates to reflect one item.',
      ],
    },
    steps: [
      { action: 'goto', url: 'https://www.saucedemo.com/' },
      { action: 'wait_for_selector', selector: { by: 'role', role: 'textbox', name: 'Username' }, state: 'visible', timeout_ms: 15000 },
      { action: 'fill', selector: { by: 'role', role: 'textbox', name: 'Username' }, input_key: 'email' },
      { action: 'fill', selector: { by: 'role', role: 'textbox', name: 'Password' }, input_key: 'password' },
      { action: 'click', selector: { by: 'role', role: 'button', name: 'Login' } },
      { action: 'wait_for_url', contains: '/inventory.html', timeout_ms: 20000 },
      { action: 'click', selector: { by: 'role', role: 'button', name: 'Add to cart' } },
      { action: 'wait_for_selector', selector: { by: 'css', value: '.shopping_cart_badge' }, state: 'visible', timeout_ms: 15000 },
      { action: 'screenshot', name: 'cart-badge-updated' },
    ],
    asserts: [
      { type: 'expect_visible', selector: { by: 'css', value: '.shopping_cart_badge' } },
      { type: 'expect_text', selector: { by: 'css', value: '.shopping_cart_badge' }, text: '1' },
    ],
  })

  return [
    'Few-shot examples',
    '',
    'Example 1 input checklist item:',
    'test_case_title: User can log in with valid credentials',
    'test_case_description: Verify that a registered user can sign in and land on the inventory page.',
    'base_url: https://www.saucedemo.com',
    'notes: Use provided email and password inputs.',
    'provided_inputs:',
    '```json',
    safeJson({
      email: 'standard_user',
      password: 'secret_sauce',
    }),
    '```',
    '',
    'Example 1 output JSON:',
    '```json',
    example1Output,
    '```',
    '',
    'Example 2 input checklist item:',
    'test_case_title: User sees validation message when password is missing',
    'test_case_description: Verify the login form blocks submission and shows an error when the password field is empty.',
    'base_url: https://www.saucedemo.com',
    'provided_inputs:',
    '```json',
    safeJson({
      email: 'standard_user',
      password: '',
    }),
    '```',
    '',
    'Note for Example 2: the runner uses Playwright getByTestId(), which targets the default data-testid attribute. Sauce Demo uses [data-test="error"], so css is the correct fallback here.',
    '',
    'Example 2 output JSON:',
    '```json',
    example2Output,
    '```',
    '',
    'Example 3 input checklist item:',
    'test_case_title: User can add a product to cart',
    'test_case_description: Verify that clicking Add to cart on a product adds it to the cart and updates the badge.',
    'base_url: https://www.saucedemo.com',
    'provided_inputs:',
    '```json',
    safeJson({
      email: 'standard_user',
      password: 'secret_sauce',
      product: 'Sauce Labs Backpack',
    }),
    '```',
    '',
    'Note for Example 3: .shopping_cart_badge has no stable role, label, text, or test id, so css is justified as a last resort.',
    '',
    'Example 3 output JSON:',
    '```json',
    example3Output,
    '```',
  ].join('\n')
}

export function buildGeneratorPrompt(checklistItem: GeneratorChecklistItem): string {
  return [
    'You are a senior QA automation engineer.',
    'Produce exactly one JSON object representing a single RunCaseDsl test case.',
    'Return JSON only.',
    '',
    'Generate one focused UI test case for the checklist item provided.',
    'Do not fan out into multiple scenarios.',
    '',
    'Your output must be valid JSON and must match the schema constraints described below.',
    'If a field is optional and not needed, omit it.',
    'Do not invent top-level fields outside the schema.',
    '',
    legalVocabularySection(),
    '',
    'Strict RunCaseDsl schema reference:',
    '```json',
    RUN_CASE_SCHEMA_JSON,
    '```',
    '',
    fewShotExamples(),
    '',
    // TODO: refine few-shot guidance so ambiguous role selectors like "Add to cart" are disambiguated more explicitly.
    'Checklist item to convert:',
    `external_id: ${checklistItem.external_id}`,
    `test_case_title: ${checklistItem.test_case_title}`,
    `test_case_description: ${checklistItem.test_case_description ?? ''}`,
    `test_case_text: ${checklistItem.test_case_text}`,
    `base_url: ${checklistItem.base_url}`,
    `use_auth: ${String(checklistItem.use_auth ?? false)}`,
    `environment_name: ${checklistItem.environment_name ?? ''}`,
    `notes: ${checklistItem.notes ?? ''}`,
    `priority: ${checklistItem.priority ?? ''}`,
    `criticality: ${checklistItem.criticality ?? ''}`,
    `current_status: ${checklistItem.current_status ?? ''}`,
    `target_type: ${checklistItem.target_type ?? ''}`,
    `source_app: ${checklistItem.source_app ?? ''}`,
    'provided_inputs:',
    '```json',
    safeJson(checklistItem.provided_inputs ?? {}),
    '```',
    'expected_result:',
    '```json',
    safeJson(checklistItem.expected_result ?? {}),
    '```',
    '',
    'Output rules:',
    '- external_id must match the provided external_id exactly as a number.',
    '- execution_profile must include intent_summary, coverage_type, and expected_observations.',
    '- expected_observations must be human-readable and concrete.',
    '- steps must be executable with the allowed action vocabulary only.',
    '- asserts must verify the intended outcome with the allowed assert vocabulary only.',
    '- use_auth should normally be false when the test performs login in its own steps.',
    '- The deterministic caller will assign severity after parsing based on criticality, so do not optimize your reasoning around severity selection.',
  ].join('\n')
}
