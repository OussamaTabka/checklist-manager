const test = require('node:test')
const assert = require('node:assert/strict')

test('resolveRequiredInputValue allows empty string when allow_empty is true', async () => {
  const { resolveRequiredInputValue } = require('../dist/engine/executor.js')

  const value = resolveRequiredInputValue(
    {
      external_id: 1,
      title: 'Missing username prevents login',
      use_auth: false,
      steps: [],
      asserts: [],
      execution_profile: {
        intent_summary: 'Negative login validation',
        coverage_type: 'auth_login',
        preconditions: [],
        required_inputs: [
          {
            key: 'email',
            label: 'Email or username',
            kind: 'email',
            required: true,
            allow_empty: true,
            value: '',
          },
        ],
        expected_observations: [],
        diagnostics: [],
      },
    },
    'email',
  )

  assert.equal(value, '')
})

test('resolveRequiredInputValue still rejects empty string when allow_empty is false', async () => {
  const { resolveRequiredInputValue } = require('../dist/engine/executor.js')

  assert.throws(
    () =>
      resolveRequiredInputValue(
        {
          external_id: 1,
          title: 'Missing username prevents login',
          use_auth: false,
          steps: [],
          asserts: [],
          execution_profile: {
            intent_summary: 'Negative login validation',
            coverage_type: 'auth_login',
            preconditions: [],
            required_inputs: [
              {
                key: 'email',
                label: 'Email or username',
                kind: 'email',
                required: true,
                value: '',
              },
            ],
            expected_observations: [],
            diagnostics: [],
          },
        },
        'email',
      ),
    /aucune valeur n'a ete fournie/i,
  )
})

test('navigation timeout is not normalized as url_unreachable', async () => {
  const { normalizeError } = require('../dist/engine/results.js')

  const normalized = normalizeError(new Error('page.waitForURL: Timeout 20000ms exceeded while waiting for navigation'))

  assert.equal(normalized.error_type, 'navigation_timeout')
})

test('only initial navigation errors produce url_unreachable', async () => {
  const { normalizeError, InitialNavigationError } = require('../dist/engine/results.js')

  const initial = normalizeError(new InitialNavigationError('The target URL could not be reached by Playwright. Details: net::ERR_NAME_NOT_RESOLVED'))
  const later = normalizeError(new Error('net::ERR_ABORTED; waiting for selector after click'))

  assert.equal(initial.error_type, 'url_unreachable')
  assert.notEqual(later.error_type, 'url_unreachable')
})
