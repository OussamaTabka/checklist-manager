# Fine-Tune Pipeline

This folder contains utilities to convert CSV test cases into training and validation JSONL for model fine-tuning.

## 1) Prepare dataset

From playwright-agent root:

- node scripts/prepare-finetune-dataset.mjs --input "C:/Users/Yahia Ghoufa/Downloads/archive (2)/Test_cases.csv"

Outputs to finetune/data:

- auto_labeled.jsonl: all rows with inferred label/confidence
- review_queue.csv: rows needing human review
- train.jsonl: high-quality rows for training
- valid.jsonl: high-quality rows for validation
- summary.json: dataset distribution and file paths

## 2) Review labels

Review and correct rows in review_queue.csv.

Recommended flow:

1. move reviewed rows into train/valid files,
2. keep class balance healthy,
3. rerun baseline eval before starting fine-tuning.

## 3) Start fine-tuning

Requires OPENAI_API_KEY in environment.

- node scripts/start-openai-finetune.mjs --model gpt-4.1-mini --suffix scenario-classifier-v1

Optional dry-run:

- node scripts/start-openai-finetune.mjs --dry-run

## Notes

- Allowed labels are fixed to dashboard, checklists, projects, users, login, unknown.
- This pipeline prioritizes high-confidence examples and marks weak examples for review.
