#!/usr/bin/env python3
"""Prepare an offline JSONL dataset from external CSV test cases.

This script is intentionally standalone. It does not touch the database,
Laravel models, migrations, endpoints, or the current execution agent.
"""

from __future__ import annotations

import argparse
import csv
import json
from collections import Counter
from pathlib import Path
from typing import Any


BASE_URL_PLACEHOLDER = "{{base_url}}"
GENERATOR_NAME = "offline_rule_based_converter"
DEFAULT_RELATIVE_INPUT = Path("backend/storage/app/agent-training/external/Test_cases.csv")
DEFAULT_RELATIVE_OUTPUT = Path(
    "backend/storage/app/agent-training/external/external_test_cases_dataset.jsonl"
)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Convert external CSV test cases into a JSONL dataset for run_spec prototyping."
    )
    parser.add_argument(
        "--input",
        dest="input_path",
        type=Path,
        default=None,
        help="Optional override for the CSV input path.",
    )
    parser.add_argument(
        "--output",
        dest="output_path",
        type=Path,
        default=None,
        help="Optional override for the JSONL output path.",
    )
    return parser.parse_args()


def repo_root() -> Path:
    return Path(__file__).resolve().parent.parent


def resolve_default_path(relative_path: Path) -> Path:
    root = repo_root()
    candidate = root / relative_path
    if candidate.exists() or str(relative_path).startswith("backend"):
        return candidate

    # Fallback for environments where the script is copied next to a Laravel app.
    fallback = root / relative_path.relative_to("backend")
    return fallback


def normalize_text(value: str) -> str:
    return " ".join(value.lower().replace("/", " ").replace("-", " ").split())


def scenario_type_for(title: str, description: str) -> str:
    title_haystack = normalize_text(title)
    full_haystack = normalize_text(f"{title} {description}")

    if any(token in full_haystack for token in ("mandatory field", "required field", "asterisk", "asterix")):
        return "form_required_fields"
    if any(token in title_haystack for token in ("css style", "red color", "green color", "colour", "color")):
        return "ui_style_validation"
    if "validation error" in full_haystack or "error message" in full_haystack:
        return "form_validation_error"
    if "tooltip" in full_haystack:
        return "tooltip_validation"
    if any(token in full_haystack for token in ("drop down", "dropdown")):
        return "dropdown_validation"
    if "delete" in full_haystack and "confirmation" in full_haystack:
        return "delete_confirmation"

    return "generic_ui_check"


def build_output_for_scenario(scenario_type: str, title: str, description: str) -> dict[str, Any]:
    generic_context = title.strip() or "Untitled external test case"

    templates: dict[str, dict[str, Any]] = {
        "form_required_fields": {
            "required_inputs": [],
            "preflight_checks": [
                {"check": "page_ready", "target": "form"},
                {"check": "required_fields_identified", "source": "labels_and_markers"},
            ],
            "steps": [
                {"action": "goto", "url": BASE_URL_PLACEHOLDER},
                {"action": "scan_required_fields", "target": "form"},
                {"action": "submit_form", "mode": "without_required_inputs"},
            ],
            "asserts": [
                {"assert": "required_field_markers_visible", "target": "required_fields", "marker": "*"},
                {"assert": "required_field_validation_visible", "target": "required_fields"},
            ],
            "quality": "high",
        },
        "form_validation_error": {
            "required_inputs": [
                {"name": "invalid_value", "type": "placeholder", "example": "<invalid_input>"}
            ],
            "preflight_checks": [
                {"check": "page_ready", "target": "form"},
                {"check": "validation_messages_supported", "target": "form_fields"},
            ],
            "steps": [
                {"action": "goto", "url": BASE_URL_PLACEHOLDER},
                {"action": "fill", "target": "first_relevant_input", "value": "<invalid_input>"},
                {"action": "submit_form", "target": "primary_form"},
            ],
            "asserts": [
                {"assert": "validation_message_visible", "target": "field_or_inline_message"},
                {"assert": "validation_message_position_correct", "target": "field_or_inline_message"},
            ],
            "quality": "high",
        },
        "ui_style_validation": {
            "required_inputs": [],
            "preflight_checks": [
                {"check": "page_ready", "target": "message_area"},
                {"check": "style_reference_available", "target": "message_component"},
            ],
            "steps": [
                {"action": "goto", "url": BASE_URL_PLACEHOLDER},
                {"action": "trigger_message_state", "target": "message_component", "mode": "non_destructive"},
            ],
            "asserts": [
                {"assert": "message_visible", "target": "message_component"},
                {"assert": "style_matches_expected", "target": "message_component", "style_hint": "color_or_css_variant"},
            ],
            "quality": "medium",
        },
        "tooltip_validation": {
            "required_inputs": [],
            "preflight_checks": [
                {"check": "page_ready", "target": "tooltip_trigger"},
                {"check": "tooltip_trigger_present", "target": "help_icon_or_labeled_control"},
            ],
            "steps": [
                {"action": "goto", "url": BASE_URL_PLACEHOLDER},
                {"action": "hover", "target": "tooltip_trigger"},
            ],
            "asserts": [
                {"assert": "tooltip_visible", "target": "tooltip_container"},
                {"assert": "tooltip_text_meaningful", "target": "tooltip_container"},
            ],
            "quality": "high",
        },
        "dropdown_validation": {
            "required_inputs": [
                {"name": "option_label", "type": "placeholder", "example": "<option_label>"}
            ],
            "preflight_checks": [
                {"check": "page_ready", "target": "dropdown_field"},
                {"check": "dropdown_present", "target": "dropdown_field"},
            ],
            "steps": [
                {"action": "goto", "url": BASE_URL_PLACEHOLDER},
                {"action": "click", "target": "dropdown_field"},
                {"action": "select_option", "target": "dropdown_field", "value": "<option_label>"},
            ],
            "asserts": [
                {"assert": "dropdown_option_visible", "target": "dropdown_menu"},
                {"assert": "dropdown_value_selected", "target": "dropdown_field", "value": "<option_label>"},
            ],
            "quality": "high",
        },
        "delete_confirmation": {
            "required_inputs": [],
            "preflight_checks": [
                {"check": "page_ready", "target": "record_list"},
                {"check": "deletable_record_present", "target": "record_row"},
            ],
            "steps": [
                {"action": "goto", "url": BASE_URL_PLACEHOLDER},
                {"action": "click", "target": "delete_control_for_record"},
            ],
            "asserts": [
                {"assert": "confirmation_dialog_visible", "target": "confirmation_dialog"},
                {"assert": "confirmation_actions_present", "target": "confirmation_dialog", "actions": ["confirm", "cancel"]},
            ],
            "quality": "high",
        },
        "generic_ui_check": {
            "required_inputs": [],
            "preflight_checks": [
                {"check": "page_ready", "target": "main_content"},
            ],
            "steps": [
                {"action": "goto", "url": BASE_URL_PLACEHOLDER},
                {"action": "observe_ui_state", "target": "main_content", "note": generic_context},
            ],
            "asserts": [
                {"assert": "primary_content_visible", "target": "main_content"},
            ],
            "quality": "low",
        },
    }

    template = templates[scenario_type]

    return {
        "scenario_type": scenario_type,
        "required_inputs": template["required_inputs"],
        "preflight_checks": template["preflight_checks"],
        "steps": template["steps"],
        "asserts": template["asserts"],
    }, template["quality"]


def convert_row(row: dict[str, str], source_name: str) -> dict[str, Any]:
    title = (row.get("Title") or "").strip()
    description = (row.get("Description") or "").strip()
    test_id = (row.get("Test Id") or "").strip()

    scenario_type = scenario_type_for(title, description)
    output, quality = build_output_for_scenario(scenario_type, title, description)

    return {
        "input": {
            "title": title,
            "description": description,
            "base_url_placeholder": BASE_URL_PLACEHOLDER,
        },
        "output": output,
        "metadata": {
            "source_file": source_name,
            "source_test_id": test_id,
            "quality": quality,
            "generated_by": GENERATOR_NAME,
        },
    }


def load_rows(input_path: Path) -> list[dict[str, str]]:
    with input_path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        return [dict(row) for row in reader]


def write_jsonl(records: list[dict[str, Any]], output_path: Path) -> None:
    output_path.parent.mkdir(parents=True, exist_ok=True)
    with output_path.open("w", encoding="utf-8", newline="\n") as handle:
        for record in records:
            handle.write(json.dumps(record, ensure_ascii=False) + "\n")


def print_summary(rows_read: int, records: list[dict[str, Any]], output_path: Path) -> None:
    converted = len(records)
    scenario_counts = Counter(record["output"]["scenario_type"] for record in records)
    low_quality = sum(1 for record in records if record["metadata"]["quality"] == "low")

    print(f"Rows read: {rows_read}")
    print(f"Rows converted: {converted}")
    print("Counts by scenario_type:")
    for scenario_type in sorted(scenario_counts):
        print(f"- {scenario_type}: {scenario_counts[scenario_type]}")
    print(f"Low quality examples: {low_quality}")
    print(f"Output file: {output_path}")


def main() -> int:
    args = parse_args()

    input_path = args.input_path or resolve_default_path(DEFAULT_RELATIVE_INPUT)
    output_path = args.output_path or resolve_default_path(DEFAULT_RELATIVE_OUTPUT)

    if not input_path.exists():
        print(f"Input file not found: {input_path}")
        return 1

    rows = load_rows(input_path)
    records = [convert_row(row, input_path.name) for row in rows]

    write_jsonl(records, output_path)
    print_summary(len(rows), records, output_path)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
