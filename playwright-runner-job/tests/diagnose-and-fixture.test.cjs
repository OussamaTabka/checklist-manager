const test = require('node:test')
const assert = require('node:assert/strict')
const { spawn } = require('node:child_process')
const path = require('node:path')
const fs = require('node:fs/promises')
const os = require('node:os')
const { chromium } = require('playwright')
const { classifyError } = require('../scripts/diagnose-url.cjs')

const fixtureScript = path.join(__dirname, '..', 'fixtures', 'sauce-demo-lite', 'server.cjs')
let fixtureProcess = null

async function ensureFixtureStarted() {
  if (fixtureProcess) {
    return
  }

  fixtureProcess = spawn(process.execPath, [fixtureScript], {
    cwd: path.join(__dirname, '..'),
    env: {
      ...process.env,
      BENCHMARK_FIXTURE_HOST: '127.0.0.1',
      BENCHMARK_FIXTURE_PORT: '4177',
    },
    stdio: ['ignore', 'pipe', 'pipe'],
  })

  await new Promise((resolve, reject) => {
    const timeout = setTimeout(() => reject(new Error('fixture start timeout')), 10000)

    fixtureProcess.stdout.on('data', (chunk) => {
      if (chunk.toString().includes('listening on')) {
        clearTimeout(timeout)
        resolve()
      }
    })

    fixtureProcess.on('exit', (code) => {
      clearTimeout(timeout)
      reject(new Error(`fixture exited early with code ${code}`))
    })
  })
}

test.after(async () => {
  if (fixtureProcess) {
    fixtureProcess.kill()
    fixtureProcess = null
  }
})

test('diagnose script classifies network access denied', () => {
  assert.equal(classifyError('page.goto: net::ERR_NETWORK_ACCESS_DENIED at https://www.saucedemo.com/'), 'network_access_denied')
})

test('local fixture GET / returns login page', async () => {
  await ensureFixtureStarted()
  const browser = await chromium.launch({ headless: true })
  const page = await browser.newPage()

  try {
    await page.goto('http://127.0.0.1:4177/', { waitUntil: 'domcontentloaded' })
    await page.locator('input[data-test="username"]').waitFor()
    await page.locator('input[data-test="password"]').waitFor()
    await page.locator('button[data-test="login-button"]').waitFor()
    assert.match(String(await page.textContent('h1.login_logo')), /swag labs/i)
  } finally {
    await browser.close()
  }
})

test('local fixture GET /login returns login page', async () => {
  await ensureFixtureStarted()
  const browser = await chromium.launch({ headless: true })
  const page = await browser.newPage()

  try {
    await page.goto('http://127.0.0.1:4177/login', { waitUntil: 'domcontentloaded' })
    await page.locator('input[data-test="username"]').waitFor()
    await page.locator('input[data-test="password"]').waitFor()
    await page.locator('button[data-test="login-button"]').waitFor()
    assert.match(String(await page.textContent('h1.login_logo')), /swag labs/i)
  } finally {
    await browser.close()
  }
})

test('local fixture valid login reaches /inventory.html', async () => {
  await ensureFixtureStarted()
  const browser = await chromium.launch({ headless: true })
  const page = await browser.newPage()

  try {
    await page.goto('http://127.0.0.1:4177/login', { waitUntil: 'domcontentloaded' })
    await page.locator('input[data-test="username"]').fill('standard_user')
    await page.locator('input[data-test="password"]').fill('secret_sauce')
    await page.locator('button[data-test="login-button"]').click()
    await page.waitForURL('**/inventory.html')
    await page.getByText('Products').waitFor()
    await page.getByText('Sauce Labs Backpack').waitFor()
    await page.locator('[data-test="inventory-container"]').waitFor()
    assert.match(page.url(), /inventory\.html/)
  } finally {
    await browser.close()
  }
})

test('local fixture locked out user displays locked out error', async () => {
  await ensureFixtureStarted()
  const browser = await chromium.launch({ headless: true })
  const page = await browser.newPage()

  try {
    await page.goto('http://127.0.0.1:4177/login', { waitUntil: 'domcontentloaded' })
    await page.locator('input[data-test="username"]').fill('locked_out_user')
    await page.locator('input[data-test="password"]').fill('secret_sauce')
    await page.locator('button[data-test="login-button"]').click()
    const errorText = await page.locator('h3[data-test="error"]').textContent()
    assert.match(String(errorText), /locked out/i)
  } finally {
    await browser.close()
  }
})

test('local fixture missing username displays Username is required', async () => {
  await ensureFixtureStarted()
  const browser = await chromium.launch({ headless: true })
  const page = await browser.newPage()

  try {
    await page.goto('http://127.0.0.1:4177/login', { waitUntil: 'domcontentloaded' })
    await page.locator('input[data-test="password"]').fill('secret_sauce')
    await page.locator('button[data-test="login-button"]').click()
    const errorText = await page.locator('h3[data-test="error"]').textContent()
    assert.match(String(errorText), /username is required/i)
  } finally {
    await browser.close()
  }
})

test('local fixture missing password displays Password is required', async () => {
  await ensureFixtureStarted()
  const browser = await chromium.launch({ headless: true })
  const page = await browser.newPage()

  try {
    await page.goto('http://127.0.0.1:4177/login', { waitUntil: 'domcontentloaded' })
    await page.locator('input[data-test="username"]').fill('standard_user')
    await page.locator('button[data-test="login-button"]').click()
    const errorText = await page.locator('h3[data-test="error"]').textContent()
    assert.match(String(errorText), /password is required/i)
  } finally {
    await browser.close()
  }
})

test('local fixture invalid password shows login error', async () => {
  await ensureFixtureStarted()
  const browser = await chromium.launch({ headless: true })
  const page = await browser.newPage()

  try {
    await page.goto('http://127.0.0.1:4177/login', { waitUntil: 'domcontentloaded' })
    await page.locator('input[data-test="username"]').fill('standard_user')
    await page.locator('input[data-test="password"]').fill('wrong_password')
    await page.locator('button[data-test="login-button"]').click()
    const errorText = await page.locator('h3[data-test="error"]').textContent()
    assert.match(String(errorText), /do not match/i)
  } finally {
    await browser.close()
  }
})

test('local fixture invalid username shows login error', async () => {
  await ensureFixtureStarted()
  const browser = await chromium.launch({ headless: true })
  const page = await browser.newPage()

  try {
    await page.goto('http://127.0.0.1:4177/login', { waitUntil: 'domcontentloaded' })
    await page.locator('input[data-test="username"]').fill('invalid_user')
    await page.locator('input[data-test="password"]').fill('secret_sauce')
    await page.locator('button[data-test="login-button"]').click()
    const errorText = await page.locator('h3[data-test="error"]').textContent()
    assert.match(String(errorText), /do not match/i)
  } finally {
    await browser.close()
  }
})

test('direct inventory route without login redirects to login form', async () => {
  await ensureFixtureStarted()
  const browser = await chromium.launch({ headless: true })
  const page = await browser.newPage()

  try {
    await page.goto('http://127.0.0.1:4177/inventory.html', { waitUntil: 'domcontentloaded' })
    await page.locator('input[data-test="username"]').waitFor()
    assert.match(page.url(), /\/login$/)
  } finally {
    await browser.close()
  }
})

test('direct cart route without login redirects to login form', async () => {
  await ensureFixtureStarted()
  const browser = await chromium.launch({ headless: true })
  const page = await browser.newPage()

  try {
    await page.goto('http://127.0.0.1:4177/cart.html', { waitUntil: 'domcontentloaded' })
    await page.locator('input[data-test="username"]').waitFor()
    assert.match(page.url(), /\/login$/)
  } finally {
    await browser.close()
  }
})

test('after valid login adding backpack shows cart badge 1', async () => {
  await ensureFixtureStarted()
  const browser = await chromium.launch({ headless: true })
  const page = await browser.newPage()

  try {
    await page.goto('http://127.0.0.1:4177/login', { waitUntil: 'domcontentloaded' })
    await page.locator('input[data-test="username"]').fill('standard_user')
    await page.locator('input[data-test="password"]').fill('secret_sauce')
    await page.locator('button[data-test="login-button"]').click()
    await page.waitForURL('**/inventory.html')
    await page.locator('[data-test="add-to-cart-sauce-labs-backpack"]').click()
    await page.locator('[data-test="shopping-cart-badge"]').waitFor()
    await page.locator('[data-test="remove-sauce-labs-backpack"]').waitFor()
    assert.equal(await page.locator('[data-test="shopping-cart-badge"]').textContent(), '1')
  } finally {
    await browser.close()
  }
})

test('adding two products shows cart badge 2', async () => {
  await ensureFixtureStarted()
  const browser = await chromium.launch({ headless: true })
  const page = await browser.newPage()

  try {
    await page.goto('http://127.0.0.1:4177/login', { waitUntil: 'domcontentloaded' })
    await page.locator('input[data-test="username"]').fill('standard_user')
    await page.locator('input[data-test="password"]').fill('secret_sauce')
    await page.locator('button[data-test="login-button"]').click()
    await page.waitForURL('**/inventory.html')
    await page.locator('[data-test="add-to-cart-sauce-labs-backpack"]').click()
    await page.locator('[data-test="add-to-cart-sauce-labs-bike-light"]').click()
    assert.equal(await page.locator('[data-test="shopping-cart-badge"]').textContent(), '2')
  } finally {
    await browser.close()
  }
})

test('removing a product clears the cart badge when the cart becomes empty', async () => {
  await ensureFixtureStarted()
  const browser = await chromium.launch({ headless: true })
  const page = await browser.newPage()

  try {
    await page.goto('http://127.0.0.1:4177/login', { waitUntil: 'domcontentloaded' })
    await page.locator('input[data-test="username"]').fill('standard_user')
    await page.locator('input[data-test="password"]').fill('secret_sauce')
    await page.locator('button[data-test="login-button"]').click()
    await page.waitForURL('**/inventory.html')
    await page.locator('[data-test="add-to-cart-sauce-labs-backpack"]').click()
    await page.locator('[data-test="remove-sauce-labs-backpack"]').click()
    await page.locator('[data-test="add-to-cart-sauce-labs-backpack"]').waitFor()
    assert.equal(await page.locator('[data-test="shopping-cart-badge"]').count(), 0)
  } finally {
    await browser.close()
  }
})

test('cart retains added item after navigation', async () => {
  await ensureFixtureStarted()
  const browser = await chromium.launch({ headless: true })
  const page = await browser.newPage()

  try {
    await page.goto('http://127.0.0.1:4177/login', { waitUntil: 'domcontentloaded' })
    await page.locator('input[data-test="username"]').fill('standard_user')
    await page.locator('input[data-test="password"]').fill('secret_sauce')
    await page.locator('button[data-test="login-button"]').click()
    await page.waitForURL('**/inventory.html')
    await page.locator('[data-test="add-to-cart-sauce-labs-backpack"]').click()
    await page.locator('[data-test="shopping-cart-link"]').click()
    await page.waitForURL('**/cart.html')
    await page.locator('[data-test="item-sauce-labs-backpack-title"]').waitFor()
    assert.match(String(await page.textContent('[data-test="item-sauce-labs-backpack-title"]')), /sauce labs backpack/i)
  } finally {
    await browser.close()
  }
})

test('product detail page opens from inventory and back to products returns the list', async () => {
  await ensureFixtureStarted()
  const browser = await chromium.launch({ headless: true })
  const page = await browser.newPage()

  try {
    await page.goto('http://127.0.0.1:4177/login', { waitUntil: 'domcontentloaded' })
    await page.locator('input[data-test="username"]').fill('standard_user')
    await page.locator('input[data-test="password"]').fill('secret_sauce')
    await page.locator('button[data-test="login-button"]').click()
    await page.waitForURL('**/inventory.html')
    await page.locator('[data-test="item-sauce-labs-backpack-title"]').click()
    await page.waitForURL('**/inventory-item.html**')
    await page.locator('[data-test="back-to-products"]').waitFor()
    await page.locator('[data-test="back-to-products"]').click()
    await page.waitForURL('**/inventory.html')
    await page.locator('[data-test="inventory-container"]').waitFor()
  } finally {
    await browser.close()
  }
})

test('sort name A-Z, Z-A, and price low-high update the visible sort label', async () => {
  await ensureFixtureStarted()
  const browser = await chromium.launch({ headless: true })
  const page = await browser.newPage()

  try {
    await page.goto('http://127.0.0.1:4177/login', { waitUntil: 'domcontentloaded' })
    await page.locator('input[data-test="username"]').fill('standard_user')
    await page.locator('input[data-test="password"]').fill('secret_sauce')
    await page.locator('button[data-test="login-button"]').click()
    await page.waitForURL('**/inventory.html')

    await page.goto('http://127.0.0.1:4177/inventory.html?sort=az', { waitUntil: 'domcontentloaded' })
    assert.match(String(await page.textContent('[data-test="product-sort-container"]')), /name \(a to z\)/i)

    await page.goto('http://127.0.0.1:4177/inventory.html?sort=za', { waitUntil: 'domcontentloaded' })
    assert.match(String(await page.textContent('[data-test="product-sort-container"]')), /name \(z to a\)/i)

    await page.goto('http://127.0.0.1:4177/inventory.html?sort=lohi', { waitUntil: 'domcontentloaded' })
    assert.match(String(await page.textContent('[data-test="product-sort-container"]')), /price \(low to high\)/i)
  } finally {
    await browser.close()
  }
})

test('runner-generated valid login plan passes against sauce-demo-lite', async () => {
  await ensureFixtureStarted()

  const tempDir = await fs.mkdtemp(path.join(os.tmpdir(), 'sauce-demo-lite-run-'))
  const inputPath = path.join(tempDir, 'input.json')

  await fs.writeFile(
    inputPath,
    JSON.stringify(
      {
        run_id: 'fixture-valid-login',
        external_id: 179,
        test_case_title: 'Valid user can login',
        test_case_description: 'Verify the standard user can login successfully and reach the inventory page.',
        test_case_text: 'Valid user can login\nVerify the standard user can login successfully and reach the inventory page.',
        base_url: 'http://127.0.0.1:4177',
        target_type: 'checklist_item',
        source_app: 'sauce_demo',
        use_auth: false,
        provided_inputs: {
          username: 'standard_user',
          email: 'standard_user',
          password: 'secret_sauce',
        },
        expected_result: {
          expected_status: 'passed',
          assertion_keywords: ['products'],
        },
      },
      null,
      2,
    ),
    'utf8',
  )

  try {
    const runSpec = await new Promise((resolve, reject) => {
      const child = spawn(process.execPath, [path.join(__dirname, '..', '..', 'playwright-agent', 'src', 'generateRunSpec.js'), '--input', inputPath], {
        cwd: path.join(__dirname, '..'),
        stdio: ['ignore', 'pipe', 'pipe'],
      })

      let stdout = ''
      let stderr = ''

      child.stdout.on('data', (chunk) => {
        stdout += chunk.toString()
      })

      child.stderr.on('data', (chunk) => {
        stderr += chunk.toString()
      })

      child.on('exit', (code) => {
        if (code !== 0) {
          reject(new Error(`generateRunSpec failed with code ${code}: ${stderr}`))
          return
        }

        try {
          resolve(JSON.parse(stdout))
        } catch (error) {
          reject(error)
        }
      })
    })

    const validCase = runSpec.cases[0]
    assert.equal(validCase.steps.some((step) => step.action === 'wait_for_url' && step.contains === '/inventory.html'), true)
    assert.equal(validCase.steps.some((step) => step.action === 'wait_for_url' && step.contains === '/dashboard'), false)
    assert.equal(validCase.asserts.some((item) => item.type === 'expect_visible' && item.selector?.by === 'text' && item.selector?.text === 'Products'), true)

    const { executeRun } = require('../dist/engine/executor.js')
    const runResult = await executeRun(runSpec, {
      artifactsConfig: {
        workRoot: tempDir,
        artifactsRoot: path.join(tempDir, 'artifacts'),
      },
    })

    assert.equal(runResult.summary.failed, 0)
    assert.equal(runResult.results[0].status, 'passed')
    assert.match(JSON.stringify(runResult.results[0].execution_trace ?? []), /inventory\.html/i)
  } finally {
    await fs.rm(tempDir, { recursive: true, force: true })
  }
})
