import fs from 'node:fs'
import path from 'node:path'
import { runOnceWithRunSpec } from '../runOnce'
import type { RunRequestDslV1 } from '../types'
import { loadConfig } from '../config'

function runSpecRequiresAuth(runSpec: RunRequestDslV1): boolean {
  if (!Array.isArray(runSpec.cases)) {
    throw new Error('[from-dsl] invalid run spec: cases must be an array')
  }

  // `use_auth` defaults to enabled when omitted in runner behavior.
  return runSpec.cases.some((runCase) => runCase.use_auth !== false)
}

function withAuthFromConfig(runSpec: RunRequestDslV1): RunRequestDslV1 {
  if (runSpec.auth) {
    return runSpec
  }

  if (!runSpecRequiresAuth(runSpec)) {
    return runSpec
  }

  let config: ReturnType<typeof loadConfig>
  try {
    config = loadConfig()
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error)
    throw new Error(
      `[from-dsl] run spec requires auth but auth injection failed to load config: ${message}`,
    )
  }

  if (config.authMode === 'api' && config.enableLoginFlow) {
    console.error('[from-dsl] injected auth from config because run spec missing auth block')
    return {
      ...runSpec,
      auth: {
        mode: 'api',
        csrf_cookie_url: config.sanctumCsrfCookiePath,
        login_url: config.loginPath,
        verify_url: config.authVerifyPath,
        username_env: 'E2E_EMAIL',
        password_env: 'E2E_PASSWORD',
        username_field: 'email',
        password_field: 'password',
        body_format: config.authBodyFormat,
      },
    }
  }

  if (config.authMode === 'ui') {
    console.error('[from-dsl] injected auth from config because run spec missing auth block')
    return {
      ...runSpec,
      auth: {
        mode: 'ui',
      },
    }
  }

  throw new Error(
    '[from-dsl] run spec requires auth but no auth block was provided and config does not allow injection. Set ENABLE_LOGIN_FLOW=true with AUTH_MODE=api, switch AUTH_MODE=ui, or provide runSpec.auth explicitly.',
  )
}

function usageAndExit(): never {
  console.error('Usage: npm run run:from-dsl -- <path-to-run.json>')
  process.exit(3)
}

async function main(): Promise<void> {
  const dslPath = process.argv[2]
  if (!dslPath) {
    usageAndExit()
  }

  const absoluteDslPath = path.resolve(dslPath)
  if (!fs.existsSync(absoluteDslPath)) {
    console.error(`DSL file not found: ${absoluteDslPath}`)
    process.exit(3)
  }

  const raw = fs.readFileSync(absoluteDslPath, 'utf8')
  const parsedRunSpec = JSON.parse(raw) as RunRequestDslV1

  if (!process.env.BASE_URL && parsedRunSpec?.target?.base_url) {
    process.env.BASE_URL = parsedRunSpec.target.base_url
  }

  const runSpec = withAuthFromConfig(parsedRunSpec)

  const result = await runOnceWithRunSpec(runSpec, () => {})

  process.stdout.write(JSON.stringify(result, null, 2))
  process.exit(result.exitCode ?? 0)
}

main().catch((error) => {
  console.error(error)
  process.exit(3)
})
