#!/usr/bin/env python3
"""Inspect the final training dataset and print a readable quality summary."""

from __future__ import annotations

import json
import re
from collections import Counter, defaultdict
from pathlib import Path
from typing import Any


DATASET_PATH = Path("backend/storage/app/agent-training/final_training_dataset.jsonl")
STEP_ACTIONS = ("fill", "click", "select_option", "hover", "set_file")
PLACEHOLDER_RE = re.compile(r"\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}")


def repo_root() -> Path:
    return Path(__file__).resolve().parent.parent


def resolve_dataset_path() -> Path:
    return repo_root() / DATASET_PATH


def load_examples(path: Path) -> list[dict[str, Any]]:
    examples: list[dict[str, Any]] = []
    for line in path.read_text(encoding="utf-8").splitlines():
        if not line.strip():
            continue
        record = json.loads(line)
        if isinstance(record, dict):
            examples.append(record)
    return examples


def extract_required_input_names(example: dict[str, Any]) -> set[str]:
    names: set[str] = set()
    required_inputs = example.get("output", {}).get("required_inputs", [])
    if not isinstance(required_inputs, list):
        return names

    for item in required_inputs:
        if isinstance(item, str) and item.strip():
            names.add(item.strip())
        elif isinstance(item, dict):
            name = item.get("name")
            if isinstance(name, str) and name.strip():
                names.add(name.strip())
    return names


def extract_placeholders_from_steps(example: dict[str, Any]) -> set[str]:
    placeholders: set[str] = set()
    steps = example.get("output", {}).get("steps", [])
    if not isinstance(steps, list):
        return placeholders

    for step in steps:
        if not isinstance(step, dict):
            continue
        serialized = json.dumps(step, ensure_ascii=False)
        for match in PLACEHOLDER_RE.findall(serialized):
            placeholders.add(match)
    return placeholders


def extract_placeholders_from_asserts(example: dict[str, Any]) -> set[str]:
    placeholders: set[str] = set()
    asserts = example.get("output", {}).get("asserts", [])
    if not isinstance(asserts, list):
        return placeholders

    for assertion in asserts:
        if not isinstance(assertion, dict):
            continue
        serialized = json.dumps(assertion, ensure_ascii=False)
        for match in PLACEHOLDER_RE.findall(serialized):
            placeholders.add(match)
    return placeholders


def count_step_action_presence(examples: list[dict[str, Any]]) -> Counter[str]:
    counts: Counter[str] = Counter()
    for example in examples:
        steps = example.get("output", {}).get("steps", [])
        if not isinstance(steps, list):
            continue
        actions = {
            step.get("action")
            for step in steps
            if isinstance(step, dict) and isinstance(step.get("action"), str)
        }
        for action in STEP_ACTIONS:
            if action in actions:
                counts[action] += 1
    return counts


def sample_examples_by_source(examples: list[dict[str, Any]], limit: int = 3) -> dict[str, list[dict[str, str]]]:
    grouped: dict[str, list[dict[str, str]]] = defaultdict(list)
    for example in examples:
        source = str(example.get("metadata", {}).get("source", "unknown"))
        if len(grouped[source]) >= limit:
            continue
        grouped[source].append(
            {
                "title": str(example.get("input", {}).get("title", "")),
                "scenario_type": str(example.get("output", {}).get("scenario_type", "")),
                "quality": str(example.get("metadata", {}).get("quality", "")),
            }
        )
    return grouped


def main() -> int:
    dataset_path = resolve_dataset_path()
    if not dataset_path.exists():
        print(f"Dataset not found: {dataset_path}")
        return 1

    examples = load_examples(dataset_path)
    total = len(examples)
    by_source = Counter(str(example.get("metadata", {}).get("source", "unknown")) for example in examples)
    by_scenario = Counter(str(example.get("output", {}).get("scenario_type", "unknown")) for example in examples)
    by_quality = Counter(str(example.get("metadata", {}).get("quality", "unknown")) for example in examples)
    step_presence = count_step_action_presence(examples)
    samples = sample_examples_by_source(examples)

    missing_provided_inputs_but_required: list[dict[str, str]] = []
    missing_required_inputs_for_placeholders: list[dict[str, Any]] = []

    for example in examples:
        title = str(example.get("input", {}).get("title", ""))
        scenario_type = str(example.get("output", {}).get("scenario_type", ""))
        provided_inputs = example.get("input", {}).get("provided_inputs", {})
        provided_keys = set(provided_inputs.keys()) if isinstance(provided_inputs, dict) else set()
        required_names = extract_required_input_names(example)
        step_placeholders = extract_placeholders_from_steps(example)
        assert_placeholders = extract_placeholders_from_asserts(example)
        all_placeholders = step_placeholders | assert_placeholders
        missing_required = sorted(
            name
            for name in all_placeholders
            if name not in {"base_url"} and name not in required_names
        )

        if required_names and not provided_keys:
            missing_provided_inputs_but_required.append(
                {
                    "title": title,
                    "scenario_type": scenario_type,
                    "required_inputs": ", ".join(sorted(required_names)),
                }
            )

        if missing_required:
            missing_required_inputs_for_placeholders.append(
                {
                    "title": title,
                    "scenario_type": scenario_type,
                    "missing_required_inputs": missing_required,
                }
            )

    print(f"Dataset: {dataset_path}")
    print(f"Total examples: {total}")
    print("Count by source:")
    for source, count in sorted(by_source.items()):
        print(f"- {source}: {count}")
    print("Count by scenario_type:")
    for scenario_type, count in sorted(by_scenario.items()):
        print(f"- {scenario_type}: {count}")
    print("Count by quality:")
    for quality, count in sorted(by_quality.items()):
        print(f"- {quality}: {count}")
    print("Examples with actions:")
    for action in STEP_ACTIONS:
        print(f"- {action}: {step_presence.get(action, 0)}")

    print("Top 10 scenario types:")
    for scenario_type, count in by_scenario.most_common(10):
        ratio = (count / total * 100) if total else 0
        print(f"- {scenario_type}: {count} ({ratio:.1f}%)")

    print("Sample examples by source:")
    for source, source_samples in sorted(samples.items()):
        print(f"- {source}:")
        for sample in source_samples:
            print(
                f"  title={sample['title']} | scenario_type={sample['scenario_type']} | quality={sample['quality']}"
            )

    dominant = [(scenario_type, count) for scenario_type, count in by_scenario.items() if total and count / total > 0.40]
    print("Dominance check:")
    if dominant:
        for scenario_type, count in dominant:
            print(f"- Warning: {scenario_type} dominates the dataset with {count}/{total} examples")
    else:
        print("- No scenario_type exceeds 40% of the dataset")

    print("Examples without provided_inputs but with required_inputs:")
    if missing_provided_inputs_but_required:
        for item in missing_provided_inputs_but_required[:10]:
            print(f"- {item['title']} | {item['scenario_type']} | required_inputs={item['required_inputs']}")
        if len(missing_provided_inputs_but_required) > 10:
            print(f"- ... and {len(missing_provided_inputs_but_required) - 10} more")
    else:
        print("- None")

    print("Examples with placeholders in steps but missing from required_inputs:")
    if missing_required_inputs_for_placeholders:
        for item in missing_required_inputs_for_placeholders[:10]:
            joined = ", ".join(item["missing_required_inputs"])
            print(f"- {item['title']} | {item['scenario_type']} | missing_required_inputs={joined}")
        if len(missing_required_inputs_for_placeholders) > 10:
            print(f"- ... and {len(missing_required_inputs_for_placeholders) - 10} more")
    else:
        print("- None")

    print("Recommendations:")
    if dominant:
        print("- Rebalance scenario types so one family does not dominate the training set.")
    if missing_provided_inputs_but_required:
        print("- Add provided_inputs for examples that declare required_inputs to make conditioning clearer.")
    if missing_required_inputs_for_placeholders:
        print("- Align step placeholders with required_inputs so the model sees a consistent contract.")
    if not dominant and not missing_provided_inputs_but_required and not missing_required_inputs_for_placeholders:
        print("- The dataset looks structurally balanced for a first prototype review.")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
