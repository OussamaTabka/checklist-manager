import { spawn } from 'node:child_process'
import path from 'node:path'
import type { AppConfig } from './config'

export interface DockerRunOutcome {
  exitCode: number
}

function dockerCandidates(): string[] {
  const fromEnv = process.env.DOCKER_BIN?.trim()
  const candidates = fromEnv ? [fromEnv] : []

  if (process.platform === 'win32') {
    candidates.push(
      'docker.exe',
      'docker',
      'C:/Program Files/Docker/Docker/resources/bin/docker.exe',
      'C:/ProgramData/DockerDesktop/version-bin/docker.exe',
    )
  } else {
    candidates.push('docker')
  }

  return Array.from(new Set(candidates))
}

function spawnDocker(
  executable: string,
  args: string[],
  cwd: string,
): Promise<DockerRunOutcome> {
  return new Promise((resolve, reject) => {
    const child = spawn(executable, args, {
      stdio: 'inherit',
      cwd,
    })

    child.on('error', (error) => {
      reject(error)
    })

    child.on('close', (code) => {
      resolve({
        exitCode: code ?? 1,
      })
    })
  })
}

function shouldInjectLinuxHostGateway(baseUrl: string, config: AppConfig): boolean {
  if (process.platform !== 'linux') {
    return false
  }

  if (!config.dockerUseHostGateway) {
    return false
  }

  return baseUrl.includes('host.docker.internal') || baseUrl.includes('localhost')
}

export async function runDockerJob(config: AppConfig, hostRunDir: string): Promise<DockerRunOutcome> {
  const args: string[] = ['run', '--rm']

  if (shouldInjectLinuxHostGateway(config.baseUrl, config)) {
    args.push('--add-host=host.docker.internal:host-gateway')
  }

  args.push('--mount', `type=bind,source=${hostRunDir},target=/work`)

  args.push(
    '-e',
    'RUN_JSON_PATH=/work/run.json',
    '-e',
    'RESULT_JSON_PATH=/work/result.json',
    '-e',
    'ARTIFACTS_DIR=/work/artifacts',
  )

  if (config.e2eEmail) {
    args.push('-e', `E2E_EMAIL=${config.e2eEmail}`)
  }

  if (config.e2ePassword) {
    args.push('-e', `E2E_PASSWORD=${config.e2ePassword}`)
  }

  if (config.dockerExtraArgs.length > 0) {
    args.push(...config.dockerExtraArgs)
  }

  args.push(config.dockerImage)

  const cwd = path.resolve(process.cwd())
  const candidates = dockerCandidates()
  let lastError: unknown

  for (const candidate of candidates) {
    try {
      return await spawnDocker(candidate, args, cwd)
    } catch (error) {
      const isEnoent =
        typeof error === 'object' &&
        error !== null &&
        'code' in error &&
        (error as { code?: unknown }).code === 'ENOENT'

      if (isEnoent) {
        lastError = error
        continue
      }

      throw error
    }
  }

  throw lastError instanceof Error
    ? lastError
    : new Error('Unable to spawn docker: executable not found')
}
