#!/usr/bin/env node
import 'dotenv/config';
import { promises as fs } from 'node:fs';
import path from 'node:path';

const SCENARIOS = ['dashboard', 'checklists', 'projects', 'users', 'login', 'unknown'];

const SYSTEM_PROMPT = [
  'You are a strict classifier.',
  'Return ONLY a single JSON object. No markdown. No explanations.',
  'Allowed scenarios: dashboard, checklists, projects, users, login, unknown.',
  'If you are not sure, return unknown.',
  'Use singular and plural synonyms equally.',
  'If text mixes multiple feature areas and no dominant intent is clear, return unknown.',
  'Map login/auth/session/sign-in/reset-password to login.',
  'Map checklist/checklists/items/test case catalog management to checklists.',
  'Map project/projects/version/release to projects.',
  'Map user/users/admin/roles/permissions/account management to users.',
  'Map dashboard/home/summary/stats/widgets to dashboard.',
  'If the item is mainly API/network/security validation and not a clear UI-page interaction, return unknown; exception: explicit login/authentication flow checks should map to login.',
].join('\n');

function parseArgs(argv) {
  const args = {
    input: path.resolve(process.cwd(), 'finetune', 'input', 'Test_cases.csv'),
    outDir: path.resolve(process.cwd(), 'finetune', 'data'),
    trainRatio: 0.9,
    minConfidence: 0.82,
    seed: 'ft-seed-1',
  };

  for (let i = 0; i < argv.length; i += 1) {
    const token = argv[i];
    const next = argv[i + 1];

    if (!token.startsWith('--') && i === 0) {
      args.input = path.resolve(token);
    } else if (token === '--input' && next) {
      args.input = path.resolve(next);
      i += 1;
    } else if (token === '--outDir' && next) {
      args.outDir = path.resolve(next);
      i += 1;
    } else if (token === '--seed' && next) {
      args.seed = String(next);
      i += 1;
    } else if (token === '--trainRatio' && next) {
      const value = Number(next);
      if (Number.isFinite(value) && value > 0 && value < 1) {
        args.trainRatio = value;
      }
      i += 1;
    } else if (token === '--minConfidence' && next) {
      const value = Number(next);
      if (Number.isFinite(value) && value >= 0 && value <= 1) {
        args.minConfidence = value;
      }
      i += 1;
    }
  }

  return args;
}

function parseCsv(text) {
  const rows = [];
  let current = '';
  let row = [];
  let inQuotes = false;

  for (let i = 0; i < text.length; i += 1) {
    const ch = text[i];

    if (ch === '"') {
      const next = text[i + 1];
      if (inQuotes && next === '"') {
        current += '"';
        i += 1;
      } else {
        inQuotes = !inQuotes;
      }
      continue;
    }

    if (ch === ',' && !inQuotes) {
      row.push(current);
      current = '';
      continue;
    }

    if ((ch === '\n' || ch === '\r') && !inQuotes) {
      if (ch === '\r' && text[i + 1] === '\n') {
        i += 1;
      }
      row.push(current);
      current = '';
      if (row.some((cell) => cell.length > 0)) {
        rows.push(row);
      }
      row = [];
      continue;
    }

    current += ch;
  }

  if (current.length > 0 || row.length > 0) {
    row.push(current);
    if (row.some((cell) => cell.length > 0)) {
      rows.push(row);
    }
  }

  if (rows.length === 0) {
    return [];
  }

  const headers = rows[0].map((value) => value.trim());
  return rows.slice(1).map((r) => {
    const obj = {};
    headers.forEach((h, idx) => {
      obj[h] = (r[idx] ?? '').trim();
    });
    return obj;
  });
}

function normalizeText(value) {
  if (typeof value !== 'string') {
    return '';
  }
  return value.replace(/\s+/g, ' ').trim();
}

function hasAny(text, patterns) {
  return patterns.some((pattern) => pattern.test(text));
}

function countMatches(text, patterns) {
  let count = 0;
  for (const pattern of patterns) {
    if (pattern.test(text)) {
      count += 1;
    }
  }
  return count;
}

const KEYWORDS = {
  login: [
    /\blog\s*in\b/i,
    /\blogin\b/i,
    /\bauth(?:entication)?\b/i,
    /\bsign\s*in\b/i,
    /\bsession\b/i,
    /\bpassword\b/i,
    /\bbearer\s*token\b/i,
    /\bcaptcha\b/i,
    /\blogout\b/i,
  ],
  dashboard: [
    /\bdashboard\b/i,
    /\bhome\b/i,
    /\bsummary\b/i,
    /\bstats?\b/i,
    /\bwidgets?\b/i,
  ],
  checklists: [
    /\bchecklists?\b/i,
    /\bcheck\s*items?\b/i,
    /\bresult\s*grid\b/i,
    /\bfilter\s*criteria\b/i,
    /\bwindow\b/i,
    /\bimage\s*upload\b/i,
    /\bexcel\s*export\b/i,
    /\bemail\b/i,
    /\bfield\b/i,
    /\bvalidation\b/i,
  ],
  projects: [
    /\bprojects?\b/i,
    /\bversions?\b/i,
    /\breleases?\b/i,
  ],
  users: [
    /\busers?\b/i,
    /\broles?\b/i,
    /\bpermissions?\b/i,
    /\baccess\s*privileges\b/i,
    /\baccount\s*management\b/i,
  ],
};

const API_NON_UI = [
  /\bjson\b/i,
  /\bschema\b/i,
  /\brequest\b/i,
  /\bresponse\b/i,
  /\bstatus\s*codes?\b/i,
  /\berror\s*codes?\b/i,
  /\bcors\b/i,
  /\brate\s*limit(?:ing)?\b/i,
  /\bendpoint\b/i,
  /\bapi\b/i,
  /\bsql\s*injection\b/i,
  /\bhttps\b/i,
  /\bcookie\b/i,
  /\btoken\b/i,
  /\bsecurity\b/i,
  /\bperformance\b/i,
  /\bstress\s*testing\b/i,
  /\bload\s*testing\b/i,
  /\bdatabase\b/i,
];

const UI_HINTS = [
  /\bpage\b/i,
  /\bbutton\b/i,
  /\btable\b/i,
  /\bfield\b/i,
  /\bform\b/i,
  /\bgrid\b/i,
  /\bdrop-?down\b/i,
  /\bwindow\b/i,
  /\blink\b/i,
  /\btooltip\b/i,
  /\bcss\b/i,
  /\bhtml\b/i,
  /\balign(?:ment)?\b/i,
  /\bscroll\s*bar\b/i,
  /\bradio\b/i,
  /\bcheckbox\b/i,
];

function autoLabel(title, description) {
  const text = `${title} ${description}`.trim();

  const hasApiCue = hasAny(text, API_NON_UI);
  const hasUiCue = hasAny(text, UI_HINTS);
  const hasLoginCue = hasAny(text, KEYWORDS.login);

  if (hasApiCue && !hasUiCue && !hasLoginCue) {
    return {
      label: 'unknown',
      confidence: 0.96,
      source: 'rule:api-non-ui',
      reviewRequired: false,
    };
  }

  const scores = {
    dashboard: countMatches(text, KEYWORDS.dashboard),
    checklists: countMatches(text, KEYWORDS.checklists),
    projects: countMatches(text, KEYWORDS.projects),
    users: countMatches(text, KEYWORDS.users),
    login: countMatches(text, KEYWORDS.login),
  };

  const ranking = Object.entries(scores)
    .sort((a, b) => b[1] - a[1]);

  const [topLabel, topScore] = ranking[0];
  const secondScore = ranking[1]?.[1] ?? 0;

  if (topScore <= 0) {
    return {
      label: 'unknown',
      confidence: hasApiCue ? 0.9 : 0.7,
      source: hasApiCue ? 'rule:api-default-unknown' : 'rule:no-signal',
      reviewRequired: !hasApiCue,
    };
  }

  if (topScore === secondScore) {
    return {
      label: 'unknown',
      confidence: 0.65,
      source: 'rule:tie',
      reviewRequired: true,
    };
  }

  const delta = topScore - secondScore;
  const confidence = Math.min(0.98, 0.58 + topScore * 0.1 + delta * 0.08);

  return {
    label: topLabel,
    confidence,
    source: 'rule:keywords',
    reviewRequired: confidence < 0.82,
  };
}

function stableHash(value) {
  let hash = 2166136261;
  for (let i = 0; i < value.length; i += 1) {
    hash ^= value.charCodeAt(i);
    hash = Math.imul(hash, 16777619);
  }
  return hash >>> 0;
}

function toTrainingExample(sample) {
  const userInput = {
    title: sample.title,
    description: sample.description,
    context: {
      source: 'fine-tune-csv',
      id: sample.id,
      auto_label_source: sample.label_source,
    },
    task: 'Classify this test item into one scenario.',
    output: { scenario: 'dashboard|checklists|projects|users|login|unknown', confidence: 0.0 },
  };

  return {
    messages: [
      { role: 'system', content: SYSTEM_PROMPT },
      { role: 'user', content: JSON.stringify(userInput, null, 2) },
      { role: 'assistant', content: JSON.stringify({ scenario: sample.correct_label }) },
    ],
  };
}

function toJsonl(rows) {
  return `${rows.map((row) => JSON.stringify(row)).join('\n')}\n`;
}

function toCsv(rows, headers) {
  const escape = (value) => {
    const v = String(value ?? '');
    if (/[",\n\r]/.test(v)) {
      return `"${v.replace(/"/g, '""')}"`;
    }
    return v;
  };

  const lines = [headers.join(',')];
  for (const row of rows) {
    lines.push(headers.map((h) => escape(row[h])).join(','));
  }
  return `${lines.join('\n')}\n`;
}

async function main() {
  const args = parseArgs(process.argv.slice(2));
  const raw = await fs.readFile(args.input, 'utf8');
  const parsed = parseCsv(raw);

  if (parsed.length === 0) {
    throw new Error('No rows found in CSV input.');
  }

  const titleKey = Object.keys(parsed[0]).find((k) => k.toLowerCase() === 'title') ?? 'Title';
  const descriptionKey =
    Object.keys(parsed[0]).find((k) => k.toLowerCase() === 'description') ??
    Object.keys(parsed[0]).find((k) => k.toLowerCase() === 'body') ??
    'Description';
  const idKey = Object.keys(parsed[0]).find((k) => k.toLowerCase().includes('id')) ?? Object.keys(parsed[0])[0];

  const samples = [];

  for (const row of parsed) {
    const title = normalizeText(row[titleKey]);
    const description = normalizeText(row[descriptionKey]);
    if (!title && !description) {
      continue;
    }

    const id = normalizeText(row[idKey]) || `${samples.length + 1}`;
    const labeled = autoLabel(title, description);

    if (!SCENARIOS.includes(labeled.label)) {
      continue;
    }

    const sample = {
      id,
      title,
      description,
      correct_label: labeled.label,
      confidence: Number(labeled.confidence.toFixed(3)),
      label_source: labeled.source,
      review_required: labeled.reviewRequired || labeled.confidence < args.minConfidence,
    };

    samples.push(sample);
  }

  const highQuality = samples.filter((s) => !s.review_required);
  const reviewQueue = samples.filter((s) => s.review_required);

  const sorted = [...highQuality].sort((a, b) => {
    const aHash = stableHash(`${args.seed}:${a.id}:${a.title}`);
    const bHash = stableHash(`${args.seed}:${b.id}:${b.title}`);
    return aHash - bHash;
  });

  const splitIndex = Math.max(1, Math.floor(sorted.length * args.trainRatio));
  const train = sorted.slice(0, splitIndex);
  const valid = sorted.slice(splitIndex);

  const trainJsonl = train.map(toTrainingExample);
  const validJsonl = valid.map(toTrainingExample);

  await fs.mkdir(args.outDir, { recursive: true });

  const autoPath = path.join(args.outDir, 'auto_labeled.jsonl');
  const reviewPath = path.join(args.outDir, 'review_queue.csv');
  const trainPath = path.join(args.outDir, 'train.jsonl');
  const validPath = path.join(args.outDir, 'valid.jsonl');
  const summaryPath = path.join(args.outDir, 'summary.json');

  await fs.writeFile(autoPath, toJsonl(samples.map((s) => ({ item: s }))), 'utf8');
  await fs.writeFile(
    reviewPath,
    toCsv(reviewQueue, ['id', 'title', 'description', 'correct_label', 'confidence', 'label_source', 'review_required']),
    'utf8',
  );
  await fs.writeFile(trainPath, toJsonl(trainJsonl), 'utf8');
  await fs.writeFile(validPath, toJsonl(validJsonl), 'utf8');

  const summary = {
    input: args.input,
    total_rows: samples.length,
    high_quality_rows: highQuality.length,
    review_required_rows: reviewQueue.length,
    train_rows: train.length,
    valid_rows: valid.length,
    labels: SCENARIOS.reduce((acc, label) => {
      acc[label] = samples.filter((row) => row.correct_label === label).length;
      return acc;
    }, {}),
    files: {
      auto_labeled: autoPath,
      review_queue: reviewPath,
      train: trainPath,
      valid: validPath,
    },
  };

  await fs.writeFile(summaryPath, `${JSON.stringify(summary, null, 2)}\n`, 'utf8');

  process.stdout.write(`Prepared fine-tune data from ${args.input}\n`);
  process.stdout.write(`Total rows: ${samples.length}\n`);
  process.stdout.write(`High-quality rows: ${highQuality.length}\n`);
  process.stdout.write(`Review required rows: ${reviewQueue.length}\n`);
  process.stdout.write(`Train rows: ${train.length}\n`);
  process.stdout.write(`Valid rows: ${valid.length}\n`);
  process.stdout.write(`Wrote: ${path.relative(process.cwd(), trainPath)}\n`);
  process.stdout.write(`Wrote: ${path.relative(process.cwd(), validPath)}\n`);
  process.stdout.write(`Wrote: ${path.relative(process.cwd(), reviewPath)}\n`);
}

main().catch((error) => {
  process.stderr.write(`${error instanceof Error ? error.stack || error.message : String(error)}\n`);
  process.exitCode = 1;
});
