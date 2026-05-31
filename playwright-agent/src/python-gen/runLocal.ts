/**
 * playwright-agent/src/python-gen/runLocal.ts
 *
 * Local (headed) execution helper for the Python-script path.
 * Spawns wrapper.py as a subprocess with headless=false, mirrors the same
 * env-var contract used by the Docker path (RUN_JSON_PATH / RESULT_JSON_PATH /
 * ARTIFACTS_DIR), then reads and prints the resulting result.json.
 *
 * Usage:
 *   node dist/python-gen/runLocal.js \
 *     --run-spec  <path>           path to the JSON produced by generateScript.js
 *    [--wrapper   <path>]          path to wrapper.py  (default: ../../../../python-runner/wrapper.py)
 *    [--result-dir <dir>]          where to write run.json / result.json / artifacts
 *                                  (default: OS temp dir)
 *    [--headless]                  run headless instead of headed (default: headed)
 */

import { spawn } from 'node:child_process'
import fs from 'node:fs/promises'
import os from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)

// At runtime this file is at playwright-agent/dist/python-gen/runLocal.js
// Workspace root is 3 levels up: dist/python-gen → dist → playwright-agent → workspace
const WORKSPACE_ROOT = path.resolve(__dirname, '../../../')
const DEFAULT_WRAPPER = path.join(WORKSPACE_ROOT, 'python-runner', 'wrapper.py')

function getArg(name: string): string | undefined {
  const idx = process.argv.findIndex((a) => a === name)
  return idx !== -1 ? process.argv[idx + 1] : undefined
}

function hasFlag(name: string): boolean {
  return process.argv.includes(name)
}

async function main(): Promise<void> {
  const runSpecArg = getArg('--run-spec')
  if (!runSpecArg) {
    process.stderr.write(
      'Usage: node dist/python-gen/runLocal.js --run-spec <path> [--wrapper <path>] [--result-dir <dir>] [--headless]\n',
    )
    process.exit(1)
  }

  const wrapperPath = getArg('--wrapper') ?? DEFAULT_WRAPPER
  const resultDirArg = getArg('--result-dir')
  const forceHeadless = hasFlag('--headless')

  const absRunSpec = path.resolve(runSpecArg)

  // Read the run spec and modify headless flag
  const rawSpec = await fs.readFile(absRunSpec, 'utf8')
  const runSpec = JSON.parse(rawSpec) as Record<string, unknown>

  const runtime = (typeof runSpec['runtime'] === 'object' && runSpec['runtime'] !== null
    ? runSpec['runtime']
    : {}) as Record<string, unknown>
  runtime['headless'] = forceHeadless
  runSpec['runtime'] = runtime

  // Set up work directory
  const workDir = resultDirArg
    ? path.resolve(resultDirArg)
    : await fs.mkdtemp(path.join(os.tmpdir(), 'python-run-'))

  await fs.mkdir(workDir, { recursive: true })

  const runJsonPath = path.join(workDir, 'run.json')
  const resultJsonPath = path.join(workDir, 'result.json')
  const artifactsDir = path.join(workDir, 'artifacts')

  await fs.writeFile(runJsonPath, JSON.stringify(runSpec, null, 2) + '\n', 'utf8')
  await fs.mkdir(artifactsDir, { recursive: true })

  process.stderr.write(`[runLocal] work_dir=${workDir}\n`)
  process.stderr.write(`[runLocal] wrapper=${wrapperPath}\n`)
  process.stderr.write(`[runLocal] headless=${String(forceHeadless)}\n`)

  // Prefer python3 on POSIX, python on Windows
  const pythonBin = process.platform === 'win32' ? 'python' : 'python3'

  const child = spawn(pythonBin, [wrapperPath], {
    stdio: 'inherit',
    env: {
      ...process.env,
      RUN_JSON_PATH: runJsonPath,
      RESULT_JSON_PATH: resultJsonPath,
      ARTIFACTS_DIR: artifactsDir,
    },
  })

  const exitCode = await new Promise<number>((resolve) => {
    child.on('close', (code) => { resolve(code ?? 3) })
    child.on('error', (err) => {
      process.stderr.write(`[runLocal] spawn error: ${err.message}\n`)
      resolve(3)
    })
  })

  process.stderr.write(`[runLocal] wrapper exited with code ${exitCode}\n`)

  // Read and echo result.json to stdout
  try {
    const resultRaw = await fs.readFile(resultJsonPath, 'utf8')
    process.stdout.write(resultRaw)
    process.stderr.write(`[runLocal] result.json → ${resultJsonPath}\n`)
    process.stderr.write(`[runLocal] artifacts  → ${artifactsDir}\n`)
  } catch {
    process.stderr.write(`[runLocal] WARNING: result.json was not produced by wrapper\n`)
  }

  process.exit(exitCode)
}

void main()
