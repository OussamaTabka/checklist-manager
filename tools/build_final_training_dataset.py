#!/usr/bin/env python3
"""Merge, validate, normalize, and deduplicate offline training datasets."""

from __future__ import annotations

import argparse
import json
import re
from collections import Counter
from pathlib import Path
from typing import Any


BASE_URL_PLACEHOLDER = "{{base_url}}"
DEFAULT_INPUTS = [
    Path("backend/storage/app/agent-training/training_dataset.jsonl"),
    Path("backend/storage/app/agent-training/codegen_examples/codegen_cleaned_examples.jsonl"),
    Path("backend/storage/app/agent-training/external/external_test_cases_dataset.jsonl"),
]
DEFAULT_OUTPUT = Path("backend/storage/app/agent-training/final_training_dataset.jsonl")
QUALITY_RANK = {"high": 3, "medium": 2, "low": 1}
DANGEROUS_MARKERS = ("permanent_delete", "destructive_delete", "irreversible_archive")
GENERIC_DIAGNOSTIC = "GENERIC_UI_FALLBACK"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Build the final offline training dataset.")
    parser.add_argument("--output", dest="output_path", type=Path, default=None)
    return parser.parse_args()


def repo_root() -> Path:
    return Path(__file__).resolve().parent.parent


def resolve_path(path: Path) -> Path:
    return repo_root() / path


def warn(message: str) -> None:
    print(f"Warning: {message}")


def load_jsonl_lines(path: Path) -> list[str]:
    return path.read_text(encoding="utf-8").splitlines()


def normalize_text(value: str) -> str:
    value = re.sub(r"\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b", "{{email}}", value, flags=re.I)
    value = re.sub(r"\b(?:password|passwd|pwd)\b\s*[:=]?\s*[^\s,;]+", "{{password}}", value, flags=re.I)
    value = re.sub(r"\bpassword123\b", "{{password}}", value, flags=re.I)
    value = re.sub(r"\b(?:token|secret|api[_-]?key|authorization)\b\s*[:=]?\s*[^\s,;]+", "{{token}}", value, flags=re.I)
    value = re.sub(r"\b(?:ghp|gho|ghu|ghs|sk|pk)_[A-Za-z0-9]{8,}\b", "{{token}}", value, flags=re.I)
    value = re.sub(r"\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9._-]+\.[A-Za-z0-9._-]+\b", "{{token}}", value)
    value = re.sub(r"([A-Za-z]:\\|/)?(?:[\w .()-]+[\\/])+[\w .()-]+\.(?:png|jpg|jpeg|pdf|csv|zip|docx|txt)", "{{file_path}}", value, flags=re.I)
    value = re.sub(r"https?://[^\s'\"}]+", BASE_URL_PLACEHOLDER, value)
    return value


def normalize_data(value: Any) -> Any:
    if isinstance(value, dict):
        return {key: normalize_data(item) for key, item in value.items()}
    if isinstance(value, list):
        return [normalize_data(item) for item in value]
    if isinstance(value, str):
        return normalize_text(value)
    return value


def ensure_list_of_dicts(value: Any) -> list[dict[str, Any]]:
    if not isinstance(value, list):
        return []
    return [item for item in value if isinstance(item, dict)]


def infer_source(path: Path, metadata: dict[str, Any]) -> str:
    if isinstance(metadata.get("source"), str) and metadata["source"].strip():
        return metadata["source"].strip()
    if path.name == "training_dataset.jsonl":
        return "playwright_run"
    if "generated_by" in metadata:
        return "external_test_cases"
    return "unknown"


def extract_run_steps(record: dict[str, Any]) -> list[dict[str, Any]]:
    output = record.get("output") if isinstance(record.get("output"), dict) else {}
    run_steps = output.get("steps")
    if isinstance(run_steps, list):
        return ensure_list_of_dicts(run_steps)

    generated_plan = output.get("generated_plan") if isinstance(output.get("generated_plan"), dict) else {}
    return ensure_list_of_dicts(generated_plan.get("steps"))


def extract_run_asserts(record: dict[str, Any]) -> list[dict[str, Any]]:
    output = record.get("output") if isinstance(record.get("output"), dict) else {}
    run_asserts = output.get("asserts")
    if isinstance(run_asserts, list):
        return ensure_list_of_dicts(run_asserts)

    generated_plan = output.get("generated_plan") if isinstance(output.get("generated_plan"), dict) else {}
    return ensure_list_of_dicts(generated_plan.get("asserts"))


def extract_run_required_inputs(record: dict[str, Any]) -> list[Any]:
    output = record.get("output") if isinstance(record.get("output"), dict) else {}
    if isinstance(output.get("required_inputs"), list):
        return output["required_inputs"]

    execution_profile = output.get("execution_profile") if isinstance(output.get("execution_profile"), dict) else {}
    if isinstance(execution_profile.get("required_inputs"), list):
        return execution_profile["required_inputs"]
    return []


def extract_run_preflight_checks(record: dict[str, Any]) -> list[Any]:
    output = record.get("output") if isinstance(record.get("output"), dict) else {}
    if isinstance(output.get("preflight_checks"), list):
        return output["preflight_checks"]

    generated_plan = output.get("generated_plan") if isinstance(output.get("generated_plan"), dict) else {}
    if isinstance(generated_plan.get("preflight_checks"), list):
        return generated_plan["preflight_checks"]
    return []


def extract_scenario_type(record: dict[str, Any]) -> str:
    output = record.get("output") if isinstance(record.get("output"), dict) else {}
    metadata = record.get("metadata") if isinstance(record.get("metadata"), dict) else {}

    for candidate in (
        output.get("scenario_type"),
        metadata.get("scenario_type"),
        (output.get("execution_profile") or {}).get("coverage_type") if isinstance(output.get("execution_profile"), dict) else None,
        (output.get("generated_plan") or {}).get("coverage_type") if isinstance(output.get("generated_plan"), dict) else None,
    ):
        if isinstance(candidate, str) and candidate.strip():
            return candidate.strip().lower()

    title = ((record.get("input") or {}).get("title") or "") if isinstance(record.get("input"), dict) else ""
    description = ((record.get("input") or {}).get("description") or "") if isinstance(record.get("input"), dict) else ""
    haystack = f"{title} {description}".lower()
    if "login" in haystack or "connexion" in haystack:
        return "authentication"
    return "generic_ui_check"


def source_has_generic_ui(record: dict[str, Any]) -> bool:
    output = record.get("output") if isinstance(record.get("output"), dict) else {}
    metadata = record.get("metadata") if isinstance(record.get("metadata"), dict) else {}

    candidates = [
        output.get("scenario_type"),
        metadata.get("scenario_type"),
        (output.get("execution_profile") or {}).get("coverage_type") if isinstance(output.get("execution_profile"), dict) else None,
        (output.get("generated_plan") or {}).get("coverage_type") if isinstance(output.get("generated_plan"), dict) else None,
    ]
    for candidate in candidates:
        if isinstance(candidate, str) and candidate.strip().lower() == "generic_ui":
            return True

    for container in (
        output.get("execution_profile"),
        output.get("generated_plan"),
    ):
        if not isinstance(container, dict):
            continue
        diagnostics = container.get("diagnostics")
        if isinstance(diagnostics, list):
            for item in diagnostics:
                if isinstance(item, str) and item.strip().upper() == GENERIC_DIAGNOSTIC:
                    return True

    return False


def canonicalize_base_url(input_payload: dict[str, Any]) -> str:
    for key in ("base_url", "base_url_placeholder"):
        value = input_payload.get(key)
        if isinstance(value, str) and value.strip():
            if value.strip().startswith("{{") and value.strip().endswith("}}"):
                return value.strip()
            return BASE_URL_PLACEHOLDER
    return BASE_URL_PLACEHOLDER


def convert_record(record: dict[str, Any], source_path: Path) -> dict[str, Any]:
    input_payload = record.get("input") if isinstance(record.get("input"), dict) else {}
    output_payload = record.get("output") if isinstance(record.get("output"), dict) else {}
    metadata_payload = record.get("metadata") if isinstance(record.get("metadata"), dict) else {}

    provided_inputs = input_payload.get("provided_inputs", {})
    if isinstance(provided_inputs, list):
        provided_inputs = {}
    if not isinstance(provided_inputs, dict):
        provided_inputs = {}

    scenario_type = extract_scenario_type(record)
    normalized = {
        "input": {
            "title": str(input_payload.get("title", "")).strip(),
            "description": str(input_payload.get("description", "")).strip(),
            "base_url": canonicalize_base_url(input_payload),
            "provided_inputs": normalize_data(provided_inputs),
            "priority": str(input_payload.get("priority") or "Medium").strip() or "Medium",
            "criticality": str(input_payload.get("criticality") or "Major").strip() or "Major",
        },
        "output": {
            "scenario_type": scenario_type,
            "required_inputs": normalize_data(extract_run_required_inputs(record)),
            "preflight_checks": normalize_data(extract_run_preflight_checks(record)),
            "steps": normalize_data(extract_run_steps(record)),
            "asserts": normalize_data(extract_run_asserts(record)),
        },
        "metadata": {
            "source": infer_source(source_path, metadata_payload),
            "quality": str(metadata_payload.get("quality") or "medium").strip().lower() or "medium",
            "scenario_type": scenario_type,
        },
        "__flags": {
            "source_has_generic_ui": source_has_generic_ui(record),
        },
    }
    return normalize_data(normalized)


def is_body_selector(value: Any) -> bool:
    if isinstance(value, str):
        return value.strip().lower() == "body"
    if isinstance(value, dict):
        for key in ("value", "selector", "css", "target", "testid"):
            candidate = value.get(key)
            if isinstance(candidate, str) and candidate.strip().lower() == "body":
                return True
    return False


def step_action(step: dict[str, Any]) -> str:
    return str(step.get("action", "")).strip().lower()


def assert_type(assertion: dict[str, Any]) -> str:
    return str(assertion.get("type") or assertion.get("assert") or "").strip().lower()


def assert_targets_body(assertion: dict[str, Any]) -> bool:
    return any(
        is_body_selector(assertion.get(key))
        for key in ("target", "selector", "value")
    )


def generic_plan_detected(example: dict[str, Any]) -> bool:
    steps = example["output"]["steps"]
    asserts = example["output"]["asserts"]

    if len(steps) == 2:
        first, second = steps
        if step_action(first) == "goto" and step_action(second) == "wait_for_selector" and is_body_selector(second.get("selector") or second.get("target")):
            return True

    if len(asserts) == 1 and assert_type(asserts[0]) in {"expect_visible", "visible"} and assert_targets_body(asserts[0]):
        return True

    return False


def dangerous_workflow_detected(example: dict[str, Any]) -> bool:
    scenario_type = example["output"]["scenario_type"].lower()
    title = example["input"]["title"].lower()
    description = example["input"]["description"].lower()
    haystack = f"{scenario_type} {title} {description}"

    return any(marker in haystack for marker in DANGEROUS_MARKERS)


def generic_ui_detected(example: dict[str, Any]) -> bool:
    if bool(((example.get("__flags") or {}).get("source_has_generic_ui"))):
        return True

    if example["output"]["scenario_type"] == "generic_ui" or example["output"]["scenario_type"] == "generic_ui_check":
        return True
    return False


def validate_example(example: dict[str, Any]) -> tuple[bool, str]:
    if not example["input"]["title"]:
        return False, "missing_input_title"
    if not example["input"]["description"]:
        return False, "missing_input_description"
    if not example["output"]["steps"]:
        return False, "missing_output_steps"
    if not example["output"]["asserts"]:
        return False, "missing_output_asserts"
    if example["metadata"]["quality"] == "low":
        return False, "low_quality"
    if dangerous_workflow_detected(example):
        return False, "dangerous_workflow"
    if generic_plan_detected(example):
        return False, "generic_plan"
    if generic_ui_detected(example):
        return False, "generic_ui"
    return True, ""


def dedupe_key(example: dict[str, Any]) -> str:
    return "||".join(
        [
            example["input"]["title"].strip().lower(),
            example["input"]["description"].strip().lower(),
            example["output"]["scenario_type"].strip().lower(),
        ]
    )


def score_example(example: dict[str, Any]) -> tuple[int, int]:
    quality_score = QUALITY_RANK.get(example["metadata"]["quality"], 0)
    richness_score = len(example["output"]["steps"]) + len(example["output"]["asserts"])
    return quality_score, richness_score


def choose_better(existing: dict[str, Any], candidate: dict[str, Any]) -> dict[str, Any]:
    return candidate if score_example(candidate) > score_example(existing) else existing


def write_jsonl(examples: list[dict[str, Any]], path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="\n") as handle:
        for example in examples:
            persisted = {key: value for key, value in example.items() if key != "__flags"}
            handle.write(json.dumps(persisted, ensure_ascii=False) + "\n")


def main() -> int:
    args = parse_args()
    output_path = resolve_path(args.output_path) if args.output_path else resolve_path(DEFAULT_OUTPUT)

    total_files_read = 0
    total_lines_read = 0
    total_rejected = 0
    total_duplicates_removed = 0
    total_valid_examples = 0
    count_by_source: Counter[str] = Counter()
    rejection_reasons: Counter[str] = Counter()
    deduped: dict[str, dict[str, Any]] = {}

    for relative_path in DEFAULT_INPUTS:
        source_path = resolve_path(relative_path)
        if not source_path.exists():
            warn(f"missing input file ignored: {source_path}")
            continue

        total_files_read += 1
        for line in load_jsonl_lines(source_path):
            if not line.strip():
                continue

            total_lines_read += 1

            try:
                record = json.loads(line)
            except json.JSONDecodeError:
                total_rejected += 1
                rejection_reasons["invalid_json"] += 1
                continue

            if not isinstance(record, dict):
                total_rejected += 1
                rejection_reasons["invalid_record_type"] += 1
                continue

            example = convert_record(record, source_path)
            is_valid, reason = validate_example(example)
            if not is_valid:
                total_rejected += 1
                rejection_reasons[reason] += 1
                continue

            total_valid_examples += 1

            key = dedupe_key(example)
            if key in deduped:
                better = choose_better(deduped[key], example)
                if better is not deduped[key]:
                    deduped[key] = better
                total_duplicates_removed += 1
                continue

            deduped[key] = example

    exported_examples = list(deduped.values())
    exported_examples.sort(
        key=lambda item: (
            item["metadata"]["source"],
            item["output"]["scenario_type"],
            item["input"]["title"].lower(),
        )
    )

    write_jsonl(exported_examples, output_path)

    count_by_source.update(example["metadata"]["source"] for example in exported_examples)
    count_by_scenario_type = Counter(example["output"]["scenario_type"] for example in exported_examples)
    count_by_quality = Counter(example["metadata"]["quality"] for example in exported_examples)

    print(f"total_files_read: {total_files_read}")
    print(f"total_lines_read: {total_lines_read}")
    print(f"total_valid_examples: {total_valid_examples}")
    print(f"total_exported: {len(exported_examples)}")
    print(f"total_rejected: {total_rejected}")
    print(f"total_duplicates_removed: {total_duplicates_removed}")
    print("count_by_source:")
    for source, count in sorted(count_by_source.items()):
        print(f"- {source}: {count}")
    print("count_by_scenario_type:")
    for scenario_type, count in sorted(count_by_scenario_type.items()):
        print(f"- {scenario_type}: {count}")
    print("count_by_quality:")
    for quality, count in sorted(count_by_quality.items()):
        print(f"- {quality}: {count}")
    print("rejection_reasons:")
    if rejection_reasons:
        for reason, count in sorted(rejection_reasons.items()):
            print(f"- {reason}: {count}")
    else:
        print("- none")
    print(f"output_file: {output_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
