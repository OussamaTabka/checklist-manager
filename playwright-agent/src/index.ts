import { config } from './config.js';
import { classifyItem, type Scenario } from './classify.js';
import { runOrchestratorFromDsl } from './orchestratorCli.js';
import fs from 'node:fs/promises';
import path from 'node:path';

type RunRequestDslV1 = {
  schema_version: '1.0';
  run_id: string;
  target: { base_url: string };
  runtime: {
    headless: boolean;
    timeout_ms: number;
    viewport: { width: number; height: number };
    trace: 'retain-on-failure' | 'off';
    video: 'retain-on-failure' | 'off';
    screenshot: 'only-on-failure' | 'off';
  };
  cases: Array<{
    external_id: number;
    title: string;
    severity: 'critical' | 'major' | 'minor';
    use_auth: boolean;
    steps: Array<Record<string, unknown>>;
    asserts: Array<Record<string, unknown>>;
  }>;
};

function externalIdForScenario(scenario: Scenario): number {
  switch (scenario) {
    case 'dashboard':
      return 1;
    case 'checklists':
      return 2;
    case 'projects':
      return 3;
    case 'users':
      return 4;
    case 'login':
      return 4;
    case 'unknown':
      return 1;
  }
}

function scenarioToPath(scenario: Scenario): string {
  switch (scenario) {
    case 'dashboard':
      return '/dashboard';
    case 'checklists':
      return '/checklists';
    case 'projects':
      return '/projects';
    case 'users':
      return '/users';
    case 'login':
      return '/login';
    case 'unknown':
      return '/dashboard';
  }
}

function scenarioToTestId(scenario: Scenario): string {
  switch (scenario) {
    case 'dashboard':
      return 'dashboard-stats';
    case 'checklists':
      return 'checklists-table';
    case 'projects':
      return 'projects-table';
    case 'users':
      return 'users-table';
    case 'login':
      return 'login-btn-submit';
    case 'unknown':
      return 'dashboard-stats';
  }
}

function buildRunSpec(scenario: Scenario): RunRequestDslV1 {
  const now = new Date();
  const runId = `agent-${now.getFullYear()}${String(now.getMonth() + 1).padStart(2, '0')}${String(
    now.getDate()
  ).padStart(2, '0')}-${String(now.getHours()).padStart(2, '0')}${String(now.getMinutes()).padStart(2, '0')}${String(
    now.getSeconds()
  ).padStart(2, '0')}`;

  const title = `Agent classified: ${scenario}`;

  return {
    schema_version: '1.0',
    run_id: runId,
    target: {
      base_url: config.targetBaseUrl,
    },
    runtime: {
      headless: true,
      timeout_ms: 30000,
      viewport: { width: 1280, height: 720 },
      trace: 'retain-on-failure',
      video: 'retain-on-failure',
      screenshot: 'only-on-failure',
    },
    cases: [
      {
        external_id: externalIdForScenario(scenario),
        title,
        severity: 'critical',
        use_auth: scenario !== 'login',
        steps: [{ action: 'goto', url: scenarioToPath(scenario) }],
        asserts: [{ type: 'expect_visible', selector: { by: 'testid', id: scenarioToTestId(scenario) } }],
      },
    ],
  };
}

function extractFirstJsonObject(text: string): string | null {
  const start = text.indexOf('{');
  const end = text.lastIndexOf('}');
  if (start === -1 || end === -1 || end <= start) return null;
  return text.slice(start, end + 1);
}

function isOllamaTimeoutError(err: unknown): err is Error {
  return err instanceof Error && /timed out/i.test(err.message);
}

async function main() {
  console.log('ollama:', {
    baseUrl: config.ollamaBaseUrl,
    model: config.ollamaModel,
    fallbackModel: config.ollamaFallbackModel,
    timeoutMs: config.ollamaTimeoutMs,
  });

  let modelUsed = config.ollamaModel;
  let classification;

  try {
    classification = await classifyItem({
      baseUrl: config.ollamaBaseUrl,
      model: modelUsed,
      timeoutMs: config.ollamaTimeoutMs,
      title: 'Dashboard loads',
      description: 'Open dashboard page and verify stats are visible',
    });
  } catch (err) {
    const fallbackModel = config.ollamaFallbackModel;
    const shouldRetryWithFallback =
      fallbackModel.length > 0 && fallbackModel !== modelUsed && isOllamaTimeoutError(err);

    if (!shouldRetryWithFallback) {
      throw err;
    }

    console.warn(`Primary model ${modelUsed} timed out; retrying classification with ${fallbackModel}`);
    modelUsed = fallbackModel;
    classification = await classifyItem({
      baseUrl: config.ollamaBaseUrl,
      model: modelUsed,
      timeoutMs: config.ollamaTimeoutMs,
      title: 'Dashboard loads',
      description: 'Open dashboard page and verify stats are visible',
    });
  }

  const runSpec = buildRunSpec(classification.scenario);
  const tmpDir = path.resolve(process.cwd(), 'tmp');
  const dslPath = path.join(tmpDir, 'run.json');

  await fs.mkdir(tmpDir, { recursive: true });
  await fs.writeFile(dslPath, `${JSON.stringify(runSpec, null, 2)}\n`, 'utf8');

  const orchestrator = await runOrchestratorFromDsl({
    orchestratorDir: config.orchestratorDir,
    dslPath,
  });

  const orchestratorJsonText = extractFirstJsonObject(orchestrator.stdout);
  const orchestratorJson = orchestratorJsonText ? JSON.parse(orchestratorJsonText) : null;

  console.log('Ollama model:', modelUsed);
  console.log('Classification:', classification);
  console.log('DSL path:', dslPath);
  console.log('Orchestrator exit code:', orchestrator.exitCode);
  console.log('Orchestrator result:', orchestratorJson);

  if (orchestrator.exitCode !== 0) {
    throw new Error(
      `Orchestrator failed with code ${orchestrator.exitCode}\nSTDOUT:\n${orchestrator.stdout}\nSTDERR:\n${orchestrator.stderr}`
    );
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
