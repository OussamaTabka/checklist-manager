#!/usr/bin/env node
import { promises as fs } from 'node:fs';
import path from 'node:path';

const SCENARIOS = ['dashboard', 'checklists', 'projects', 'users', 'login', 'unknown'];
const UNKNOWN_SCENARIO_GUARD_TOKEN = '__agent_unknown_scenario__';

function parseArgs(argv) {
  const args = {
    runsDir: path.resolve(process.cwd(), '..', 'runs'),
    outDir: path.resolve(process.cwd(), 'eval'),
    limit: 300,
  };

  for (let i = 0; i < argv.length; i += 1) {
    const token = argv[i];
    const next = argv[i + 1];

    if (token === '--runsDir' && next) {
      args.runsDir = path.resolve(next);
      i += 1;
    } else if (token === '--outDir' && next) {
      args.outDir = path.resolve(next);
      i += 1;
    } else if (token === '--limit' && next) {
      const value = Number(next);
      if (Number.isFinite(value) && value > 0) {
        args.limit = Math.floor(value);
      }
      i += 1;
    }
  }

  return args;
}

function safeString(value) {
  if (typeof value === 'string') {
    return value.trim();
  }
  if (typeof value === 'number' || typeof value === 'boolean') {
    return String(value);
  }
  return '';
}

function inferFromAsserts(asserts) {
  if (!Array.isArray(asserts)) {
    return 'unknown';
  }

  const serialized = JSON.stringify(asserts).toLowerCase();

  if (serialized.includes(UNKNOWN_SCENARIO_GUARD_TOKEN)) {
    return 'unknown';
  }
  if (/login|sign\s*in|auth|session/.test(serialized)) {
    return 'login';
  }
  if (/dashboard|summary|widget|stats?/.test(serialized)) {
    return 'dashboard';
  }
  if (/checklists?|check\s*items?|items?/.test(serialized)) {
    return 'checklists';
  }
  if (/projects?|versions?|releases?/.test(serialized)) {
    return 'projects';
  }
  if (/users?|admins?|roles?|permissions?/.test(serialized)) {
    return 'users';
  }

  return 'unknown';
}

function inferFromText(title, text) {
  const sample = `${title}\n${text}`.toLowerCase();
  const matches = new Set();

  if (/\blog\s*in\b|\blogin\b|\bauth\b|\bsign\s*in\b|\bsession\b|\breset\s*password\b/.test(sample)) {
    matches.add('login');
  }
  if (/\bdashboard\b|\bhome\b|\bstats?\b|\bsummary\b|\bwidgets?\b/.test(sample)) {
    matches.add('dashboard');
  }
  if (/\bchecklists?\b|\bcheck\s*items?\b|\bitems?\b/.test(sample)) {
    matches.add('checklists');
  }
  if (/\bprojects?\b|\bversions?\b|\breleases?\b/.test(sample)) {
    matches.add('projects');
  }
  if (/\busers?\b|\badmins?\b|\broles?\b|\bpermissions?\b|\baccount\s*management\b/.test(sample)) {
    matches.add('users');
  }

  if (matches.size === 1) {
    return Array.from(matches)[0];
  }

  return 'unknown';
}

async function fileExists(filePath) {
  try {
    await fs.access(filePath);
    return true;
  } catch {
    return false;
  }
}

async function collectRunJsonPaths(runsDir) {
  const entries = await fs.readdir(runsDir, { withFileTypes: true });
  const directories = entries
    .filter((entry) => entry.isDirectory())
    .map((entry) => entry.name)
    .sort((a, b) => b.localeCompare(a));

  const result = [];
  for (const dirName of directories) {
    const candidate = path.join(runsDir, dirName, 'run.json');
    if (await fileExists(candidate)) {
      result.push(candidate);
    }
  }

  return result;
}

async function main() {
  const args = parseArgs(process.argv.slice(2));
  const runJsonPaths = await collectRunJsonPaths(args.runsDir);

  const allSamples = [];
  const dedupe = new Set();

  for (const runFile of runJsonPaths) {
    if (allSamples.length >= args.limit) {
      break;
    }

    let parsed;
    try {
      const raw = await fs.readFile(runFile, 'utf8');
      parsed = JSON.parse(raw);
    } catch {
      continue;
    }

    const runId = safeString(parsed?.run_id) || path.basename(path.dirname(runFile));
    const cases = Array.isArray(parsed?.cases)
      ? parsed.cases
      : Array.isArray(parsed?.run_input?.cases)
        ? parsed.run_input.cases
        : [];

    for (let index = 0; index < cases.length; index += 1) {
      if (allSamples.length >= args.limit) {
        break;
      }

      const testCase = cases[index] ?? {};
      const title = safeString(testCase.title) || `case-${index + 1}`;
      const text = safeString(testCase.description || testCase.text || '');
      const externalId = safeString(testCase.external_id) || `${runId}::${index + 1}`;

      const key = `${title.toLowerCase()}::${text.toLowerCase()}`;
      if (dedupe.has(key)) {
        continue;
      }
      dedupe.add(key);

      const inferredAsserts = inferFromAsserts(testCase.asserts);
      const inferredText = inferFromText(title, text);
      const suggestedLabel = inferredAsserts !== 'unknown' ? inferredAsserts : inferredText;

      if (!SCENARIOS.includes(suggestedLabel)) {
        continue;
      }

      allSamples.push({
        item: {
          id: externalId,
          title,
          text,
          correct_label: suggestedLabel,
          label_needs_review: true,
        },
        meta: {
          run_id: runId,
          run_file: path.relative(process.cwd(), runFile).replace(/\\/g, '/'),
          infer_from_asserts: inferredAsserts,
          infer_from_text: inferredText,
          source: 'runs/run.json',
        },
      });
    }
  }

  await fs.mkdir(args.outDir, { recursive: true });

  const candidatesPath = path.join(args.outDir, 'candidates.jsonl');
  const labeledSeedPath = path.join(args.outDir, 'labeled.seed.jsonl');

  const lines = allSamples.map((entry) => JSON.stringify(entry));
  await fs.writeFile(candidatesPath, `${lines.join('\n')}\n`, 'utf8');
  await fs.writeFile(labeledSeedPath, `${lines.join('\n')}\n`, 'utf8');

  process.stdout.write(
    `Built eval set with ${allSamples.length} samples.\n` +
      `- Candidates: ${path.relative(process.cwd(), candidatesPath)}\n` +
      `- Seed labels: ${path.relative(process.cwd(), labeledSeedPath)}\n` +
      'Review and correct correct_label values before using for baseline metrics.\n',
  );
}

main().catch((error) => {
  process.stderr.write(`${error instanceof Error ? error.stack || error.message : String(error)}\n`);
  process.exitCode = 1;
});
