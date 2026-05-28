import { promises as fs } from 'node:fs'
import path from 'node:path'
import * as childProcess from 'node:child_process'

export type GeneratedSpecExecutionInput = {
  run_id: string
  base_url: string
  spec_file: string
  output_dir: string
  provided_inputs?: Record<string, unknown>
}

export type GeneratedSpecExecutionResult = {
  status: 'passed' | 'failed' | 'blocked'
  duration_ms: number
  execution_trace: string[]
  artifacts: string[]
  error_type: string | null
  error_message: string | null
  failure_source?: {
    phase: 'initial_navigation' | 'assert' | 'runtime'
    reference: string
    message: string
  }
}

export function mapGeneratedSpecFailure(message: string): Pick<GeneratedSpecExecutionResult, 'status' | 'error_type' | 'error_message' | 'failure_source'> {
  const normalized = message.trim()

  if (/ERR_NAME_NOT_RESOLVED|ERR_CONNECTION_REFUSED|ERR_CONNECTION_TIMED_OUT|Cannot navigate to invalid URL/i.test(normalized)) {
    return {
      status: 'blocked',
      error_type: 'url_unreachable',
      error_message: normalized,
      failure_source: {
        phase: 'initial_navigation',
        reference: 'url_unreachable',
        message: normalized,
      },
    }
  }

  if (/expect\(|assert|Expected:/i.test(normalized)) {
    return {
      status: 'failed',
      error_type: 'assertion_failed',
      error_message: normalized,
      failure_source: {
        phase: 'assert',
        reference: 'generated_spec_assertion',
        message: normalized,
      },
    }
  }

  return {
    status: 'blocked',
    error_type: 'generated_spec_execution_failed',
    error_message: normalized,
    failure_source: {
      phase: 'runtime',
      reference: 'generated_spec_runtime',
      message: normalized,
    },
  }
}

export async function readExecutionInput(inputPath: string): Promise<GeneratedSpecExecutionInput> {
  const raw = await fs.readFile(inputPath, 'utf8')
  return JSON.parse(raw) as GeneratedSpecExecutionInput
}

export async function executeGeneratedSpec(
  input: GeneratedSpecExecutionInput,
): Promise<GeneratedSpecExecutionResult> {
  const startedAt = Date.now()
  const outputDir = path.resolve(input.output_dir)
  const specFile = path.resolve(input.spec_file)
  const configPath = path.join(outputDir, 'playwright.generated.config.cjs')
  const reportPath = path.join(outputDir, 'playwright-report.json')
  const resultsDir = path.join(outputDir, 'test-results')

  await fs.mkdir(outputDir, { recursive: true })
  await fs.mkdir(resultsDir, { recursive: true })

  const configContent = [
    'module.exports = {',
    `  testDir: ${JSON.stringify(path.dirname(specFile))},`,
    `  outputDir: ${JSON.stringify(resultsDir)},`,
    `  use: { baseURL: ${JSON.stringify(input.base_url)}, trace: 'on', video: 'retain-on-failure', screenshot: 'only-on-failure' },`,
    `  reporter: [['json', { outputFile: ${JSON.stringify(reportPath)} }]],`,
    '};',
    '',
  ].join('\n')

  await fs.writeFile(configPath, configContent, 'utf8')

  const runnerArgs = ['playwright', 'test', specFile, '--config', configPath]
  const command = process.platform === 'win32' ? 'npx.cmd' : 'npx'

  const { code, stderr } = await new Promise<{ code: number; stderr: string }>((resolve) => {
    const child = childProcess.spawn(command, runnerArgs, {
      cwd: outputDir,
      env: {
        ...process.env,
        BASE_URL: input.base_url,
        PLAYWRIGHT_GENERATED_PROVIDED_INPUTS: JSON.stringify(input.provided_inputs ?? {}),
      },
      windowsHide: true,
      stdio: ['ignore', 'ignore', 'pipe'],
    })

    let errorOutput = ''
    child.stderr.setEncoding('utf8')
    child.stderr.on('data', (chunk: string) => {
      errorOutput += chunk
    })

    child.on('close', (exitCode) => {
      resolve({ code: exitCode ?? 1, stderr: errorOutput.trim() })
    })
  })

  const artifacts = await collectArtifacts(outputDir)
  const durationMs = Date.now() - startedAt

  if (code === 0) {
    return {
      status: 'passed',
      duration_ms: durationMs,
      execution_trace: ['Generated Playwright spec passed.'],
      artifacts,
      error_type: null,
      error_message: null,
    }
  }

  const mapped = mapGeneratedSpecFailure(stderr || 'Generated Playwright spec failed.')

  return {
    status: mapped.status,
    duration_ms: durationMs,
    execution_trace: [mapped.error_message ?? 'Generated Playwright spec failed.'],
    artifacts,
    error_type: mapped.error_type,
    error_message: mapped.error_message,
    failure_source: mapped.failure_source,
  }
}

async function collectArtifacts(outputDir: string): Promise<string[]> {
  const artifacts: string[] = []

  async function walk(currentPath: string): Promise<void> {
    const entries = await fs.readdir(currentPath, { withFileTypes: true })
    for (const entry of entries) {
      const absolutePath = path.join(currentPath, entry.name)
      if (entry.isDirectory()) {
        await walk(absolutePath)
        continue
      }

      if (/\.(zip|png|webm|json)$/i.test(entry.name)) {
        artifacts.push(path.relative(outputDir, absolutePath).replaceAll('\\', '/'))
      }
    }
  }

  await walk(outputDir)
  return artifacts.sort()
}

async function main(): Promise<void> {
  const inputPath = process.argv[2]
  if (!inputPath) {
    process.stdout.write(
      `${JSON.stringify({
        status: 'blocked',
        duration_ms: 0,
        execution_trace: [],
        artifacts: [],
        error_type: 'missing_input',
        error_message: 'Missing execution input path.',
      })}\n`,
    )
    process.exit(1)
  }

  try {
    const input = await readExecutionInput(inputPath)
    const result = await executeGeneratedSpec(input)
    process.stdout.write(`${JSON.stringify(result)}\n`)
    process.exit(result.status === 'passed' ? 0 : 1)
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error)
    process.stdout.write(
      `${JSON.stringify({
        status: 'blocked',
        duration_ms: 0,
        execution_trace: [message],
        artifacts: [],
        error_type: 'generated_spec_execution_failed',
        error_message: message,
      })}\n`,
    )
    process.exit(1)
  }
}

if (require.main === module) {
  void main()
}
