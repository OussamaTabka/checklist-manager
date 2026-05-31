export type HealPayload = {
  original_script: string
  test_case: {
    title: string
    description: string
    priority?: string
    criticality?: string
  }
  user_story: {
    reference: string
    title: string
    as_a: string
    i_want_that: string
    so_that: string
    business_rules: string
  }
  failure: {
    error_type: 'selector_not_found' | 'navigation_timeout' | 'assertion_failed'
    error_message: string
    last_action_attempted?: string
  }
  page_dom_snippet?: string
  screenshot_path?: string
  // run_context is written by PHP to healScript.js can build the full run-spec
  run_context: {
    run_id: string
    external_id: number
    base_url: string
    provided_inputs: Record<string, unknown>
    execution_profile: Record<string, unknown>
    human_readable_steps: string[]
  }
}

export const HEALER_SYSTEM_PROMPT = `You are a Playwright script healer. A previous Python Playwright script failed during execution. Your job is to diagnose why and produce a corrected script that addresses the specific failure WITHOUT changing the test objective.

YOU RECEIVE:
1. original_script — the Python script body that failed. It uses async functions and expects \`page\`, \`expect\`, and \`inputs\` to be in scope.
2. failure.error_type and failure.error_message — the runtime failure details.
3. test_case — title, description, priority, criticality. The description states what the test is supposed to verify.
4. user_story — the parent story (as_a, i_want_that, so_that, business_rules). business_rules is your source of truth for defaults and expected behaviors.
5. page_dom_snippet — optionally, a trimmed HTML snapshot of the page when failure occurred. Use it to find better selectors.

DIAGNOSIS RULES (apply the one matching error_type):

- selector_not_found → the selector did not match anything on the page. Inspect page_dom_snippet to find the real element. Prefer in this order: page.get_by_role > page.get_by_label > page.get_by_text > page.get_by_test_id > CSS selector. Never invent a selector that is not visible in the snippet.

- navigation_timeout → a wait_for_url or wait_for_selector exceeded its budget. Extend the affected timeout to at least 60000ms. If the global runtime timeout might also be a ceiling, surface that in the diagnosis.

- assertion_failed → an expect() call did not match. Re-read test_case.description and user_story.business_rules to determine whether: (a) the assertion targets the wrong element/text, or (b) the test polarity was misread (a negative test was treated as positive, or vice versa). Fix the assertion. Do NOT change the polarity itself.

INVARIANTS YOU MUST PRESERVE:
- The test objective stated in test_case.description
- The polarity of the test (positive = action succeeds; negative = action is correctly blocked)
- The credentials chosen by the original script, UNLESS the DOM clearly proves they were wrong for the scenario described in test_case.description

OUTPUT:
Return ONLY a JSON object, no markdown fences, no commentary outside the JSON:
{
  "diagnosis": "One sentence explaining what went wrong and how you are fixing it.",
  "python_script_body": "The corrected script body. Same async signature as the original. Uses page, expect, inputs."
}`

export function buildHealerPrompt(payload: HealPayload): string {
  const lines: string[] = [
    '=== FAILED TEST CASE ===',
    `test_case.title: ${payload.test_case.title}`,
    `test_case.description: ${payload.test_case.description}`,
    `test_case.priority: ${payload.test_case.priority ?? ''}`,
    `test_case.criticality: ${payload.test_case.criticality ?? ''}`,
    '',
    '=== USER STORY ===',
    `reference: ${payload.user_story.reference}`,
    `title: ${payload.user_story.title}`,
    `as_a: ${payload.user_story.as_a}`,
    `i_want_that: ${payload.user_story.i_want_that}`,
    `so_that: ${payload.user_story.so_that}`,
    `business_rules: ${payload.user_story.business_rules}`,
    '',
    '=== FAILURE ===',
    `error_type: ${payload.failure.error_type}`,
    `error_message: ${payload.failure.error_message}`,
  ]

  if (payload.failure.last_action_attempted) {
    lines.push(`last_action_attempted: ${payload.failure.last_action_attempted}`)
  }

  if (payload.page_dom_snippet) {
    lines.push('', '=== PAGE DOM SNIPPET ===', payload.page_dom_snippet.slice(0, 4000))
  }

  if (payload.screenshot_path) {
    lines.push('', `screenshot_path: ${payload.screenshot_path}`)
  }

  lines.push(
    '',
    '=== ORIGINAL SCRIPT (FAILED) ===',
    payload.original_script,
    '',
    'Return ONLY a JSON object with keys "diagnosis" and "python_script_body". No markdown. No explanation.',
  )

  return lines.join('\n')
}
