#!/usr/bin/env node
import 'dotenv/config';
import { promises as fs } from 'node:fs';
import path from 'node:path';
import { classifyItem } from '../src/classify.js';
import { config } from '../src/config.js';

const SCENARIOS = ['dashboard', 'checklists', 'projects', 'users', 'login', 'unknown'];

function parseArgs(argv) {
  const args = {
    input: path.resolve(process.cwd(), 'eval', 'labeled.seed.jsonl'),
    outDir: path.resolve(process.cwd(), 'eval', 'reports'),
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
    }
  }

  return args;
}

function safeNumber(value, fallback = 0) {
  return Number.isFinite(value) ? value : fallback;
}

function toFixed(value) {
  return safeNumber(value).toFixed(3);
}

function initMatrix(labels) {
  const matrix = {};
  for (const trueLabel of labels) {
    matrix[trueLabel] = {};
    for (const predictedLabel of labels) {
      matrix[trueLabel][predictedLabel] = 0;
    }
  }
  return matrix;
}

function normalizeLabel(value) {
  if (typeof value !== 'string') {
    return 'unknown';
  }
  const normalized = value.trim().toLowerCase();
  return SCENARIOS.includes(normalized) ? normalized : 'unknown';
}

function parseJsonl(text) {
  return text
    .split(/\r?\n/g)
    .map((line) => line.trim())
    .filter(Boolean)
    .map((line) => JSON.parse(line));
}

function computeMetrics(labels, matrix) {
  const perClass = {};
  let total = 0;
  let correct = 0;

  for (const label of labels) {
    const row = matrix[label];
    const rowTotal = labels.reduce((sum, predicted) => sum + row[predicted], 0);
    total += rowTotal;
    correct += row[label];

    const tp = row[label];
    const fp = labels.reduce((sum, trueLabel) => {
      if (trueLabel === label) {
        return sum;
      }
      return sum + matrix[trueLabel][label];
    }, 0);
    const fn = labels.reduce((sum, predictedLabel) => {
      if (predictedLabel === label) {
        return sum;
      }
      return sum + row[predictedLabel];
    }, 0);

    const precision = tp + fp > 0 ? tp / (tp + fp) : 0;
    const recall = tp + fn > 0 ? tp / (tp + fn) : 0;
    const f1 = precision + recall > 0 ? (2 * precision * recall) / (precision + recall) : 0;

    perClass[label] = {
      precision,
      recall,
      f1,
      support: rowTotal,
      tp,
      fp,
      fn,
    };
  }

  const macroPrecision = labels.reduce((sum, label) => sum + perClass[label].precision, 0) / labels.length;
  const macroRecall = labels.reduce((sum, label) => sum + perClass[label].recall, 0) / labels.length;
  const macroF1 = labels.reduce((sum, label) => sum + perClass[label].f1, 0) / labels.length;

  return {
    total,
    correct,
    accuracy: total > 0 ? correct / total : 0,
    macro: {
      precision: macroPrecision,
      recall: macroRecall,
      f1: macroF1,
    },
    perClass,
  };
}

function pad(value, width) {
  const text = String(value);
  if (text.length >= width) {
    return text;
  }
  return `${text}${' '.repeat(width - text.length)}`;
}

function renderConsoleReport({ metrics, matrix, lowConfidenceCount, threshold }) {
  const lines = [];
  lines.push(`Samples: ${metrics.total}`);
  lines.push(`Accuracy: ${toFixed(metrics.accuracy)}`);
  lines.push(`Macro P/R/F1: ${toFixed(metrics.macro.precision)} / ${toFixed(metrics.macro.recall)} / ${toFixed(metrics.macro.f1)}`);
  lines.push(`Low-confidence rerouted to unknown: ${lowConfidenceCount} (threshold=${threshold})`);
  lines.push('');
  lines.push('Per-class metrics:');
  lines.push(pad('class', 14) + pad('precision', 12) + pad('recall', 10) + pad('f1', 10) + pad('support', 10));

  for (const label of SCENARIOS) {
    const item = metrics.perClass[label];
    lines.push(
      pad(label, 14) +
        pad(toFixed(item.precision), 12) +
        pad(toFixed(item.recall), 10) +
        pad(toFixed(item.f1), 10) +
        pad(item.support, 10),
    );
  }

  lines.push('');
  lines.push('Confusion matrix (rows=true, cols=pred):');
  lines.push(pad('', 14) + SCENARIOS.map((label) => pad(label, 12)).join(''));

  for (const trueLabel of SCENARIOS) {
    const row = matrix[trueLabel];
    lines.push(pad(trueLabel, 14) + SCENARIOS.map((pred) => pad(row[pred], 12)).join(''));
  }

  return lines.join('\n');
}

function toMarkdownReport({ metrics, matrix, lowConfidenceCount, threshold, inputPath }) {
  const lines = [];
  lines.push('# Classifier Baseline Report');
  lines.push('');
  lines.push(`- Input: ${inputPath}`);
  lines.push(`- Samples: ${metrics.total}`);
  lines.push(`- Accuracy: ${toFixed(metrics.accuracy)}`);
  lines.push(`- Macro Precision: ${toFixed(metrics.macro.precision)}`);
  lines.push(`- Macro Recall: ${toFixed(metrics.macro.recall)}`);
  lines.push(`- Macro F1: ${toFixed(metrics.macro.f1)}`);
  lines.push(`- Low-confidence rerouted to unknown: ${lowConfidenceCount}`);
  lines.push(`- Threshold: ${threshold}`);
  lines.push('');
  lines.push('## Per-Class Metrics');
  lines.push('');
  lines.push('| Class | Precision | Recall | F1 | Support |');
  lines.push('| --- | ---: | ---: | ---: | ---: |');

  for (const label of SCENARIOS) {
    const item = metrics.perClass[label];
    lines.push(`| ${label} | ${toFixed(item.precision)} | ${toFixed(item.recall)} | ${toFixed(item.f1)} | ${item.support} |`);
  }

  lines.push('');
  lines.push('## Confusion Matrix (rows=true, cols=pred)');
  lines.push('');
  lines.push(`| true \\ pred | ${SCENARIOS.join(' | ')} |`);
  lines.push(`| --- | ${SCENARIOS.map(() => '---:').join(' | ')} |`);

  for (const trueLabel of SCENARIOS) {
    const row = matrix[trueLabel];
    lines.push(`| ${trueLabel} | ${SCENARIOS.map((pred) => row[pred]).join(' | ')} |`);
  }

  return `${lines.join('\n')}\n`;
}

async function main() {
  const args = parseArgs(process.argv.slice(2));
  const raw = await fs.readFile(args.input, 'utf8');
  const rows = parseJsonl(raw);

  const matrix = initMatrix(SCENARIOS);
  const sampleOutputs = [];
  let lowConfidenceCount = 0;

  for (const row of rows) {
    const item = row?.item ?? row;
    const title = typeof item.title === 'string' ? item.title : '';
    const description = typeof item.text === 'string' ? item.text : '';
    const expected = normalizeLabel(item.correct_label);

    const result = await classifyItem({
      baseUrl: config.ollamaBaseUrl,
      model: config.ollamaModel,
      timeoutMs: config.ollamaTimeoutMs,
      title,
      description,
      context: {
        source: 'classifier-eval',
      },
    });

    let predicted = normalizeLabel(result.scenario);
    let thresholdRouted = false;

    if (
      predicted !== 'unknown' &&
      typeof result.confidence === 'number' &&
      result.confidence < config.ollamaMinConfidence
    ) {
      predicted = 'unknown';
      thresholdRouted = true;
      lowConfidenceCount += 1;
    }

    matrix[expected][predicted] += 1;

    sampleOutputs.push({
      id: item.id,
      title,
      expected,
      predicted,
      confidence: result.confidence,
      threshold_routed_to_unknown: thresholdRouted,
      raw_model_scenario: result.scenario,
    });
  }

  const metrics = computeMetrics(SCENARIOS, matrix);
  const report = {
    generated_at: new Date().toISOString(),
    input: args.input,
    threshold: config.ollamaMinConfidence,
    low_confidence_routed_to_unknown: lowConfidenceCount,
    metrics,
    matrix,
    samples: sampleOutputs,
  };

  await fs.mkdir(args.outDir, { recursive: true });
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const reportJson = path.join(args.outDir, `baseline-${stamp}.json`);
  const reportMd = path.join(args.outDir, `baseline-${stamp}.md`);

  await fs.writeFile(reportJson, `${JSON.stringify(report, null, 2)}\n`, 'utf8');
  await fs.writeFile(
    reportMd,
    toMarkdownReport({
      metrics,
      matrix,
      lowConfidenceCount,
      threshold: config.ollamaMinConfidence,
      inputPath: path.relative(process.cwd(), args.input).replace(/\\/g, '/'),
    }),
    'utf8',
  );

  process.stdout.write(`${renderConsoleReport({ metrics, matrix, lowConfidenceCount, threshold: config.ollamaMinConfidence })}\n\n`);
  process.stdout.write(`Saved JSON report: ${path.relative(process.cwd(), reportJson)}\n`);
  process.stdout.write(`Saved Markdown report: ${path.relative(process.cwd(), reportMd)}\n`);
}

main().catch((error) => {
  process.stderr.write(`${error instanceof Error ? error.stack || error.message : String(error)}\n`);
  process.exitCode = 1;
});
