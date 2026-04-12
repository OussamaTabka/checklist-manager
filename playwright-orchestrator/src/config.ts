import path from 'node:path'
import dotenv from 'dotenv'

dotenv.config()

export interface AppConfig {
  baseUrl: string
  runSuite: 'smoke' | 'full'
  runMode: string
  targetApp: string
  smokePages: Array<'dashboard' | 'checklists' | 'projects' | 'users'>
  runsRoot: string
  runId?: string

  loginEmailTestId: string
  loginPasswordTestId: string
  loginSubmitTestId: string
  dashboardUrlFragment: string
  smokeExternalId: number
  smokeUiLoginExternalId: number
  enableLoginFlow: boolean
  loginFlowForcedForProtectedSmoke: boolean
  authMode: 'api' | 'ui'
  authUiTestEnabled: boolean
  sanctumCsrfCookiePath: string
  loginPath: string
  authVerifyPath: string
  authBodyFormat: 'json' | 'form'

  runtimeTimeoutMs: number
  runtimeViewportWidth: number
  runtimeViewportHeight: number
  runtimeHeadless: boolean
  runtimeTrace: 'retain-on-failure' | 'off'
  runtimeVideo: 'retain-on-failure' | 'off'
  runtimeScreenshot: 'only-on-failure' | 'off'

  dockerImage: string
  dockerUseHostGateway: boolean
  dockerExtraArgs: string[]

  e2eEmail?: string
  e2ePassword?: string

  enableLaravel: boolean
  enableCreateRun: boolean
  enablePublishResults: boolean
  enableBatchResults: boolean
  enableFallbackPatch: boolean
  laravelBaseUrl?: string
  laravelToken?: string
  projectVersionId?: number
}

function parseBool(value: string | undefined, defaultValue: boolean): boolean {
  if (value === undefined || value.trim() === '') {
    return defaultValue
  }

  const normalized = value.trim().toLowerCase()
  return normalized === '1' || normalized === 'true' || normalized === 'yes' || normalized === 'on'
}

function parseIntStrict(value: string | undefined, fieldName: string, defaultValue?: number): number {
  if (value === undefined || value.trim() === '') {
    if (defaultValue !== undefined) {
      return defaultValue
    }
    throw new Error(`Missing required numeric env: ${fieldName}`)
  }

  const parsed = Number.parseInt(value, 10)
  if (!Number.isFinite(parsed)) {
    throw new Error(`Invalid numeric env ${fieldName}: ${value}`)
  }

  return parsed
}

function parseCsvTokens(value: string | undefined): string[] {
  if (!value || value.trim() === '') {
    return []
  }

  return value
    .split(' ')
    .map((part) => part.trim())
    .filter((part) => part.length > 0)
}

function normalizeApiBaseUrl(url: string): string {
  return url.endsWith('/') ? url.slice(0, -1) : url
}

function parseSmokePages(value: string | undefined): Array<'dashboard' | 'checklists' | 'projects' | 'users'> {
  const defaults: Array<'dashboard' | 'checklists' | 'projects' | 'users'> = [
    'dashboard',
    'checklists',
    'projects',
  ]

  if (!value || value.trim() === '') {
    return defaults
  }

  const allowed = new Set(['dashboard', 'checklists', 'projects', 'users'])
  const parsed = value
    .split(',')
    .map((part) => part.trim().toLowerCase())
    .filter((part) => part.length > 0)

  if (parsed.length === 0) {
    return defaults
  }

  for (const page of parsed) {
    if (!allowed.has(page)) {
      throw new Error(`SMOKE_PAGES contains unsupported value: ${page}`)
    }
  }

  return Array.from(new Set(parsed)) as Array<'dashboard' | 'checklists' | 'projects' | 'users'>
}

export function loadConfig(): AppConfig {
  const baseUrl = process.env.BASE_URL ?? process.env.APP_BASE_URL
  if (!baseUrl || baseUrl.trim() === '') {
    throw new Error('BASE_URL (or APP_BASE_URL) is required')
  }

  const runSuite = (process.env.RUN_SUITE ?? 'smoke').trim().toLowerCase()
  if (runSuite !== 'smoke' && runSuite !== 'full') {
    throw new Error('RUN_SUITE must be smoke or full')
  }

  const runtimeTrace = (process.env.RUNTIME_TRACE ?? 'retain-on-failure') as 'retain-on-failure' | 'off'
  if (runtimeTrace !== 'retain-on-failure' && runtimeTrace !== 'off') {
    throw new Error('RUNTIME_TRACE must be retain-on-failure|off')
  }

  const runtimeVideo = (process.env.RUNTIME_VIDEO ?? 'retain-on-failure') as 'retain-on-failure' | 'off'
  if (runtimeVideo !== 'retain-on-failure' && runtimeVideo !== 'off') {
    throw new Error('RUNTIME_VIDEO must be retain-on-failure|off')
  }

  const runtimeScreenshot = (process.env.RUNTIME_SCREENSHOT ?? 'only-on-failure') as
    | 'only-on-failure'
    | 'off'
  if (runtimeScreenshot !== 'only-on-failure' && runtimeScreenshot !== 'off') {
    throw new Error('RUNTIME_SCREENSHOT must be only-on-failure|off')
  }

  const authMode = (process.env.AUTH_MODE ?? 'api').trim().toLowerCase()
  if (authMode !== 'api' && authMode !== 'ui') {
    throw new Error('AUTH_MODE must be api or ui')
  }

  const authBodyFormat = (process.env.AUTH_BODY_FORMAT ?? 'json').trim().toLowerCase()
  if (authBodyFormat !== 'json' && authBodyFormat !== 'form') {
    throw new Error('AUTH_BODY_FORMAT must be json or form')
  }

  const smokePages = parseSmokePages(process.env.SMOKE_PAGES)
  const includesProtectedSmokePages = smokePages.some((page) =>
    ['dashboard', 'checklists', 'projects', 'users'].includes(page),
  )

  const requestedEnableLoginFlow = parseBool(process.env.ENABLE_LOGIN_FLOW, true)
  const loginFlowForcedForProtectedSmoke =
    authMode === 'api' && includesProtectedSmokePages && !requestedEnableLoginFlow
  const effectiveEnableLoginFlow =
    authMode === 'api' && includesProtectedSmokePages ? true : requestedEnableLoginFlow

  const enableLaravel = parseBool(process.env.ENABLE_LARAVEL, true)
  const enableCreateRun = parseBool(process.env.ENABLE_CREATE_RUN, true)
  const enablePublishResults = parseBool(process.env.ENABLE_PUBLISH_RESULTS, true)
  const enableBatchResults = parseBool(process.env.ENABLE_BATCH_RESULTS, true)
  const enableFallbackPatch = parseBool(process.env.ENABLE_FALLBACK_PATCH, true)

  const laravelBaseUrlRaw = (process.env.LARAVEL_BASE_URL ?? process.env.LARAVEL_API_BASE_URL)?.trim()
  const laravelToken = process.env.LARAVEL_TOKEN?.trim()

  if (enableLaravel) {
    if (!laravelBaseUrlRaw) {
      throw new Error('LARAVEL_BASE_URL (or LARAVEL_API_BASE_URL) is required when ENABLE_LARAVEL=true')
    }

    if (!laravelToken) {
      throw new Error('LARAVEL_TOKEN is required when ENABLE_LARAVEL=true')
    }
  }

  const projectVersionId = process.env.PROJECT_VERSION_ID
    ? parseIntStrict(process.env.PROJECT_VERSION_ID, 'PROJECT_VERSION_ID')
    : undefined

  if (enableLaravel && enableCreateRun && !projectVersionId) {
    throw new Error('PROJECT_VERSION_ID is required when ENABLE_CREATE_RUN=true')
  }

  return {
    baseUrl: baseUrl.trim(),
    runSuite,
    runMode: (process.env.RUN_MODE ?? runSuite).trim(),
    targetApp: (process.env.TARGET_APP ?? 'checklist-manager').trim().toLowerCase(),
    smokePages,
    runsRoot: path.resolve(process.cwd(), process.env.RUNS_ROOT ?? process.env.RUNS_DIR ?? './runs'),
    runId: process.env.RUN_ID?.trim() || undefined,

    loginEmailTestId: (process.env.LOGIN_EMAIL_TESTID ?? 'login-input-email').trim(),
    loginPasswordTestId: (process.env.LOGIN_PASSWORD_TESTID ?? 'login-input-password').trim(),
    loginSubmitTestId: (process.env.LOGIN_SUBMIT_TESTID ?? 'login-btn-submit').trim(),
    dashboardUrlFragment: (process.env.DASHBOARD_URL_FRAGMENT ?? '/dashboard').trim(),
    smokeExternalId: parseIntStrict(process.env.SMOKE_EXTERNAL_ID, 'SMOKE_EXTERNAL_ID', 7101),
    smokeUiLoginExternalId: parseIntStrict(
      process.env.SMOKE_UI_LOGIN_EXTERNAL_ID,
      'SMOKE_UI_LOGIN_EXTERNAL_ID',
      7102,
    ),
    enableLoginFlow: effectiveEnableLoginFlow,
    loginFlowForcedForProtectedSmoke,
    authMode,
    authUiTestEnabled: parseBool(process.env.AUTH_UI_TEST_ENABLED, true),
    sanctumCsrfCookiePath: (process.env.SANCTUM_CSRF_COOKIE_PATH ?? '/sanctum/csrf-cookie').trim(),
    loginPath: (process.env.LOGIN_PATH ?? '/api/login').trim(),
    authVerifyPath: (process.env.AUTH_VERIFY_PATH ?? '/api/me').trim(),
    authBodyFormat,

    runtimeTimeoutMs: parseIntStrict(process.env.RUNTIME_TIMEOUT_MS, 'RUNTIME_TIMEOUT_MS', 30000),
    runtimeViewportWidth: parseIntStrict(process.env.RUNTIME_VIEWPORT_WIDTH, 'RUNTIME_VIEWPORT_WIDTH', 1280),
    runtimeViewportHeight: parseIntStrict(process.env.RUNTIME_VIEWPORT_HEIGHT, 'RUNTIME_VIEWPORT_HEIGHT', 720),
    runtimeHeadless: parseBool(process.env.RUNTIME_HEADLESS, true),
    runtimeTrace,
    runtimeVideo,
    runtimeScreenshot,

    dockerImage: (process.env.DOCKER_IMAGE ?? process.env.RUNNER_IMAGE ?? 'checklist-playwright-job').trim(),
    dockerUseHostGateway: parseBool(
      process.env.DOCKER_USE_HOST_GATEWAY,
      process.platform === 'linux',
    ),
    dockerExtraArgs: parseCsvTokens(process.env.DOCKER_EXTRA_ARGS),

    e2eEmail: process.env.E2E_EMAIL,
    e2ePassword: process.env.E2E_PASSWORD,

    enableLaravel,
    enableCreateRun,
    enablePublishResults,
    enableBatchResults,
    enableFallbackPatch,
    laravelBaseUrl: laravelBaseUrlRaw ? normalizeApiBaseUrl(laravelBaseUrlRaw) : undefined,
    laravelToken,
    projectVersionId,
  }
}
