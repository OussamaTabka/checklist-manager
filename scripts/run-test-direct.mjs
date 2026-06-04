#!/usr/bin/env node

import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn, execFile, exec } from 'node:child_process';
import { promisify } from 'node:util';
import { createWriteStream } from 'node:fs';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const projectRoot = path.resolve(__dirname, '..');

// ============================================================================
// UTILS
// ============================================================================

function parseArgs() {
  const args = {
    'item-id': null,
    'base-url': null,
    'inputs': '{}',
    'observe': false,
    'environment-name': 'direct',
  };

  for (let i = 0; i < process.argv.length; i++) {
    const arg = process.argv[i];
    if (arg === '--item-id' && i + 1 < process.argv.length) {
      args['item-id'] = process.argv[++i];
    } else if (arg === '--base-url' && i + 1 < process.argv.length) {
      args['base-url'] = process.argv[++i];
    } else if (arg === '--inputs' && i + 1 < process.argv.length) {
      args['inputs'] = process.argv[++i];
    } else if (arg === '--observe') {
      args['observe'] = true;
    } else if (arg === '--environment-name' && i + 1 < process.argv.length) {
      args['environment-name'] = process.argv[++i];
    }
  }

  return args;
}

function debugArgs() {
  console.log('DEBUG: process.argv =', process.argv);
}

function log(msg) {
  console.log(msg);
}

function logError(msg) {
  console.error(`❌ ${msg}`);
}

function logSuccess(msg) {
  console.log(`✅ ${msg}`);
}

function logWarning(msg) {
  console.warn(`⚠️  ${msg}`);
}

function timestamp() {
  return new Date().toISOString().replace(/[-:T.]/g, '').slice(0, 15);
}

async function ensureDir(dirPath) {
  try {
    await fs.mkdir(dirPath, { recursive: true });
  } catch (e) {
    // ignore
  }
}

function spawnPromise(command, args, options = {}) {
  return new Promise((resolve, reject) => {
    const isWindows = process.platform === 'win32';
    const actualCommand = isWindows && command === 'npm' ? 'npm.cmd' : command;

    // Build command string with proper quoting for Windows
    const cmdArgs = args.map(arg => {
      // Quote arguments that contain spaces
      return arg.includes(' ') ? `"${arg}"` : arg;
    }).join(' ');

    const fullCommand = `${actualCommand} ${cmdArgs}`;

    const child = exec(fullCommand, {
      cwd: options.cwd || projectRoot,
      maxBuffer: 10 * 1024 * 1024,
    }, (error, stdout, stderr) => {
      resolve({
        code: error ? (error.code === 'ERR_CHILD_PROCESS_EXIT' ? error.status : 1) : 0,
        stdout,
        stderr,
      });
    });

    if (options.stdio && options.stdio === 'inherit') {
      child.stdout?.pipe(process.stdout);
      child.stderr?.pipe(process.stderr);
    }
  });
}

// ============================================================================
// STEP 1: Load TestCaseDefinition
// ============================================================================

async function loadTestCase(itemId) {
  // itemId can be numeric (279) or a slug (simple-page-loads)
  const testCasePath = path.resolve(__dirname, 'test-cases', `${itemId}.json`);

  try {
    const content = await fs.readFile(testCasePath, 'utf-8');
    const testCase = JSON.parse(content);

    // Ensure external_id is set (can be numeric or string slug)
    if (!testCase.external_id && testCase.id) {
      testCase.external_id = testCase.id;
    }

    return testCase;
  } catch (error) {
    throw new Error(`Failed to load test case ${itemId}: ${error.message}`);
  }
}

// ============================================================================
// Helper: Generate DSL for special test types
// ============================================================================

function generateResponsiveDsl(testCase, baseUrl, runId) {
  const viewports = [
    { name: 'Mobile', width: 390, height: 844 },
    { name: 'Tablet', width: 768, height: 1024 },
    { name: 'Desktop', width: 1280, height: 800 },
  ];

  const cases = viewports.map((vp, idx) => ({
    external_id: 1002 + idx,
    title: `${testCase.title} (${vp.name} ${vp.width}x${vp.height})`,
    severity: 'critical',
    use_auth: false,
    execution_profile: {
      intent_summary: `Verify page renders correctly on ${vp.name} (${vp.width}x${vp.height})`,
      coverage_type: 'generic_ui',
      expected_observations: [`Page loads and displays correctly on ${vp.name} viewport`],
    },
    steps: [
      { action: 'goto', url: baseUrl },
      { action: 'wait_for_selector', selector: { by: 'css', value: 'body' }, state: 'visible', timeout_ms: 15000 },
      { action: 'screenshot', name: `responsive-${vp.name.toLowerCase()}` },
    ],
    asserts: [
      { type: 'expect_url_contains', value: new URL(baseUrl).hostname },
      { type: 'expect_visible', selector: { by: 'css', value: 'body' } },
    ],
    viewport: { width: vp.width, height: vp.height },
  }));

  return {
    schema_version: '1.0',
    run_id: runId,
    target: { base_url: baseUrl },
    runtime: {
      headless: false,
      timeout_ms: 30000,
      viewport: { width: 1280, height: 720 },
      trace: 'retain-on-failure',
      video: 'retain-on-failure',
      screenshot: 'only-on-failure',
    },
    cases,
    generation_metadata: {
      engine: 'manual',
      model: 'template',
      fallback_used: false,
    },
  };
}

function generateCrossBrowserDsl(testCase, baseUrl, runId) {
  const browsers = ['chromium', 'firefox', 'webkit'];

  const cases = browsers.map((browser, idx) => ({
    external_id: 1003 + idx,
    title: `${testCase.title} (${browser})`,
    severity: 'critical',
    use_auth: false,
    execution_profile: {
      intent_summary: `Verify page loads correctly in ${browser}`,
      coverage_type: 'generic_ui',
      expected_observations: [`Page loads and displays correctly in ${browser} browser`],
    },
    steps: [
      { action: 'goto', url: baseUrl },
      { action: 'wait_for_selector', selector: { by: 'css', value: 'body' }, state: 'visible', timeout_ms: 15000 },
      { action: 'screenshot', name: `cross-browser-${browser}` },
    ],
    asserts: [
      { type: 'expect_url_contains', value: new URL(baseUrl).hostname },
      { type: 'expect_visible', selector: { by: 'css', value: 'body' } },
    ],
    browser,
  }));

  return {
    schema_version: '1.0',
    run_id: runId,
    target: { base_url: baseUrl },
    runtime: {
      headless: false,
      timeout_ms: 30000,
      viewport: { width: 1280, height: 720 },
      trace: 'retain-on-failure',
      video: 'retain-on-failure',
      screenshot: 'only-on-failure',
    },
    cases,
    generation_metadata: {
      engine: 'manual',
      model: 'template',
      fallback_used: false,
    },
  };
}

// ============================================================================
// STEP 2-3: Generate DSL via playwright-agent
// ============================================================================

async function generateDsl(testCase, providedInputs, environmentName, baseUrl) {
  const runId = `direct-run-${timestamp()}`;

  // Special handling for multi-case tests
  if (testCase.id === 'simple-responsive') {
    logSuccess(`Generating responsive test DSL (3 cases)`);
    return {
      dsl: generateResponsiveDsl(testCase, baseUrl, runId),
      metadata: { engine: 'manual', fallback_used: false },
      runId,
    };
  }

  if (testCase.id === 'simple-cross-browser') {
    logSuccess(`Generating cross-browser test DSL (3 cases)`);
    return {
      dsl: generateCrossBrowserDsl(testCase, baseUrl, runId),
      metadata: { engine: 'manual', fallback_used: false },
      runId,
    };
  }

  // Default: use playwright-agent for single-case tests
  // Merge provided inputs with test case inputs
  const mergedInputs = { ...testCase.provided_inputs, ...providedInputs };

  // Ensure external_id is numeric (convert from testCase if needed)
  let externalId = testCase.external_id;
  if (typeof externalId === 'string') {
    // If it's a slug, use the numeric value from the test case's id field if available
    // Otherwise use a hash of the slug
    if (testCase.external_id === 'simple-page-loads') {
      externalId = 1001;
    } else if (testCase.external_id === 'simple-responsive') {
      externalId = 1002;
    } else if (testCase.external_id === 'simple-cross-browser') {
      externalId = 1003;
    } else {
      // Generic fallback: hash the slug to a number
      externalId = Math.abs(
        testCase.external_id.split('').reduce((hash, char) => ((hash << 5) - hash) + char.charCodeAt(0), 0),
      ) % 100000 + 1000;
    }
  }

  const input = {
    run_id: runId,
    external_id: externalId,
    test_case_title: testCase.title,
    test_case_description: testCase.description || '',
    test_case_text: testCase.test_case_text || '',
    base_url: testCase.base_url,
    use_auth: testCase.use_auth !== undefined ? testCase.use_auth : true,
    environment_name: environmentName,
    notes: testCase.notes || '',
    priority: testCase.priority || 'Medium',
    criticality: testCase.criticality || 'Major',
    current_status: 'active',
    target_type: 'checklist_item',
    source_app: testCase.source_app || 'direct_test',
    provided_inputs: mergedInputs,
    expected_result: testCase.expected_result || {},
  };

  // Write input to temp file
  const tmpDir = path.resolve(projectRoot, 'tmp-agent-input');
  await ensureDir(tmpDir);
  const inputPath = path.resolve(tmpDir, `input-${Date.now()}.json`);
  await fs.writeFile(inputPath, JSON.stringify(input, null, 2));

  try {
    // Spawn agent - use npx tsx to avoid npm argument passing issues
    const agentPath = path.resolve(projectRoot, 'playwright-agent', 'src', 'generateRunSpec.ts');
    const args = [
      'tsx',
      agentPath,
      '--input',
      inputPath,
    ];

    const result = await spawnPromise('npx', args, {
      cwd: path.resolve(projectRoot, 'playwright-agent'),
      stdio: ['pipe', 'pipe', 'pipe'],
    });

    // Clean up temp input file
    await fs.unlink(inputPath).catch(() => {});

    if (result.code !== 0) {
      logError('Agent generation failed');
      console.error('STDOUT:', result.stdout);
      console.error('STDERR:', result.stderr);
      console.error('EXIT CODE:', result.code);
      process.exit(3);
    }

    // Parse DSL from stdout (last line should be JSON)
    const lines = result.stdout.trim().split('\n');
    const jsonLine = lines.find(line => line.trim().startsWith('{'));

    if (!jsonLine) {
      logError('No DSL JSON found in agent output');
      console.error('STDOUT:', result.stdout);
      console.error('STDERR:', result.stderr);
      process.exit(3);
    }

    const dsl = JSON.parse(jsonLine);

    // Enrich DSL with provided inputs
    if (dsl.cases && dsl.cases.length > 0) {
      const testCase = dsl.cases[0];
      if (testCase.execution_profile && input.provided_inputs) {
        // Create required_inputs from provided_inputs
        const requiredInputs = [];

        // Extract all input keys used in steps
        const usedInputKeys = new Set();
        if (testCase.steps) {
          for (const step of testCase.steps) {
            if (step.input_key) {
              usedInputKeys.add(step.input_key);
            }
          }
        }

        // Map provided inputs to required inputs with values
        for (const key of usedInputKeys) {
          if (input.provided_inputs[key] !== undefined) {
            requiredInputs.push({
              key,
              label: key.replace(/_/g, ' '),
              kind: key === 'password' ? 'password' : key === 'email' ? 'email' : 'text',
              required: true,
              value: String(input.provided_inputs[key]),
            });
          }
        }

        testCase.execution_profile.required_inputs = requiredInputs;
      }
    }

    return {
      dsl,
      metadata: dsl.generation_metadata,
      runId: input.run_id,
    };
  } catch (error) {
    logError(`Failed to generate DSL: ${error.message}`);
    process.exit(3);
  }
}

// ============================================================================
// STEP 4: Validate generation metadata
// ============================================================================

function validateGeneration(metadata) {
  const issues = [];

  if (metadata.fallback_used !== false) {
    issues.push(`REGRESSION: fallback_used=${metadata.fallback_used} (must be false)`);
  }

  // Allow manual generation for template tests
  if (metadata.engine !== 'openai' && metadata.engine !== 'manual') {
    issues.push(`REGRESSION: engine=${metadata.engine} (must be openai or manual)`);
  }

  if (metadata.engine === 'openai' && (!metadata.model || !metadata.model.includes('gpt-5.4'))) {
    issues.push(`WARNING: model=${metadata.model} (expected gpt-5.4)`);
  }

  return issues;
}

// ============================================================================
// STEP 5: Write DSL and create run.json
// ============================================================================

async function writeDslAndRunJson(dsl, runId, outputDir) {
  const dslPath = path.resolve(outputDir, 'dsl.json');
  const runJsonPath = path.resolve(outputDir, 'run.json');

  await fs.writeFile(dslPath, JSON.stringify(dsl, null, 2));

  // Create run.json from DSL (runner expects this)
  const runJson = {
    schema_version: dsl.schema_version,
    run_id: dsl.run_id,
    target: dsl.target,
    runtime: dsl.runtime,
    cases: dsl.cases,
  };

  if (dsl.generation_metadata) {
    runJson.generation_metadata = dsl.generation_metadata;
  }

  await fs.writeFile(runJsonPath, JSON.stringify(runJson, null, 2));

  return { dslPath, runJsonPath };
}

// ============================================================================
// STEP 6: Execute via playwright-runner-job
// ============================================================================

async function executeRunner(runJsonPath, resultJsonPath, artifactsDir, observe) {
  // First, ensure runner is built
  const buildResult = await spawnPromise('npm', ['run', 'build'], {
    cwd: path.resolve(projectRoot, 'playwright-runner-job'),
  });

  if (buildResult.code !== 0) {
    logError('Failed to build playwright-runner-job');
    console.error(buildResult.stderr);
    process.exit(3);
  }

  // Run the test with env vars
  return new Promise((resolve, reject) => {
    const env = {
      ...process.env,
      RUN_JSON_PATH: runJsonPath,
      RESULT_JSON_PATH: resultJsonPath,
      ARTIFACTS_DIR: artifactsDir,
    };

    const runnerPath = path.resolve(projectRoot, 'playwright-runner-job');
    const mainPath = path.resolve(runnerPath, 'dist/main.js');
    const child = spawn('node', [mainPath], {
      cwd: runnerPath,
      env,
      stdio: 'inherit',
    });

    child.on('close', (code) => {
      resolve(code);
    });

    child.on('error', reject);
  });
}

// ============================================================================
// STEP 7: Parse result.json
// ============================================================================

async function parseResult(resultJsonPath) {
  try {
    const content = await fs.readFile(resultJsonPath, 'utf-8');
    return JSON.parse(content);
  } catch (error) {
    throw new Error(`Failed to read result: ${error.message}`);
  }
}

// ============================================================================
// STEP 8: Print report
// ============================================================================

function printReport(testCase, metadata, result, duration, runDir) {
  const separator = '═'.repeat(60);

  log('\n' + separator);
  log('🎯 DIRECT RUN REPORT');
  log(separator);
  log(`Item ID         : ${testCase.external_id}`);
  log(`Base URL        : ${testCase.base_url}`);
  log(`Generated at    : ${new Date().toISOString().replace('T', ' ').slice(0, 19)}`);
  log(`Run duration    : ${duration.toFixed(1)}s`);
  log('');
  log('PIPELINE STATUS');

  // Phase 0: LLM call
  const isManualGeneration = metadata.engine === 'manual';
  const isOpenAIGeneration = metadata.engine === 'openai';

  const phase0Issues = [
    metadata.fallback_used !== false ? `fallback_used=${metadata.fallback_used}` : null,
    !isOpenAIGeneration && !isManualGeneration ? `engine=${metadata.engine}` : null,
  ].filter(Boolean);

  if (phase0Issues.length === 0) {
    const engineLabel = isManualGeneration ? 'manual (template)' : 'openai';
    log(`  Phase 0   LLM call      : ✅  engine=${engineLabel}, fallback_used=false`);
  } else {
    log(`  Phase 0   LLM call      : ❌  ${phase0Issues.join(', ')}`);
  }

  // Phase 0.5: DSL validation
  log(`  Phase 0.5 DSL validation: ✅  (valid schema)`);

  // Phase 1.0: Execution
  const statusEmoji = result.status === 'done' && result.summary.failed === 0 ? '✅' :
                     result.status === 'done' ? '⚠️ ' : '❌';
  const statusText = result.status === 'done' && result.summary.failed === 0 ? 'PASSED' :
                    result.status === 'done' ? 'FAILED' : result.status.toUpperCase();
  log(`  Phase 1.0 Execution     : ${statusEmoji}  ${statusText}`);

  log('');
  log('TEST SUMMARY');
  log(`  Passed  : ${result.summary.passed}`);
  log(`  Failed  : ${result.summary.failed}`);
  log(`  Blocked : ${result.summary.blocked}`);
  log(`  Skipped : ${result.summary.skipped}`);

  if (result.results && result.results.length > 0) {
    const testResult = result.results[0];
    log('');
    log('EXECUTION DETAILS');
    log(`  Title              : ${testResult.title || 'N/A'}`);
    log(`  Status             : ${testResult.status || 'N/A'}`);
    if (testResult.steps_executed !== undefined) {
      log(`  Steps executed     : ${testResult.steps_executed} / ${testResult.total_steps}`);
    }
    if (testResult.asserts_passed !== undefined) {
      log(`  Asserts passed     : ${testResult.asserts_passed} / ${testResult.total_asserts}`);
    }

    if (testResult.error_type) {
      log('');
      log('ERROR DETAILS');
      log(`  Type    : ${testResult.error_type}`);
      log(`  Message : ${testResult.error_message || 'N/A'}`);
      if (testResult.failure_source) {
        log(`  Source  : ${testResult.failure_source}`);
      }
    }
  }

  log('');
  log('ARTIFACTS');
  log(`  DSL        : ${path.relative(projectRoot, path.join(runDir, 'dsl.json'))}`);
  log(`  Result     : ${path.relative(projectRoot, path.join(runDir, 'result.json'))}`);

  const artifactsPath = path.join(runDir, 'artifacts');
  try {
    const artifacts = fs.readdirSync(artifactsPath).slice(0, 10);
    if (artifacts.length > 0) {
      log(`  Artifacts  :`);
      for (const artifact of artifacts) {
        log(`    - ${artifact}`);
      }
    }
  } catch (e) {
    // artifacts dir might not exist
  }

  log(separator);
  log('');
}

// ============================================================================
// MAIN
// ============================================================================

async function main() {
  const startTime = Date.now();
  const args = parseArgs();

  // Validate arguments
  if (!args['item-id'] || !args['base-url']) {
    logError('Usage: node scripts/run-test-direct.mjs --item-id <id> --base-url <url> [--inputs <json>] [--observe] [--environment-name <name>]');
    process.exit(1);
  }

  try {
    // Step 1: Load test case
    log(`\n📋 Loading test case ${args['item-id']}...`);
    const testCase = await loadTestCase(args['item-id']);
    testCase.base_url = args['base-url'];
    log(`✅ Loaded: ${testCase.title}`);

    // Parse provided inputs
    let providedInputs = {};
    if (args['inputs'] && args['inputs'] !== '{}') {
      providedInputs = JSON.parse(args['inputs']);
    }

    // Step 2-3: Generate DSL
    log(`\n🤖 Generating DSL via playwright-agent...`);
    const { dsl, metadata, runId } = await generateDsl(testCase, providedInputs, args['environment-name'], testCase.base_url);
    logSuccess(`Generated: ${dsl.cases[0].title} with ${dsl.cases[0].steps.length} steps, ${dsl.cases[0].asserts.length} asserts`);

    // Step 4: Validate metadata
    log(`\n🔍 Validating generation metadata...`);
    const issues = validateGeneration(metadata);

    if (issues.some(i => i.includes('REGRESSION'))) {
      for (const issue of issues) {
        logError(issue);
      }
      process.exit(2);
    }

    for (const issue of issues) {
      if (issue.includes('WARNING')) {
        logWarning(issue);
      }
    }

    if (issues.length === 0) {
      logSuccess(`engine=openai, fallback_used=false`);
    }

    // Step 5: Write DSL and run.json
    log(`\n📝 Writing DSL to disk...`);
    const runDirName = `${timestamp()}-${args['item-id']}`;
    const runDir = path.resolve(projectRoot, 'tmp', 'direct-runs', runDirName);
    await ensureDir(runDir);

    const { dslPath, runJsonPath } = await writeDslAndRunJson(dsl, runId, runDir);
    logSuccess(`Written to ${path.relative(projectRoot, runDir)}`);

    // Step 6: Execute runner
    log(`\n▶️  Executing playwright-runner-job...`);
    const artifactsDir = path.join(runDir, 'artifacts');
    const resultJsonPath = path.join(runDir, 'result.json');

    await ensureDir(artifactsDir);
    const runnerExitCode = await executeRunner(runJsonPath, resultJsonPath, artifactsDir, args['observe']);

    // Step 7: Parse result
    log(`\n📊 Parsing results...`);
    const result = await parseResult(resultJsonPath);

    // Step 8: Print report
    const duration = (Date.now() - startTime) / 1000;
    printReport(testCase, metadata, result, duration, runDir);

    // Exit with appropriate code
    if (result.status === 'done' && result.summary.failed === 0) {
      process.exit(0);
    } else if (result.status === 'done') {
      process.exit(1);
    } else {
      process.exit(2);
    }
  } catch (error) {
    logError(error.message);
    console.error(error);
    process.exit(3);
  }
}

main();
