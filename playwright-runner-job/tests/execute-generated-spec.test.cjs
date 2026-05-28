const test = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs/promises')
const os = require('node:os')
const path = require('node:path')

test('readExecutionInput reads generated spec payload from disk', async () => {
  const { readExecutionInput } = require('../dist/agents/executeGeneratedSpec.js')
  const tempDir = await fs.mkdtemp(path.join(os.tmpdir(), 'generated-spec-'))
  const inputPath = path.join(tempDir, 'input.json')

  await fs.writeFile(
    inputPath,
    JSON.stringify({
      run_id: 'run-1',
      base_url: 'https://example.test',
      spec_file: './generated.spec.ts',
      output_dir: './out',
      provided_inputs: { username: 'demo' },
    }),
    'utf8',
  )

  const payload = await readExecutionInput(inputPath)
  assert.equal(payload.run_id, 'run-1')
  assert.equal(payload.provided_inputs.username, 'demo')
})

test('initial navigation failure maps to url_unreachable', async () => {
  const { mapGeneratedSpecFailure } = require('../dist/agents/executeGeneratedSpec.js')

  const result = mapGeneratedSpecFailure('net::ERR_NAME_NOT_RESOLVED while navigating to https://missing.test')

  assert.equal(result.status, 'blocked')
  assert.equal(result.error_type, 'url_unreachable')
  assert.equal(result.failure_source.phase, 'initial_navigation')
})

test('failed assertion maps to failed status', async () => {
  const { mapGeneratedSpecFailure } = require('../dist/agents/executeGeneratedSpec.js')

  const result = mapGeneratedSpecFailure('Error: expect(locator).toContainText("Welcome")')

  assert.equal(result.status, 'failed')
  assert.equal(result.error_type, 'assertion_failed')
  assert.equal(result.failure_source.phase, 'assert')
})

test('generic execution success returns structured passed result', async () => {
  const { executeGeneratedSpec } = require('../dist/agents/executeGeneratedSpec.js')
  const childProcess = require('node:child_process')

  const tempDir = await fs.mkdtemp(path.join(os.tmpdir(), 'generated-spec-exec-'))
  const specFile = path.join(tempDir, 'generated.spec.ts')
  await fs.writeFile(specFile, 'test("ok", async () => {});', 'utf8')

  const originalSpawn = childProcess.spawn
  childProcess.spawn = () => {
    const { EventEmitter } = require('node:events')
    const child = new EventEmitter()
    child.stderr = new EventEmitter()
    child.stderr.setEncoding = () => {}
    process.nextTick(() => child.emit('close', 0))
    return child
  }

  try {
    const result = await executeGeneratedSpec({
      run_id: 'run-2',
      base_url: 'https://example.test',
      spec_file: specFile,
      output_dir: path.join(tempDir, 'out'),
      provided_inputs: {},
    })

    assert.equal(result.status, 'passed')
    assert.equal(result.error_type, null)
    assert.ok(Array.isArray(result.artifacts))
  } finally {
    childProcess.spawn = originalSpawn
  }
})
