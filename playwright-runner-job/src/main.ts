import { promises as fs } from 'node:fs'
import path from 'node:path'
import { ensureDir } from './engine/artifacts'
import { validateRunRequest } from './engine/dsl'
import { executeRun } from './engine/executor'
import type { RunResultV1 } from './engine/results'

const EXIT_OK = 0
const EXIT_TESTS_FAILED = 1
const EXIT_INVALID_SPEC = 2
const EXIT_RUNNER_ERROR = 3

function getEnvPath(name: string, fallback: string): string {
  const value = process.env[name]
  if (!value || value.trim().length === 0) {
    return fallback
  }
  return value
}

function getOptionalEnvPath(name: string): string | undefined {
  const value = process.env[name]
  if (!value || value.trim().length === 0) {
    return undefined
  }

  return value
}

async function writeJsonFile(filePath: string, payload: unknown): Promise<void> {
  const dir = path.dirname(filePath)
  await ensureDir(dir)
  await fs.writeFile(filePath, `${JSON.stringify(payload, null, 2)}\n`, 'utf8')
}

function createBaseResult(
  runId: string,
  status: RunResultV1['status'],
  runnerRunId: string,
): RunResultV1 {
  return {
    schema_version: '1.0',
    run_id: runId,
    runner_run_id: runnerRunId,
    status,
    summary: {
      passed: 0,
      failed: 0,
      blocked: 0,
      skipped: 0,
    },
    results: [],
  }
}

async function readRunJson(runJsonPath: string): Promise<unknown> {
  const content = await fs.readFile(runJsonPath, 'utf8')
  return JSON.parse(content) as unknown
}

async function main(): Promise<void> {
  const runJsonPath = getEnvPath('RUN_JSON_PATH', '/work/run.json')
  const resultJsonPath = getEnvPath('RESULT_JSON_PATH', '/work/result.json')
  const artifactsDir = getEnvPath('ARTIFACTS_DIR', '/work/artifacts')
  const liveTracePath = getOptionalEnvPath('LIVE_TRACE_PATH')
  const workRoot = path.dirname(resultJsonPath)

  const runnerRunId = `runner-${Date.now()}`

  let runRaw: unknown

  try {
    runRaw = await readRunJson(runJsonPath)
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error)
    console.error(`[runner_error] cannot read run.json: ${message}`)

    const result = createBaseResult('unknown', 'runner_error', runnerRunId)
    await writeJsonFile(resultJsonPath, result)
    process.exit(EXIT_RUNNER_ERROR)
  }

  const validation = validateRunRequest(runRaw)

  if (!validation.ok || !validation.value) {
    const runId =
      typeof runRaw === 'object' &&
      runRaw !== null &&
      'run_id' in runRaw &&
      typeof (runRaw as { run_id?: unknown }).run_id === 'string'
        ? ((runRaw as { run_id: string }).run_id as string)
        : 'unknown'

    console.error('[invalid_spec] run.json validation failed')
    for (const err of validation.errors) {
      console.error(` - ${err}`)
    }

    const result = createBaseResult(runId, 'invalid_spec', runnerRunId)
    await writeJsonFile(resultJsonPath, result)
    process.exit(EXIT_INVALID_SPEC)
  }

  const request = validation.value as NonNullable<typeof validation.value>

  try {
    const runResult = await executeRun(request, {
      artifactsConfig: {
        workRoot,
        artifactsRoot: artifactsDir,
      },
      liveTracePath,
    })

    await writeJsonFile(resultJsonPath, runResult)
    if (runResult.status === 'done' && runResult.summary.failed > 0) {
      process.exit(EXIT_TESTS_FAILED)
    }

    process.exit(EXIT_OK)
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error)
    console.error(`[runner_error] ${message}`)

    const result = createBaseResult(request.run_id, 'runner_error', runnerRunId)
    await writeJsonFile(resultJsonPath, result)
    process.exit(EXIT_RUNNER_ERROR)
  }
}

void main()
