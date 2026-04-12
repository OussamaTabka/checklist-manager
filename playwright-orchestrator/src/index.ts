import { loadConfig } from './config'
import { buildRunRequest } from './runBuilder'
import { runOnceWithRunSpec } from './runOnce'

function log(event: string, data: Record<string, unknown> = {}): void {
  console.log(
    JSON.stringify({
      ts: new Date().toISOString(),
      event,
      ...data,
    }),
  )
}

async function main(): Promise<void> {
  const config = loadConfig()

  log('orchestrator.started', {
    suite: config.runSuite,
    base_url: config.baseUrl,
    docker_image: config.dockerImage,
    enable_laravel: config.enableLaravel,
    smoke_pages: config.smokePages,
    auth_mode: config.authMode,
  })

  if (config.loginFlowForcedForProtectedSmoke) {
    log('orchestrator.auth_force_enabled', {
      reason: 'AUTH_MODE=api with protected smoke pages requires login flow',
    })
  }

  const runRequest = buildRunRequest(config)
  const outcome = await runOnceWithRunSpec(runRequest, log, config)
  process.exit(outcome.exitCode)
}

main().catch((error) => {
  const message = error instanceof Error ? error.message : String(error)
  log('orchestrator.failed', { message })
  process.exit(3)
})
