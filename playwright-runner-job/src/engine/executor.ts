import { promises as fs } from 'node:fs'
import path from 'node:path'
import {
  chromium,
  firefox,
  webkit,
  type Browser,
  type BrowserType,
  type BrowserContext,
  type LaunchOptions,
  type Page,
  type Video,
} from 'playwright'
import type {
  AssertDsl,
  RunBrowserTarget,
  RunAuthApiDsl,
  RunCaseDsl,
  RunRequestDslV1,
  SelectorDsl,
  StepDsl,
} from './dsl'
import {
  deleteIfExists,
  ensureCaseArtifactsDir,
  ensureDir,
  moveOrCopyFile,
  sanitizeFilename,
  toRelativeWorkPath,
  type ArtifactsConfig,
} from './artifacts'
import {
  AmbiguousTargetError,
  AssertionFailureError,
  InitialNavigationError,
  InputDataMissingError,
  MissingEnvVarError,
  normalizeError,
  PreconditionFailureError,
  summarizeResults,
  UnsupportedTestCaseError,
  type CaseArtifacts,
  type CaseResultV1,
  type CaseStatus,
  type RunResultV1,
} from './results'
import { selectorDebugText, selectorToLocator } from './selectors'

interface ExecuteRunOptions {
  artifactsConfig: ArtifactsConfig
  liveTracePath?: string
}

interface LiveTraceCaseState {
  external_id: number
  title: string
  status: 'queued' | 'running' | CaseStatus
  execution_trace: string[]
}

interface LiveTraceState {
  schema_version: '1.0'
  run_id: string
  status: 'running' | 'done' | 'runner_error'
  updated_at: string
  error_message?: string
  cases: LiveTraceCaseState[]
}

interface BrowserCaseResult {
  browser: RunBrowserTarget
  result: CaseResultV1
}

const ENV_VAR_PATTERN = /\$\{([A-Z0-9_]+)\}/g
const UNKNOWN_SCENARIO_GUARD_TOKEN = '__agent_unknown_scenario__'
const RUNNER_DEBUG_ENABLED = ['1', 'true', 'yes', 'on'].includes((process.env.AGENT_RUNNER_DEBUG ?? '').trim().toLowerCase())

function sleep(ms: number): Promise<void> {
  if (ms <= 0) {
    return Promise.resolve()
  }

  return new Promise((resolve) => setTimeout(resolve, ms))
}

async function flushLiveTrace(liveTracePath: string | undefined, state: LiveTraceState | null): Promise<void> {
  if (!liveTracePath || !state) {
    return
  }

  await ensureDir(path.dirname(liveTracePath))
  await fs.writeFile(liveTracePath, `${JSON.stringify(state, null, 2)}\n`, 'utf8')
}

function resolveTemplateValue(value: string): string {
  return value.replace(ENV_VAR_PATTERN, (_, varName: string) => {
    const envValue = process.env[varName]
    if (envValue === undefined) {
      throw new MissingEnvVarError(varName)
    }
    return envValue
  })
}

function resolveUrl(baseUrl: string, inputUrl: string): string {
  const resolved = resolveTemplateValue(inputUrl)
  if (resolved.startsWith('http://') || resolved.startsWith('https://')) {
    return resolved
  }
  return new URL(resolved, baseUrl).toString()
}

function getRequiredEnv(name: string): string {
  const value = process.env[name]
  if (!value || value.trim() === '') {
    throw new MissingEnvVarError(name)
  }
  return value
}

function tryExtractJsonBody(payload: unknown): Record<string, unknown> | null {
  if (typeof payload !== 'object' || payload === null || Array.isArray(payload)) {
    return null
  }

  return payload as Record<string, unknown>
}

async function createApiAuthStorageState(
  browser: Browser,
  request: RunRequestDslV1,
  auth: RunAuthApiDsl,
  timeoutMs: number,
  storageStatePath: string,
): Promise<void> {
  const setupContext = await browser.newContext({
    viewport: request.runtime.viewport,
  })

  try {
    const username = getRequiredEnv(auth.username_env)
    const password = getRequiredEnv(auth.password_env)

    const csrfUrl = resolveUrl(request.target.base_url, auth.csrf_cookie_url)
    const loginUrl = resolveUrl(request.target.base_url, auth.login_url)
    const verifyUrl = auth.verify_url ? resolveUrl(request.target.base_url, auth.verify_url) : null

    const defaultHeaders = {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    }

    const csrfResponse = await setupContext.request.get(csrfUrl, {
      timeout: timeoutMs,
      failOnStatusCode: false,
      headers: defaultHeaders,
    })

    if (csrfResponse.status() < 200 || csrfResponse.status() >= 300) {
      throw new Error(`CSRF bootstrap failed (${csrfResponse.status()})`)
    }

    const usernameField = auth.username_field ?? 'email'
    const passwordField = auth.password_field ?? 'password'
    const bodyFormat = auth.body_format ?? 'json'

    const payload = {
      [usernameField]: username,
      [passwordField]: password,
    }

    const loginResponse =
      bodyFormat === 'form'
        ? await setupContext.request.post(loginUrl, {
            timeout: timeoutMs,
            failOnStatusCode: false,
            headers: defaultHeaders,
            form: payload,
          })
        : await setupContext.request.post(loginUrl, {
            timeout: timeoutMs,
            failOnStatusCode: false,
            headers: defaultHeaders,
            data: payload,
          })

    if (loginResponse.status() < 200 || loginResponse.status() >= 300) {
      throw new Error(`API login failed (${loginResponse.status()})`)
    }

    if (verifyUrl) {
      const verifyResponse = await setupContext.request.get(verifyUrl, {
        timeout: timeoutMs,
        failOnStatusCode: false,
        headers: defaultHeaders,
      })

      if (verifyResponse.status() < 200 || verifyResponse.status() >= 300) {
        throw new Error(`Auth verify failed (${verifyResponse.status()})`)
      }
    }

    // If login API returns token/user, persist expected localStorage keys used by the Vue auth store.
    const loginJson = tryExtractJsonBody(await loginResponse.json().catch(() => null))
    if (loginJson) {
      const page = await setupContext.newPage()
      await page.goto(resolveUrl(request.target.base_url, '/login'), {
        waitUntil: 'domcontentloaded',
        timeout: timeoutMs,
      })

      const token = typeof loginJson.token === 'string' ? loginJson.token : ''
      const user = typeof loginJson.user === 'object' && loginJson.user !== null ? loginJson.user : null
      const roles = Array.isArray(loginJson.roles) ? loginJson.roles : []

      await page.evaluate(
        ({ tokenValue, userValue, rolesValue }) => {
          if (tokenValue) {
            window.localStorage.setItem('auth_token', tokenValue)
          }

          if (userValue) {
            window.localStorage.setItem(
              'auth_user',
              JSON.stringify({ user: userValue, roles: rolesValue }),
            )
          }
        },
        {
          tokenValue: token,
          userValue: user,
          rolesValue: roles,
        },
      )
    }

    await setupContext.storageState({ path: storageStatePath })
  } finally {
    await setupContext.close()
  }
}

function createEmptyArtifacts(): CaseArtifacts {
  return {
    trace_path: null,
    screenshot_path: null,
    video_path: null,
  }
}

function describeStep(step: StepDsl): string {
  switch (step.action) {
    case 'goto':
      return `goto ${step.url}`
    case 'click':
      return `click ${selectorDebugText(step.selector)}`
    case 'fill':
      return `fill ${selectorDebugText(step.selector)}`
    case 'press':
      return `press ${step.key} on ${selectorDebugText(step.selector)}`
    case 'wait_for_url':
      return `wait_for_url contains '${step.contains}'`
    case 'wait_for_selector':
      return `wait_for_selector ${selectorDebugText(step.selector)} state=${step.state}`
    case 'screenshot':
      return `screenshot ${step.name}`
    case 'set_file':
      return `set_file ${selectorDebugText(step.selector)}`
    default: {
      const unreachable: never = step
      return JSON.stringify(unreachable)
    }
  }
}

function describeAssert(assertItem: AssertDsl): string {
  switch (assertItem.type) {
    case 'expect_visible':
      return `expect_visible ${selectorDebugText(assertItem.selector)}`
    case 'expect_hidden':
      return `expect_hidden ${selectorDebugText(assertItem.selector)}`
    case 'expect_text':
      return `expect_text ${selectorDebugText(assertItem.selector)}`
    case 'expect_text_contains':
      return `expect_text_contains ${selectorDebugText(assertItem.selector)}`
    case 'expect_url_contains':
      return `expect_url_contains '${assertItem.value}'`
    case 'expect_title':
      return `expect_title '${assertItem.value}'`
    default: {
      const unreachable: never = assertItem
      return JSON.stringify(unreachable)
    }
  }
}

function debugTrace(line: string): void {
  if (!RUNNER_DEBUG_ENABLED) {
    return
  }

  console.error(`[runner-debug] ${line}`)
}

function cloneGeneratedPlan(runCase: RunCaseDsl): Record<string, unknown> | null {
  if (runCase.generated_plan) {
    return JSON.parse(JSON.stringify(runCase.generated_plan)) as Record<string, unknown>
  }

  if (!runCase.execution_profile) {
    return null
  }

  return {
    title: runCase.title,
    intent_summary: runCase.execution_profile.intent_summary,
    coverage_type: runCase.execution_profile.coverage_type,
    preflight_checks: runCase.preflight_checks ?? [],
    steps: runCase.steps,
    asserts: runCase.asserts,
    expected_observations: runCase.execution_profile.expected_observations,
    diagnostics: runCase.execution_profile.diagnostics,
  }
}

export function resolveRequiredInputValue(runCase: RunCaseDsl, inputKey: string): string {
  const requiredInputs = runCase.execution_profile?.required_inputs ?? []
  const matched = requiredInputs.find((entry) => entry.key === inputKey)

  if (!matched) {
    throw new InputDataMissingError(`Le champ requis '${inputKey}' n'est pas defini dans le profil d'execution genere.`)
  }

  const value = matched.value
  if (
    matched.required &&
    !matched.allow_empty &&
    (value === undefined || value === null || String(value).trim() === '')
  ) {
    throw new InputDataMissingError(`Le champ '${matched.label}' est requis mais aucune valeur n'a ete fournie.`)
  }

  return typeof value === 'string' ? value : ''
}

function isStrictModeViolation(error: unknown): boolean {
  const message = error instanceof Error ? error.message : String(error)
  return message.toLowerCase().includes('strict mode violation')
}

async function clickWithStrictFallback(page: Page, selector: SelectorDsl): Promise<void> {
  const locator = selectorToLocator(page, selector)
  try {
    await locator.click()
  } catch (error) {
    if (!isStrictModeViolation(error)) {
      throw error
    }

    const fallbackLocator = await selectFirstVisibleLocator(locator)
    await fallbackLocator.click()
  }
}

async function fillWithStrictFallback(page: Page, selector: SelectorDsl, value: string): Promise<void> {
  const locator = selectorToLocator(page, selector)
  try {
    await locator.fill(value)
  } catch (error) {
    if (!isStrictModeViolation(error)) {
      throw error
    }

    const fallbackLocator = await selectFirstVisibleLocator(locator)
    await fallbackLocator.fill(value)
  }
}

async function pressWithStrictFallback(page: Page, selector: SelectorDsl, key: string): Promise<void> {
  const locator = selectorToLocator(page, selector)
  try {
    await locator.press(key)
  } catch (error) {
    if (!isStrictModeViolation(error)) {
      throw error
    }

    const fallbackLocator = await selectFirstVisibleLocator(locator)
    await fallbackLocator.press(key)
  }
}

async function selectFirstVisibleLocator(locator: ReturnType<typeof selectorToLocator>): Promise<ReturnType<typeof selectorToLocator>> {
  const total = await locator.count()
  for (let index = 0; index < total; index += 1) {
    const candidate = locator.nth(index)
    try {
      if (await candidate.isVisible()) {
        return candidate
      }
    } catch {
      // Ignore transient visibility lookup errors and keep scanning.
    }
  }

  return locator.first()
}

async function waitForSelectorWithStrictFallback(
  page: Page,
  selector: SelectorDsl,
  state: 'visible' | 'hidden' | 'attached' | 'detached',
  timeout: number,
): Promise<void> {
  const locator = selectorToLocator(page, selector)
  try {
    await locator.waitFor({ state, timeout })
  } catch (error) {
    if (!isStrictModeViolation(error)) {
      throw error
    }

    const fallbackLocator = state === 'visible'
      ? await selectFirstVisibleLocator(locator)
      : locator.first()
    await fallbackLocator.waitFor({ state, timeout })
  }
}

async function textContentWithStrictFallback(page: Page, selector: SelectorDsl, timeout: number): Promise<string | null> {
  const locator = selectorToLocator(page, selector)
  try {
    return await locator.textContent({ timeout })
  } catch (error) {
    if (!isStrictModeViolation(error)) {
      throw error
    }

    const fallbackLocator = await selectFirstVisibleLocator(locator)
    return fallbackLocator.textContent({ timeout })
  }
}

async function executeStep(
  page: Page,
  runCase: RunCaseDsl,
  step: StepDsl,
  baseUrl: string,
  timeoutMs: number,
  caseArtifactsDir: string,
): Promise<{ redirectedTo?: string } | void> {
  switch (step.action) {
    case 'goto': {
      const finalUrl = resolveUrl(baseUrl, step.url)
      await page.goto(finalUrl, { timeout: timeoutMs, waitUntil: 'domcontentloaded' })
      const actualUrl = page.url()
      return actualUrl !== finalUrl ? { redirectedTo: actualUrl } : {}
    }

    case 'click': {
      await clickWithStrictFallback(page, step.selector)
      return
    }

    case 'fill': {
      const rawValue = step.input_key
        ? resolveRequiredInputValue(runCase, step.input_key)
        : resolveTemplateValue(step.value ?? '')
      await fillWithStrictFallback(page, step.selector, rawValue)
      return
    }

    case 'press': {
      await pressWithStrictFallback(page, step.selector, step.key)
      return
    }

    case 'wait_for_url': {
      const contains = resolveTemplateValue(step.contains)
      await page.waitForURL((url) => url.toString().includes(contains), {
        timeout: step.timeout_ms ?? timeoutMs,
      })
      return
    }

    case 'wait_for_selector': {
      await waitForSelectorWithStrictFallback(
        page,
        step.selector,
        step.state,
        step.timeout_ms ?? timeoutMs,
      )
      return
    }

    case 'screenshot': {
      await ensureDir(caseArtifactsDir)
      const screenshotName = `${sanitizeFilename(step.name)}.png`
      const screenshotPath = path.join(caseArtifactsDir, screenshotName)
      await page.screenshot({
        path: screenshotPath,
        fullPage: true,
      })
      return
    }

    case 'set_file': {
      const locator = selectorToLocator(page, step.selector)
      const filePath = step.input_key
        ? resolveRequiredInputValue(runCase, step.input_key)
        : resolveTemplateValue(step.file_path ?? '')
      await locator.setInputFiles(filePath)
      return
    }

    default: {
      const unreachable: never = step
      throw new Error(`Unsupported step: ${JSON.stringify(unreachable)}`)
    }
  }
}

async function gotoWithRetry(
  page: Page,
  url: string,
  timeoutMs: number,
  pushTrace: (line: string) => Promise<void>,
  reference: string,
): Promise<void> {
  const attempts = 3
  let lastError: unknown = null

  for (let attempt = 1; attempt <= attempts; attempt += 1) {
    try {
      await pushTrace(`${reference} -> started (attempt ${attempt}/${attempts}, url=${url})`)
      debugTrace(`${reference} started attempt=${attempt} url=${url}`)
      await page.goto(url, { timeout: timeoutMs, waitUntil: 'domcontentloaded' })
      await pushTrace(`${reference} -> ok (attempt ${attempt}/${attempts})`)
      if (page.url() !== url) {
        await pushTrace(`${reference} -> redirected to ${page.url()}`)
      }
      debugTrace(`${reference} ok attempt=${attempt} url=${url}`)
      return
    } catch (error) {
      lastError = error
      const message = error instanceof Error ? error.message : String(error)
      await pushTrace(`${reference} -> failed (attempt ${attempt}/${attempts}, failure_type=initial_navigation_failed, failure_message=${message})`)
      debugTrace(`${reference} failed attempt=${attempt} url=${url} message=${message}`)

      if (attempt < attempts) {
        await sleep(1500 * attempt)
      }
    }
  }

  const lastMessage = lastError instanceof Error ? lastError.message : String(lastError)
  throw new InitialNavigationError(`The target URL could not be reached by Playwright. Details: ${lastMessage}`)
}

async function runPreflight(
  page: Page,
  runCase: RunCaseDsl,
  request: RunRequestDslV1,
  timeoutMs: number,
  pushTrace: (line: string) => Promise<void>,
): Promise<number> {
  const preflightChecks = runCase.preflight_checks ?? []
  const firstStep = runCase.steps[0]
  let consumedGoto = false

  await pushTrace(`Planning: ${runCase.execution_profile?.intent_summary ?? runCase.title}`)

  if (runCase.execution_profile?.preconditions?.length) {
    for (const precondition of runCase.execution_profile.preconditions) {
      await pushTrace(`Planning: precondition -> ${precondition}`)
    }
  }

  if (runCase.execution_profile?.required_inputs?.length) {
    for (const input of runCase.execution_profile.required_inputs) {
      const status = input.required ? 'required' : 'optional'
      const valueState =
        typeof input.value === 'string' && input.value.trim() === '' && input.allow_empty
          ? 'intentionally_empty'
          : input.value && input.value.trim() !== ''
            ? 'provided'
            : 'missing'
      await pushTrace(`Planning: input ${input.key} (${status}, ${input.kind}) -> ${valueState}`)
    }
  }

  if (firstStep?.action === 'goto') {
    const preflightUrl = resolveUrl(request.target.base_url, firstStep.url)
    await gotoWithRetry(page, preflightUrl, timeoutMs, pushTrace, 'Preflight 0: page_accessible')
    consumedGoto = true
  } else if (preflightChecks.some((check) => check.kind === 'page_accessible')) {
    const preflightUrl = resolveUrl(request.target.base_url, request.target.base_url)
    await gotoWithRetry(page, preflightUrl, timeoutMs, pushTrace, 'Preflight 0: page_accessible')
  }

  for (let index = 0; index < preflightChecks.length; index += 1) {
    const check = preflightChecks[index]
    const prefix = `Preflight ${index + 1}: ${check.label}`

    try {
      await pushTrace(`${prefix} -> started (kind=${check.kind})`)
      switch (check.kind) {
        case 'unsupported':
          throw new UnsupportedTestCaseError(check.failure_message)
        case 'input_available': {
          if (!check.input_key) {
            throw new PreconditionFailureError(`${check.label} is missing an input_key definition.`)
          }

          resolveRequiredInputValue(runCase, check.input_key)
          await pushTrace(`${prefix} -> ok`)
          break
        }
        case 'url_contains': {
          const currentUrl = page.url()
          if (!check.expected || !currentUrl.includes(check.expected)) {
            throw new PreconditionFailureError(check.failure_message)
          }
          await pushTrace(`${prefix} -> ok`)
          break
        }
        case 'element_visible': {
          if (!check.selector) {
            throw new PreconditionFailureError(`${check.label} is missing a selector.`)
          }
          await waitForSelectorWithStrictFallback(page, check.selector, 'visible', timeoutMs)
          await pushTrace(`${prefix} -> ok`)
          break
        }
        case 'element_attached': {
          if (!check.selector) {
            throw new PreconditionFailureError(`${check.label} is missing a selector.`)
          }
          await waitForSelectorWithStrictFallback(page, check.selector, 'attached', timeoutMs)
          await pushTrace(`${prefix} -> ok`)
          break
        }
        case 'page_accessible':
          await pushTrace(`${prefix} -> ok`)
          break
        default:
          throw new AmbiguousTargetError(check.failure_message)
      }
    } catch (error) {
      const message = error instanceof Error ? error.message : String(error)
      await pushTrace(`${prefix} -> failed (failure_type=preflight_check_failed, failure_message=${message})`)
      debugTrace(`${prefix} failed kind=${check.kind} message=${message}`)
      throw error
    }
  }

  return consumedGoto ? 1 : 0
}

async function executeAssert(page: Page, assertItem: AssertDsl, timeoutMs: number): Promise<void> {
  switch (assertItem.type) {
    case 'expect_visible': {
      try {
        await waitForSelectorWithStrictFallback(page, assertItem.selector, 'visible', timeoutMs)
      } catch (error) {
        throw new AssertionFailureError(
          `Expected visible but selector was not visible: ${selectorDebugText(assertItem.selector)} (${String(error)})`,
        )
      }
      return
    }

    case 'expect_hidden': {
      try {
        await waitForSelectorWithStrictFallback(page, assertItem.selector, 'hidden', timeoutMs)
      } catch (error) {
        throw new AssertionFailureError(
          `Expected hidden but selector was still visible: ${selectorDebugText(assertItem.selector)} (${String(error)})`,
        )
      }
      return
    }

    case 'expect_text': {
      const expected = resolveTemplateValue(assertItem.text)
      const actualRaw = await textContentWithStrictFallback(page, assertItem.selector, timeoutMs)
      const actual = (actualRaw ?? '').trim()
      if (actual !== expected) {
        throw new AssertionFailureError(
          `Expected text '${expected}' but got '${actual}' for ${selectorDebugText(assertItem.selector)}`,
        )
      }
      return
    }

    case 'expect_text_contains': {
      const expected = resolveTemplateValue(assertItem.text)
      const actualRaw = await textContentWithStrictFallback(page, assertItem.selector, timeoutMs)
      const actual = (actualRaw ?? '').trim()
      if (!actual.toLowerCase().includes(expected.toLowerCase())) {
        throw new AssertionFailureError(
          `Expected text containing '${expected}' but got '${actual}' for ${selectorDebugText(assertItem.selector)}`,
        )
      }
      return
    }

    case 'expect_url_contains': {
      const expectedFragment = resolveTemplateValue(assertItem.value)
      const currentUrl = page.url()
      if (!currentUrl.includes(expectedFragment)) {
        if (expectedFragment === UNKNOWN_SCENARIO_GUARD_TOKEN) {
          throw new AssertionFailureError(
            `Unknown scenario guard triggered: classifier returned unknown or low-confidence, so this case is intentionally failed. Current URL: '${currentUrl}'.`,
          )
        }

        throw new AssertionFailureError(
          `Expected URL to contain '${expectedFragment}' but got '${currentUrl}'`,
        )
      }
      return
    }

    case 'expect_title': {
      const expectedTitle = resolveTemplateValue(assertItem.value)
      const actualTitle = await page.title()
      if (actualTitle !== expectedTitle) {
        throw new AssertionFailureError(
          `Expected title '${expectedTitle}' but got '${actualTitle}'`,
        )
      }
      return
    }

    default: {
      const unreachable: never = assertItem
      throw new Error(`Unsupported assert: ${JSON.stringify(unreachable)}`)
    }
  }
}

async function stopTraceIfStarted(
  context: BrowserContext | undefined,
  traceStarted: boolean,
  keepTrace: boolean,
  traceOutputPath: string,
): Promise<string | null> {
  if (!traceStarted || !context) {
    return null
  }

  if (keepTrace) {
    await context.tracing.stop({ path: traceOutputPath })
    return traceOutputPath
  }

  await context.tracing.stop()
  return null
}

async function finalizeVideo(
  video: Video | null,
  keepVideo: boolean,
  caseArtifactsDir: string,
): Promise<string | null> {
  if (!video) {
    return null
  }

  const originalVideoPath = await video.path().catch(() => null)
  if (!originalVideoPath) {
    return null
  }

  if (!keepVideo) {
    await deleteIfExists(originalVideoPath)
    return null
  }

  const destination = path.join(caseArtifactsDir, 'video.webm')
  await moveOrCopyFile(originalVideoPath, destination)
  return destination
}

async function executeCase(
  browser: Browser,
  runCase: RunCaseDsl,
  request: RunRequestDslV1,
  options: ExecuteRunOptions,
  storageStatePath?: string,
  onTrace?: (line: string) => Promise<void>,
  tracePrefix = '',
): Promise<CaseResultV1> {
  const start = Date.now()
  const timeoutMs = request.runtime.timeout_ms
  const caseArtifactsDir = await ensureCaseArtifactsDir(options.artifactsConfig, runCase.external_id)

  let context: BrowserContext | undefined
  let page: Page | undefined
  let videoHandle: Video | null = null
  let traceStarted = false
  const artifacts = createEmptyArtifacts()
  const executionTrace: string[] = []
  const generatedPlan = cloneGeneratedPlan(runCase)

  const pushTrace = async (line: string): Promise<void> => {
    const prefixed = tracePrefix ? `[${tracePrefix}] ${line}` : line
    executionTrace.push(prefixed)
    if (onTrace) {
      await onTrace(prefixed)
    }
  }

  let status: CaseStatus = 'passed'
  let error_type: CaseResultV1['error_type'] = null
  let error_message: string | null = null
  let failureSource: CaseResultV1['failure_source'] = null
  let diagnostics: CaseResultV1['diagnostics'] = null
  let pageReachable = false

  try {
    await pushTrace('Planning: browser context starting')

    const caseStorageStatePath = runCase.use_auth === false ? undefined : storageStatePath

    context = await browser.newContext({
      viewport: request.runtime.viewport,
      storageState: caseStorageStatePath,
      recordVideo:
        request.runtime.video === 'retain-on-failure'
          ? {
              dir: caseArtifactsDir,
            }
          : undefined,
    })

    page = await context.newPage()
    videoHandle = page.video()
    page.setDefaultTimeout(timeoutMs)

    if (request.runtime.trace === 'retain-on-failure') {
      await context.tracing.start({
        screenshots: true,
        snapshots: true,
        sources: true,
      })
      traceStarted = true
    }

    const firstExecutableStepIndex = await runPreflight(
      page,
      runCase,
      request,
      timeoutMs,
      pushTrace,
    )
    pageReachable = true

    for (let index = firstExecutableStepIndex; index < runCase.steps.length; index += 1) {
      const step = runCase.steps[index]
      const stepLabel = describeStep(step)
      const stepStart = Date.now()

      try {
        await pushTrace(`Step ${index + 1}: ${stepLabel} -> started`)
        const stepOutcome = await executeStep(page, runCase, step, request.target.base_url, timeoutMs, caseArtifactsDir)
        await pushTrace(`Step ${index + 1}: ${stepLabel} -> ok (${Date.now() - stepStart}ms)`)
        if (stepOutcome && stepOutcome.redirectedTo) {
          await pushTrace(`Step ${index + 1}: ${stepLabel} -> redirected to ${stepOutcome.redirectedTo}`)
        }
        debugTrace(`Step ${index + 1} ok action=${step.action}`)
      } catch (error) {
        const message = error instanceof Error ? error.message : String(error)
        const normalized = normalizeError(error)
        await pushTrace(`Step ${index + 1}: ${stepLabel} -> failed (failure_type=${normalized.error_type}, failure_message=${message})`)
        debugTrace(`Step ${index + 1} failed action=${step.action} type=${normalized.error_type} message=${message}`)
        failureSource = {
          phase: 'step',
          reference: stepLabel,
          message,
        }
        throw error
      }
    }

    for (let index = 0; index < runCase.asserts.length; index += 1) {
      const assertItem = runCase.asserts[index]
      const assertLabel = describeAssert(assertItem)
      const assertStart = Date.now()

      try {
        await pushTrace(`Assert ${index + 1}: ${assertLabel} -> started`)
        await executeAssert(page, assertItem, timeoutMs)
        await pushTrace(`Assert ${index + 1}: ${assertLabel} -> ok (${Date.now() - assertStart}ms)`)
        debugTrace(`Assert ${index + 1} ok type=${assertItem.type}`)
      } catch (error) {
        const message = error instanceof Error ? error.message : String(error)
        const normalized = normalizeError(error)
        await pushTrace(`Assert ${index + 1}: ${assertLabel} -> failed (failure_type=${normalized.error_type}, failure_message=${message})`)
        debugTrace(`Assert ${index + 1} failed type=${assertItem.type} failure_type=${normalized.error_type} message=${message}`)
        failureSource = {
          phase: 'assert',
          reference: assertLabel,
          message,
        }
        throw error
      }
    }
  } catch (error) {
    let normalized = normalizeError(error)
    const failurePhase = failureSource ? failureSource.phase : null

    if (pageReachable && normalized.error_type === 'url_unreachable') {
      normalized = {
        error_type: failurePhase === 'assert' ? 'assertion_failed' : 'unexpected_navigation_state',
        error_message: normalized.error_message,
      }
    }

    if (
      normalized.error_type === 'missing_env_var' ||
      normalized.error_type === 'url_unreachable' ||
      normalized.error_type === 'authentication_failed' ||
      normalized.error_type === 'precondition_failed' ||
      normalized.error_type === 'unsupported_test_case' ||
      normalized.error_type === 'input_data_missing' ||
      normalized.error_type === 'ambiguous_target'
    ) {
      status = 'blocked'
    } else if (failurePhase === 'preflight') {
      status = 'blocked'
    } else {
      status = 'failed'
    }

    error_type = normalized.error_type
    error_message = normalized.error_message
    diagnostics = {
      raw_error: error instanceof Error ? error.message : String(error),
      normalized_error_type: normalized.error_type,
      page_reachable: pageReachable,
    }
    if (!failureSource) {
      failureSource = {
        phase: normalized.error_type === 'url_unreachable'
          ? 'initial_navigation'
          : normalized.error_type === 'precondition_failed' || normalized.error_type === 'unsupported_test_case' || normalized.error_type === 'input_data_missing'
          ? 'preflight'
          : 'runtime',
        reference: normalized.error_type,
        message: normalized.error_message,
      }
    }
    await pushTrace(`Failure analysis: ${normalized.error_type} -> ${normalized.error_message}`)

    if (status === 'failed' && request.runtime.screenshot === 'only-on-failure' && page) {
      const failScreenshotPath = path.join(caseArtifactsDir, 'fail.png')
      await page.screenshot({ path: failScreenshotPath, fullPage: true })
      artifacts.screenshot_path = toRelativeWorkPath(options.artifactsConfig, failScreenshotPath)
    }

    const tracePath = path.join(caseArtifactsDir, 'trace.zip')
    const keepTrace = status === 'failed' && request.runtime.trace === 'retain-on-failure'
    const traceSavedPath = await stopTraceIfStarted(context, traceStarted, keepTrace, tracePath)
    traceStarted = false

    if (traceSavedPath) {
      artifacts.trace_path = toRelativeWorkPath(options.artifactsConfig, traceSavedPath)
    }
  } finally {
    const holdOpenMs = request.runtime.hold_open_ms ?? 0
    if (!request.runtime.headless && holdOpenMs > 0 && page) {
      await sleep(holdOpenMs)
    }

    if (traceStarted) {
      await stopTraceIfStarted(context, traceStarted, false, path.join(caseArtifactsDir, 'trace.zip'))
      traceStarted = false
    }

    if (context) {
      await context.close()
    }

    if (request.runtime.video === 'retain-on-failure') {
      const keepVideo = status === 'failed'
      const videoPath = await finalizeVideo(videoHandle, keepVideo, caseArtifactsDir)
      if (videoPath) {
        artifacts.video_path = toRelativeWorkPath(options.artifactsConfig, videoPath)
      }
    }

    if (status === 'passed') {
      await pushTrace('Failure analysis: none')
      await pushTrace('Run completed successfully')
    }
  }

  return {
    external_id: runCase.external_id,
    status,
    attempt: 1,
    duration_ms: Date.now() - start,
    error_type,
    error_message,
    artifacts,
    generated_plan: generatedPlan,
    diagnostics,
    failure_source: failureSource,
    execution_trace: executionTrace,
  }
}

function getRuntimeBrowserTargets(request: RunRequestDslV1): RunBrowserTarget[] {
  const browsers = request.runtime.browsers
  if (!Array.isArray(browsers) || browsers.length === 0) {
    return ['chromium']
  }

  return Array.from(new Set(browsers))
}

function getBrowserLabel(target: RunBrowserTarget): string {
  switch (target) {
    case 'chromium':
      return 'chrome'
    case 'msedge':
      return 'edge'
    case 'firefox':
      return 'firefox'
    case 'webkit':
      return 'safari'
    default:
      return target
  }
}

function statusPriority(status: CaseResultV1['status']): number {
  switch (status) {
    case 'failed':
      return 3
    case 'blocked':
      return 2
    case 'skipped':
      return 1
    case 'passed':
    default:
      return 0
  }
}

function pickWorstStatus(results: BrowserCaseResult[]): CaseResultV1['status'] {
  let worst: CaseResultV1['status'] = 'passed'

  for (const entry of results) {
    if (statusPriority(entry.result.status) > statusPriority(worst)) {
      worst = entry.result.status
    }
  }

  return worst
}

function firstNonNull<T>(values: Array<T | null | undefined>): T | null {
  for (const value of values) {
    if (value !== null && value !== undefined) {
      return value
    }
  }

  return null
}

function aggregateBrowserResults(runCase: RunCaseDsl, browserResults: BrowserCaseResult[]): CaseResultV1 {
  const status = pickWorstStatus(browserResults)
  const firstFailure = browserResults.find((entry) => entry.result.status !== 'passed')
  const aggregatedTrace = browserResults.flatMap((entry) => entry.result.execution_trace ?? [])

  return {
    external_id: runCase.external_id,
    status,
    attempt: 1,
    duration_ms: browserResults.reduce((sum, entry) => sum + entry.result.duration_ms, 0),
    error_type: firstFailure?.result.error_type ?? null,
    error_message: firstFailure?.result.error_message ?? null,
    generated_plan: firstFailure?.result.generated_plan ?? browserResults[0]?.result.generated_plan ?? null,
    failure_source: firstFailure?.result.failure_source ?? null,
    artifacts: {
      trace_path: firstNonNull(browserResults.map((entry) => entry.result.artifacts.trace_path)),
      screenshot_path: firstNonNull(browserResults.map((entry) => entry.result.artifacts.screenshot_path)),
      video_path: firstNonNull(browserResults.map((entry) => entry.result.artifacts.video_path)),
    },
    execution_trace: aggregatedTrace,
  }
}

async function launchBrowserForTarget(
  target: RunBrowserTarget,
  request: RunRequestDslV1,
): Promise<Browser> {
  const launchOptions: LaunchOptions = {
    headless: request.runtime.headless,
    slowMo: request.runtime.slow_mo_ms ?? 0,
  }

  let browserType: BrowserType<Browser>

  switch (target) {
    case 'firefox':
      browserType = firefox
      break
    case 'webkit':
      browserType = webkit
      break
    case 'msedge':
      browserType = chromium
      launchOptions.channel = 'msedge'
      break
    case 'chromium':
    default:
      browserType = chromium
      break
  }

  return browserType.launch(launchOptions)
}

export async function executeRun(
  request: RunRequestDslV1,
  options: ExecuteRunOptions,
): Promise<RunResultV1> {
  const runnerRunId = `runner-${Date.now()}`
  const browserTargets = getRuntimeBrowserTargets(request)

  const liveTraceState: LiveTraceState | null = options.liveTracePath
    ? {
        schema_version: '1.0',
        run_id: request.run_id,
        status: 'running',
        updated_at: new Date().toISOString(),
        cases: request.cases.map((runCase) => ({
          external_id: runCase.external_id,
          title: runCase.title,
          status: 'queued',
          execution_trace: [],
        })),
      }
    : null

  const results: CaseResultV1[] = []
  let storageStatePath: string | undefined

  try {
    await flushLiveTrace(options.liveTracePath, liveTraceState)

    if (request.auth?.mode === 'api') {
      const authBrowser = await chromium.launch({
        headless: request.runtime.headless,
        slowMo: request.runtime.slow_mo_ms ?? 0,
      })

      storageStatePath = path.join(options.artifactsConfig.workRoot, 'storageState.json')
      try {
        await createApiAuthStorageState(
          authBrowser,
          request,
          request.auth,
          request.runtime.timeout_ms,
          storageStatePath,
        )
      } finally {
        await authBrowser.close()
      }
    }

    for (const runCase of request.cases) {
      const liveCase = liveTraceState?.cases.find((entry) => entry.external_id === runCase.external_id)
      if (liveCase && liveTraceState) {
        liveCase.status = 'running'
        liveTraceState.updated_at = new Date().toISOString()
        await flushLiveTrace(options.liveTracePath, liveTraceState)
      }

      const browserResults: BrowserCaseResult[] = []

      for (const browserTarget of browserTargets) {
        const browserLabel = getBrowserLabel(browserTarget)
        let caseBrowser: Browser | undefined

        try {
          caseBrowser = await launchBrowserForTarget(browserTarget, request)

          const caseResult = await executeCase(
            caseBrowser,
            runCase,
            request,
            options,
            storageStatePath,
            async (line) => {
              if (!liveCase || !liveTraceState) {
                return
              }

              liveCase.execution_trace.push(line)
              liveTraceState.updated_at = new Date().toISOString()
              await flushLiveTrace(options.liveTracePath, liveTraceState)
            },
            browserLabel,
          )

          browserResults.push({
            browser: browserTarget,
            result: caseResult,
          })
        } catch (error) {
          const message = error instanceof Error ? error.message : String(error)
          browserResults.push({
            browser: browserTarget,
            result: {
              external_id: runCase.external_id,
              status: 'blocked',
              attempt: 1,
              duration_ms: 0,
              error_type: 'unexpected_error',
              error_message: `[${browserLabel}] ${message}`,
              artifacts: {
                trace_path: null,
                screenshot_path: null,
                video_path: null,
              },
              execution_trace: [`[${browserLabel}] Browser launch failed: ${message}`],
            },
          })

          if (liveCase && liveTraceState) {
            liveCase.execution_trace.push(`[${browserLabel}] Browser launch failed: ${message}`)
            liveTraceState.updated_at = new Date().toISOString()
            await flushLiveTrace(options.liveTracePath, liveTraceState)
          }
        } finally {
          if (caseBrowser) {
            await caseBrowser.close()
          }
        }
      }

      const caseResult = aggregateBrowserResults(runCase, browserResults)

      if (liveCase && liveTraceState) {
        liveCase.status = caseResult.status
        liveTraceState.updated_at = new Date().toISOString()
        await flushLiveTrace(options.liveTracePath, liveTraceState)
      }

      results.push(caseResult)
    }

    if (liveTraceState) {
      liveTraceState.status = 'done'
      liveTraceState.updated_at = new Date().toISOString()
      await flushLiveTrace(options.liveTracePath, liveTraceState)
    }
  } catch (error) {
    if (liveTraceState) {
      liveTraceState.status = 'runner_error'
      liveTraceState.error_message = error instanceof Error ? error.message : String(error)
      liveTraceState.updated_at = new Date().toISOString()
      await flushLiveTrace(options.liveTracePath, liveTraceState)
    }

    throw error
  }

  return {
    schema_version: '1.0',
    run_id: request.run_id,
    runner_run_id: runnerRunId,
    base_url_used: request.target.base_url,
    status: 'done',
    summary: summarizeResults(results),
    results,
  }
}
