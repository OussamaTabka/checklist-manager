#!/usr/bin/env node
import 'dotenv/config';
import { promises as fs } from 'node:fs';
import path from 'node:path';

function parseArgs(argv) {
  const args = {
    train: path.resolve(process.cwd(), 'finetune', 'data', 'train.jsonl'),
    valid: path.resolve(process.cwd(), 'finetune', 'data', 'valid.jsonl'),
    model: process.env.OPENAI_BASE_MODEL ?? 'gpt-4.1-mini',
    suffix: process.env.OPENAI_FINE_TUNE_SUFFIX ?? 'scenario-classifier-v1',
    dryRun: false,
  };

  for (let i = 0; i < argv.length; i += 1) {
    const token = argv[i];
    const next = argv[i + 1];

    if (token === '--train' && next) {
      args.train = path.resolve(next);
      i += 1;
    } else if (token === '--valid' && next) {
      args.valid = path.resolve(next);
      i += 1;
    } else if (token === '--model' && next) {
      args.model = String(next);
      i += 1;
    } else if (token === '--suffix' && next) {
      args.suffix = String(next);
      i += 1;
    } else if (token === '--dry-run') {
      args.dryRun = true;
    }
  }

  return args;
}

async function uploadFile(apiKey, filePath) {
  const fileBytes = await fs.readFile(filePath);
  const form = new FormData();
  form.append('purpose', 'fine-tune');
  form.append('file', new Blob([fileBytes]), path.basename(filePath));

  const response = await fetch('https://api.openai.com/v1/files', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${apiKey}`,
    },
    body: form,
  });

  if (!response.ok) {
    const text = await response.text().catch(() => '');
    throw new Error(`File upload failed (${response.status}): ${text}`);
  }

  return response.json();
}

async function createFineTuneJob(apiKey, payload) {
  const response = await fetch('https://api.openai.com/v1/fine_tuning/jobs', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${apiKey}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(payload),
  });

  if (!response.ok) {
    const text = await response.text().catch(() => '');
    throw new Error(`Fine-tune job creation failed (${response.status}): ${text}`);
  }

  return response.json();
}

async function main() {
  const args = parseArgs(process.argv.slice(2));
  await fs.access(args.train);
  await fs.access(args.valid);

  if (args.dryRun) {
    process.stdout.write('Dry run mode enabled.\n');
    process.stdout.write(`Train file: ${args.train}\n`);
    process.stdout.write(`Valid file: ${args.valid}\n`);
    process.stdout.write(`Base model: ${args.model}\n`);
    process.stdout.write(`Suffix: ${args.suffix}\n`);
    return;
  }

  const apiKey = process.env.OPENAI_API_KEY;

  if (!apiKey) {
    throw new Error('OPENAI_API_KEY is missing. Set it in your environment before running this script.');
  }

  process.stdout.write(`Uploading train file: ${args.train}\n`);
  const trainUpload = await uploadFile(apiKey, args.train);

  process.stdout.write(`Uploading validation file: ${args.valid}\n`);
  const validUpload = await uploadFile(apiKey, args.valid);

  const jobPayload = {
    model: args.model,
    training_file: trainUpload.id,
    validation_file: validUpload.id,
    suffix: args.suffix,
  };

  process.stdout.write('Creating fine-tune job...\n');
  const job = await createFineTuneJob(apiKey, jobPayload);

  process.stdout.write(`Fine-tune job created: ${job.id}\n`);
  process.stdout.write(`Status: ${job.status}\n`);
  process.stdout.write(`Training file: ${trainUpload.id}\n`);
  process.stdout.write(`Validation file: ${validUpload.id}\n`);
}

main().catch((error) => {
  process.stderr.write(`${error instanceof Error ? error.stack || error.message : String(error)}\n`);
  process.exitCode = 1;
});
