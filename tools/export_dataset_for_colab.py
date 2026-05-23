#!/usr/bin/env python3
"""Export the final training dataset into Colab-ready train/eval JSONL files."""

from __future__ import annotations

import json
import random
from collections import Counter, defaultdict
from pathlib import Path
from typing import Any


INPUT_PATH = Path("backend/storage/app/agent-training/final_training_dataset.jsonl")
OUTPUT_DIR = Path("backend/storage/app/agent-training/colab")
TRAIN_PATH = OUTPUT_DIR / "colab_train.jsonl"
EVAL_PATH = OUTPUT_DIR / "colab_eval.jsonl"
REPORT_PATH = OUTPUT_DIR / "colab_dataset_report.json"
INSTRUCTION = "Generate a Playwright run_spec JSON from the given QA test case. Return only valid JSON."
RANDOM_SEED = 42


def repo_root() -> Path:
    return Path(__file__).resolve().parent.parent


def resolve(path: Path) -> Path:
    return repo_root() / path


def load_examples(path: Path) -> list[dict[str, Any]]:
    examples: list[dict[str, Any]] = []
    for line in path.read_text(encoding="utf-8").splitlines():
        if not line.strip():
            continue
        record = json.loads(line)
        if not isinstance(record, dict):
            continue
        if not all(key in record for key in ("input", "output", "metadata")):
            continue
        examples.append(
            {
                "instruction": INSTRUCTION,
                "input": record["input"],
                "output": record["output"],
                "metadata": record["metadata"],
            }
        )
    return examples


def stratified_split(examples: list[dict[str, Any]]) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    random_generator = random.Random(RANDOM_SEED)
    grouped: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for example in examples:
        scenario_type = str(example.get("metadata", {}).get("scenario_type", "unknown"))
        grouped[scenario_type].append(example)

    train: list[dict[str, Any]] = []
    eval_set: list[dict[str, Any]] = []

    for scenario_type in sorted(grouped):
        bucket = grouped[scenario_type][:]
        random_generator.shuffle(bucket)

        if len(bucket) == 1:
            train.extend(bucket)
            continue

        eval_count = max(1, round(len(bucket) * 0.2))
        if eval_count >= len(bucket):
            eval_count = len(bucket) - 1

        eval_set.extend(bucket[:eval_count])
        train.extend(bucket[eval_count:])

    random_generator.shuffle(train)
    random_generator.shuffle(eval_set)
    return train, eval_set


def write_jsonl(records: list[dict[str, Any]], path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="\n") as handle:
        for record in records:
            handle.write(json.dumps(record, ensure_ascii=False) + "\n")


def write_json(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")


def main() -> int:
    input_path = resolve(INPUT_PATH)
    train_path = resolve(TRAIN_PATH)
    eval_path = resolve(EVAL_PATH)
    report_path = resolve(REPORT_PATH)

    if not input_path.exists():
        print(f"Input dataset not found: {input_path}")
        return 1

    examples = load_examples(input_path)
    train_examples, eval_examples = stratified_split(examples)

    warning = None
    if len(examples) < 50:
        warning = "Dataset too small for real training; use only for pipeline prototype."
        print(warning)

    write_jsonl(train_examples, train_path)
    write_jsonl(eval_examples, eval_path)

    report = {
        "total_examples": len(examples),
        "train_examples": len(train_examples),
        "eval_examples": len(eval_examples),
        "count_by_scenario_type": dict(
            sorted(Counter(str(example["metadata"].get("scenario_type", "unknown")) for example in examples).items())
        ),
        "count_by_quality": dict(
            sorted(Counter(str(example["metadata"].get("quality", "unknown")) for example in examples).items())
        ),
        "count_by_source": dict(
            sorted(Counter(str(example["metadata"].get("source", "unknown")) for example in examples).items())
        ),
        "warning": warning,
        "random_seed": RANDOM_SEED,
    }
    write_json(report_path, report)

    print(f"total_examples: {len(examples)}")
    print(f"train_examples: {len(train_examples)}")
    print(f"eval_examples: {len(eval_examples)}")
    print(f"train_output: {train_path}")
    print(f"eval_output: {eval_path}")
    print(f"report_output: {report_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
