# Classifier Evaluation Workflow

This folder contains the ROI-first evaluation loop for scenario classification quality.

## 1) Build an eval set from real cases

From `playwright-agent`:

```bash
node scripts/build-eval-set.mjs
```

Outputs:

- `eval/candidates.jsonl`: all extracted candidate samples from `../runs/*/run.json`
- `eval/labeled.seed.jsonl`: same samples with seeded labels

Seed labels are heuristic only. Review and fix `item.correct_label` before trusting metrics.

Allowed labels:

- `dashboard`
- `checklists`
- `projects`
- `users`
- `login`
- `unknown`

## 2) Run baseline evaluation by class

```bash
node scripts/evaluate-classifier.mjs --input eval/labeled.seed.jsonl
```

Outputs under `eval/reports/`:

- `baseline-*.json`
- `baseline-*.md`

Report includes:

- overall accuracy
- macro precision/recall/F1
- per-class precision/recall/F1/support
- confusion matrix
- count of low-confidence predictions rerouted to `unknown`

## 3) Iterate prompt and fallback rules

After prompt or heuristic changes:

1. rerun the baseline command,
2. compare new report against previous report,
3. keep changes only when per-class metrics improve without harming critical classes.

## 4) Confidence threshold behavior

Predictions with confidence below `OLLAMA_MIN_CONFIDENCE` are routed to `unknown`.
This is intentionally fail-closed so uncertain classifications do not silently pass.
