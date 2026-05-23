#!/usr/bin/env python3
"""Validate, normalize, merge, and deduplicate offline training datasets."""

from __future__ import annotations

import argparse
import json
import re
from collections import Counter
from pathlib import Path
from typing import Any


BASE_URL = "{{base_url}}"
DEFAULT_INPUTS = [
    Path("backend/storage/app/agent-training/training_dataset.jsonl"),
    Path("backend/storage/app/agent-training/codegen_examples/codegen_cleaned_examples.jsonl"),
    Path("backend/storage/app/agent-training/external/external_test_cases_dataset.jsonl"),
]
DEFAULT_FINAL_OUTPUT = Path("backend/storage/app/agent-training/final_training_dataset.jsonl")
DEFAULT_REJECTED_OUTPUT = Path("backend/storage/app/agent-training/rejected_training_examples.jsonl")
DEFAULT_REPORT_OUTPUT = Path("backend/storage/app/agent-training/dataset_quality_report.json")
QUALITY_RANK = {"high": 3, "medium": 2, "low": 1}
SUPPORTED_ACTIONS = {
    "goto",
    "click",
    "fill",
    "select_option",
    "hover",
    "set_file",
    "wait_for_selector",
    "wait_for_url",
    "press",
    "screenshot",
}
USEFUL_ASSERT_TYPES = {
    "text_visible",
    "url_contains",
    "url_not_contains",
    "selector_visible",
    "locator_visible",
    "validation_message_visible",
    "confirmation_dialog_visible",
    "dropdown_value_selected",
    "dropdown_option_visible",
    "required_field_markers_visible",
    "required_field_validation_visible",
    "tooltip_visible",
    "tooltip_text_meaningful",
    "style_matches_expected",
    "history_entry_visible",
    "execution_panel_visible",
    "notifications_list_visible",
    "selected_value_visible",
    "page_section_visible",
    "filtered_results_visible",
    "submit_blocked_or_invalid_state",
    "text_or_state_visible",
    "message_visible",
    "user_visible_in_active_list",
    "confirmation_actions_present",
    "refresh_state_visible",
}
MEANINGFUL_ACTIONS = {"click", "fill", "select_option", "hover", "set_file", "press", "wait_for_url"}
DANGEROUS_MARKERS = {
    "permanent_delete",
    "destructive_delete",
    "irreversible_delete",
    "irreversible_archive",
}
BROKEN_PLACEHOLDER_RE = re.compile(r"\{\{\{\{+|\}\}\}\}+")
EMAIL_RE = re.compile(r"\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b", re.I)
TOKEN_RE = re.compile(
    r"\b(?:ghp|gho|ghu|ghs|sk|pk)_[A-Za-z0-9]{8,}\b|\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9._-]+\.[A-Za-z0-9._-]+\b",
    re.I,
)
LOCAL_FILE_RE = re.compile(
    r"([A-Za-z]:\\|/)?(?:[\w .()-]+[\\/])+[\w .()-]+\.(?:png|jpg|jpeg|pdf|csv|zip|docx|txt|exe)",
    re.I,
)
URL_RE = re.compile(r"https?://[^\s'\"}]+", re.I)
PLACEHOLDER_RE = re.compile(r"\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Validate and normalize the final offline training dataset.")
    parser.add_argument("--final-output", dest="final_output", type=Path, default=None)
    parser.add_argument("--rejected-output", dest="rejected_output", type=Path, default=None)
    parser.add_argument("--report-output", dest="report_output", type=Path, default=None)
    return parser.parse_args()


def repo_root() -> Path:
    return Path(__file__).resolve().parent.parent


def resolve_path(path: Path) -> Path:
    return repo_root() / path


def warn(message: str) -> None:
    print(f"Warning: {message}")


def read_lines(path: Path) -> list[str]:
    return path.read_text(encoding="utf-8").splitlines()


def repair_placeholders(text: str, corrections: Counter[str]) -> str:
    original = text
    replacements = {
        "[REDACTED_EMAIL]": "{{email}}",
        "[REDACTED_PASSWORD]": "{{password}}",
        "[REDACTED_TOKEN]": "{{token}}",
        "[REDACTED]": "{{token}}",
    }
    for source, target in replacements.items():
        if source in text:
            corrections[f"replace_{source.lower().strip('[]')}"] += text.count(source)
            text = text.replace(source, target)

    def collapse_placeholder(match: re.Match[str]) -> str:
        name = match.group(1).strip().lower()
        corrections["collapsed_broken_placeholder"] += 1
        return "{{" + name + "}}"

    text = re.sub(r"\{+\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}+", collapse_placeholder, text)

    if text != original and "{{{{" in text:
        text = text.replace("{{{{", "{{")
    if text != original and "}}}}" in text:
        text = text.replace("}}}}", "}}")

    return text


def normalize_scalar(value: str, corrections: Counter[str]) -> str:
    value = repair_placeholders(value, corrections)

    if EMAIL_RE.search(value):
        corrections["email_placeholder"] += len(EMAIL_RE.findall(value))
        value = EMAIL_RE.sub("{{email}}", value)

    password_patterns = [
        (re.compile(r"\bpassword123\b", re.I), "{{password}}"),
        (re.compile(r"(?<=['\":=\s])(?:[Pp]assword|[Pp]asswd|[Pp]wd)(?=['\":=\s]|$)"), "password"),
    ]
    for pattern, replacement in password_patterns:
        if pattern.search(value):
            corrections["password_placeholder"] += len(pattern.findall(value))
            value = pattern.sub(replacement, value)

    if TOKEN_RE.search(value):
        corrections["token_placeholder"] += len(TOKEN_RE.findall(value))
        value = TOKEN_RE.sub("{{token}}", value)

    if LOCAL_FILE_RE.search(value):
        corrections["file_path_placeholder"] += len(LOCAL_FILE_RE.findall(value))
        value = LOCAL_FILE_RE.sub("{{file_path}}", value)

    if URL_RE.search(value):
        urls = URL_RE.findall(value)
        corrections["base_url_placeholder"] += len(urls)
        value = URL_RE.sub(BASE_URL, value)

    return value


def normalize_data(value: Any, corrections: Counter[str]) -> Any:
    if isinstance(value, dict):
        return {key: normalize_data(item, corrections) for key, item in value.items()}
    if isinstance(value, list):
        return [normalize_data(item, corrections) for item in value]
    if isinstance(value, str):
        return normalize_scalar(value, corrections)
    return value


def ensure_dict(value: Any) -> dict[str, Any]:
    return value if isinstance(value, dict) else {}


def ensure_list(value: Any) -> list[Any]:
    return value if isinstance(value, list) else []


def infer_source(source_path: Path, metadata: dict[str, Any]) -> str:
    source = metadata.get("source")
    if isinstance(source, str) and source.strip():
        return source.strip()
    if source_path.name == "training_dataset.jsonl":
        return "playwright_run"
    if metadata.get("generated_by") == "offline_rule_based_converter":
        return "external_test_cases"
    if source_path.name == "codegen_cleaned_examples.jsonl":
        return "playwright_codegen"
    return "unknown"


def scenario_from_keywords(title: str, description: str) -> str | None:
    haystack = f"{title} {description}".lower()

    if any(token in haystack for token in ("mandatory", "required fields", "required field", "asterisk", "asterix")):
        return "form_required_fields"
    if any(token in haystack for token in ("aligned properly", "spacing", "layout", "left-justified", "left justified")):
        return "ui_layout_validation"
    if any(token in haystack for token in ("css style", "red color", "green color", "colour", "color")):
        return "ui_style_validation"
    if any(token in haystack for token in ("application crash", "unavailable pages", "error page")):
        return "error_page_redirection"
    if any(token in haystack for token in ("image upload", "file upload", "file type", "upload functionality", "upload")):
        return "upload_validation"
    if "confirmation messages" in haystack and ("update" in haystack or "delete" in haystack):
        return "update_delete_confirmation"
    if any(token in haystack for token in ("drop-down", "dropdown", "drop down", "select list")):
        return "dropdown_validation"
    if any(token in haystack for token in ("validation error", "invalid values", "error messages", "error message")):
        return "form_validation_error"
    if "tooltip" in haystack:
        return "tooltip_validation"
    if any(token in haystack for token in ("delete confirmation", "ask for a confirmation")):
        return "delete_confirmation"
    if any(token in haystack for token in ("login", "connexion", "mot de passe", "password reset")):
        if "reset" in haystack or "mot de passe oublié" in haystack or "mot de passe oublie" in haystack:
            return "password_reset"
        return "authentication"
    return None


def extract_raw_scenario(record: dict[str, Any]) -> str:
    output = ensure_dict(record.get("output"))
    metadata = ensure_dict(record.get("metadata"))
    for candidate in (
        output.get("scenario_type"),
        metadata.get("scenario_type"),
        ensure_dict(output.get("execution_profile")).get("coverage_type"),
        ensure_dict(output.get("generated_plan")).get("coverage_type"),
    ):
        if isinstance(candidate, str) and candidate.strip():
            return candidate.strip().lower()
    return ""


def normalize_required_inputs(values: list[Any], corrections: Counter[str]) -> list[dict[str, str]]:
    normalized: list[dict[str, str]] = []
    seen: set[str] = set()
    for value in values:
        if isinstance(value, str):
            name = value.strip().lower()
            if not name:
                continue
            placeholder = "{{" + name + "}}"
        elif isinstance(value, dict):
            name = str(value.get("name") or value.get("key") or "").strip().lower()
            if not name:
                continue
            placeholder = str(value.get("placeholder") or "{{" + name + "}}").strip()
            placeholder = normalize_scalar(placeholder, corrections)
        else:
            continue

        placeholder = repair_placeholders(placeholder, corrections)
        entry_key = f"{name}:{placeholder}"
        if entry_key in seen:
            continue
        seen.add(entry_key)
        normalized.append({"name": name, "placeholder": placeholder})
    return normalized


def extract_placeholders(value: Any) -> set[str]:
    placeholders: set[str] = set()
    if isinstance(value, str):
        for match in PLACEHOLDER_RE.findall(value):
            if match != "base_url":
                placeholders.add(match)
        return placeholders
    if isinstance(value, dict):
        for item in value.values():
            placeholders.update(extract_placeholders(item))
        return placeholders
    if isinstance(value, list):
        for item in value:
            placeholders.update(extract_placeholders(item))
    return placeholders


def align_required_input_name(name: str, placeholder: str, stats: dict[str, Counter[str] | int]) -> str:
    placeholder_name = placeholder.strip()[2:-2] if placeholder.startswith("{{") and placeholder.endswith("}}") else name
    aligned_name = name

    known_mappings = {
        "new_role": "role",
        "app_url": "project_app_url",
        "new_status": "status",
    }

    if name in known_mappings:
        aligned_name = known_mappings[name]

    if placeholder_name and placeholder_name != aligned_name and placeholder_name not in {"email", "password", "token", "comment", "option_label", "invalid_value", "project_name", "project_name_filter", "project_app_url", "role", "status"}:
        placeholder_name = aligned_name

    if placeholder_name and placeholder_name != aligned_name:
        aligned_name = placeholder_name

    if aligned_name != name:
        stats["required_input_names_aligned"][f"{name}->{aligned_name}"] += 1  # type: ignore[index]

    return aligned_name


def normalize_provided_input_value(name: str, value: Any, corrections: Counter[str]) -> str:
    if isinstance(value, str):
        normalized = normalize_scalar(value, corrections)
    else:
        normalized = "{{" + name + "}}"

    if name in {"email", "password", "token"}:
        return "{{" + name + "}}"
    if name in {"role", "status"}:
        return normalized if normalized and not normalized.startswith("{{") else "{{" + name + "}}"
    return normalized if normalized else "{{" + name + "}}"


def enforce_placeholder_contract(
    example: dict[str, Any],
    stats: dict[str, Counter[str] | int],
    corrections: Counter[str],
) -> None:
    required_inputs = example["output"]["required_inputs"]
    provided_inputs = example["input"]["provided_inputs"]

    normalized_required: list[dict[str, str]] = []
    seen_required: set[str] = set()
    for item in required_inputs:
        if not isinstance(item, dict):
            continue
        placeholder = str(item.get("placeholder") or "").strip()
        name = str(item.get("name") or "").strip().lower()
        if not name and placeholder.startswith("{{") and placeholder.endswith("}}"):
            name = placeholder[2:-2]
        if not name:
            continue

        if not placeholder:
            placeholder = "{{" + name + "}}"

        aligned_name = align_required_input_name(name, placeholder, stats)
        aligned_placeholder = "{{" + aligned_name + "}}"
        key = f"{aligned_name}:{aligned_placeholder}"
        if key in seen_required:
            continue
        seen_required.add(key)
        normalized_required.append({"name": aligned_name, "placeholder": aligned_placeholder})

    placeholder_names = set()
    placeholder_names.update(extract_placeholders(example["output"]["steps"]))
    placeholder_names.update(extract_placeholders(example["output"]["asserts"]))
    placeholder_names.update(extract_placeholders([item.get("placeholder") for item in normalized_required]))

    required_names = {item["name"] for item in normalized_required}
    missing_required = sorted(name for name in placeholder_names if name not in required_names and name != "base_url")
    for name in missing_required:
        normalized_required.append({"name": name, "placeholder": "{{" + name + "}}"})
        stats["required_inputs_added_from_placeholders"][name] += 1  # type: ignore[index]

    normalized_provided: dict[str, Any] = {}
    for key, value in list(provided_inputs.items()):
        normalized_key = str(key).strip()
        if not normalized_key:
            continue
        aligned_key = {
            "new_role": "role",
            "app_url": "project_app_url",
            "new_status": "status",
        }.get(normalized_key, normalized_key)
        if aligned_key != normalized_key:
            stats["required_input_names_aligned"][f"{normalized_key}->{aligned_key}"] += 1  # type: ignore[index]
        normalized_provided[aligned_key] = normalize_provided_input_value(aligned_key, value, corrections)

    for item in normalized_required:
        name = item["name"]
        if name not in normalized_provided:
            normalized_provided[name] = "{{" + name + "}}"
            stats["provided_inputs_added"][name] += 1  # type: ignore[index]

    example["output"]["required_inputs"] = sorted(normalized_required, key=lambda item: item["name"])
    example["input"]["provided_inputs"] = dict(sorted(normalized_provided.items()))

    if missing_required or normalized_provided:
        stats["placeholder_contract_fixed"]["count"] += 1  # type: ignore[index]


def normalize_asserts(asserts: list[Any], corrections: Counter[str]) -> list[dict[str, Any]]:
    normalized: list[dict[str, Any]] = []
    for raw in asserts:
        if not isinstance(raw, dict):
            continue
        assert_type = str(raw.get("type") or raw.get("assert") or "").strip()
        if not assert_type:
            continue
        entry: dict[str, Any] = {"type": normalize_scalar(assert_type, corrections)}
        if "target" in raw and str(raw.get("target")).strip():
            entry["target"] = normalize_data(raw.get("target"), corrections)
        elif "selector" in raw and raw.get("selector") not in (None, ""):
            entry["target"] = normalize_data(raw.get("selector"), corrections)
        if "value" in raw and raw.get("value") not in (None, ""):
            entry["value"] = normalize_data(raw.get("value"), corrections)
        if "marker" in raw and raw.get("marker") not in (None, ""):
            entry["value"] = normalize_data(raw.get("marker"), corrections)
        if "actions" in raw and isinstance(raw.get("actions"), list):
            entry["value"] = normalize_data(raw.get("actions"), corrections)
        if "style_hint" in raw and raw.get("style_hint") not in (None, ""):
            entry["value"] = normalize_data(raw.get("style_hint"), corrections)
        normalized.append(entry)
    return normalized


def normalize_steps(steps: list[Any], corrections: Counter[str]) -> tuple[list[dict[str, Any]], bool, bool]:
    normalized: list[dict[str, Any]] = []
    had_abstract_action = False
    unresolved_action = False

    for raw in steps:
        if not isinstance(raw, dict):
            continue
        action = str(raw.get("action") or "").strip().lower()
        if not action:
            continue

        entry: dict[str, Any] = {"action": action}

        target = raw.get("target")
        if target in (None, ""):
            target = raw.get("selector")
        if target in (None, ""):
            target = raw.get("field")

        if action == "scan_required_fields":
            had_abstract_action = True
            action = "wait_for_selector"
            entry["action"] = action
            target = target or "required_fields"
        elif action == "submit_form":
            had_abstract_action = True
            action = "click"
            entry["action"] = action
            target = target or "submit_button"
        elif action == "trigger_message_state":
            had_abstract_action = True
            action = "click"
            entry["action"] = action
            target = target or "message_component"
        elif action in {"inspect", "observe_ui_state", "check_visible"}:
            unresolved_action = True
            continue

        if action not in SUPPORTED_ACTIONS:
            unresolved_action = True
            continue

        if action == "goto":
            url = str(raw.get("url") or BASE_URL).strip() or BASE_URL
            if not url.startswith("{{"):
                url = normalize_scalar(url, corrections)
            entry["url"] = url
        else:
            if target not in (None, ""):
                entry["target"] = normalize_data(target, corrections)

        if "value" in raw and raw.get("value") not in (None, ""):
            entry["value"] = normalize_data(raw.get("value"), corrections)
        if "key" in raw and raw.get("key") not in (None, ""):
            entry["value"] = normalize_data(raw.get("key"), corrections)
        if "mode" in raw and raw.get("mode") not in (None, "") and "value" not in entry:
            entry["value"] = normalize_data(raw.get("mode"), corrections)

        normalized.append(entry)

    deduped: list[dict[str, Any]] = []
    seen_serialized: set[str] = set()
    for entry in normalized:
        serialized = json.dumps(entry, sort_keys=True, ensure_ascii=False)
        if serialized in seen_serialized:
            continue
        seen_serialized.add(serialized)
        deduped.append(entry)

    return deduped, had_abstract_action, unresolved_action


def normalize_preflight_checks(values: list[Any], corrections: Counter[str]) -> list[dict[str, Any]]:
    normalized: list[dict[str, Any]] = []
    for item in values:
        if isinstance(item, dict):
            check = str(item.get("check") or "").strip()
            if not check:
                continue
            entry = {"check": normalize_scalar(check, corrections)}
            if item.get("target") not in (None, ""):
                entry["target"] = normalize_data(item.get("target"), corrections)
            if item.get("source") not in (None, ""):
                entry["source"] = normalize_data(item.get("source"), corrections)
            normalized.append(entry)
        elif isinstance(item, str) and item.strip():
            normalized.append({"check": normalize_scalar(item.strip(), corrections)})
    return normalized


def normalize_base_url(input_payload: dict[str, Any]) -> str:
    value = input_payload.get("base_url")
    if not isinstance(value, str) or not value.strip():
        value = input_payload.get("base_url_placeholder")
    if isinstance(value, str) and value.strip().startswith("{{") and value.strip().endswith("}}"):
        return value.strip()
    return BASE_URL


def extract_steps(record: dict[str, Any]) -> list[Any]:
    output = ensure_dict(record.get("output"))
    if isinstance(output.get("steps"), list):
        return output["steps"]
    return ensure_dict(output.get("generated_plan")).get("steps", [])


def extract_asserts(record: dict[str, Any]) -> list[Any]:
    output = ensure_dict(record.get("output"))
    if isinstance(output.get("asserts"), list):
        return output["asserts"]
    return ensure_dict(output.get("generated_plan")).get("asserts", [])


def extract_required_inputs(record: dict[str, Any]) -> list[Any]:
    output = ensure_dict(record.get("output"))
    if isinstance(output.get("required_inputs"), list):
        return output["required_inputs"]
    return ensure_dict(output.get("execution_profile")).get("required_inputs", [])


def extract_preflight_checks(record: dict[str, Any]) -> list[Any]:
    output = ensure_dict(record.get("output"))
    if isinstance(output.get("preflight_checks"), list):
        return output["preflight_checks"]
    generated_plan = ensure_dict(output.get("generated_plan"))
    if isinstance(generated_plan.get("preflight_checks"), list):
        return generated_plan["preflight_checks"]
    return []


def source_has_generic_flags(record: dict[str, Any]) -> bool:
    raw_scenario = extract_raw_scenario(record)
    if raw_scenario == "generic_ui":
        return True

    output = ensure_dict(record.get("output"))
    for container in (ensure_dict(output.get("execution_profile")), ensure_dict(output.get("generated_plan"))):
        diagnostics = container.get("diagnostics")
        if not isinstance(diagnostics, list):
            continue
        for value in diagnostics:
            if isinstance(value, str) and value.strip().upper() == "GENERIC_UI_FALLBACK":
                return True
    return False


def convert_record(record: dict[str, Any], source_path: Path, stats: dict[str, Counter[str] | int]) -> dict[str, Any]:
    corrections = Counter()
    input_payload = ensure_dict(record.get("input"))
    metadata_payload = ensure_dict(record.get("metadata"))
    title = str(input_payload.get("title") or "").strip()
    description = str(input_payload.get("description") or "").strip()
    raw_scenario = extract_raw_scenario(record)
    inferred = scenario_from_keywords(title, description)
    scenario_type = inferred or raw_scenario or "generic_ui_check"

    if raw_scenario and inferred and raw_scenario != inferred:
        stats["scenario_type_corrections"][f"{raw_scenario}->{inferred}"] += 1  # type: ignore[index]

    steps, had_abstract_action, unresolved_action = normalize_steps(extract_steps(record), corrections)
    asserts = normalize_asserts(extract_asserts(record), corrections)
    required_inputs = normalize_required_inputs(extract_required_inputs(record), corrections)
    preflight_checks = normalize_preflight_checks(extract_preflight_checks(record), corrections)

    provided_inputs = input_payload.get("provided_inputs", {})
    if not isinstance(provided_inputs, dict):
        provided_inputs = {}
    provided_inputs = normalize_data(provided_inputs, corrections)

    quality = str(metadata_payload.get("quality") or "").strip().lower()
    if quality not in {"high", "medium", "low"}:
        quality = ""

    example = {
        "input": {
            "title": normalize_scalar(title, corrections),
            "description": normalize_scalar(description, corrections),
            "base_url": normalize_base_url(input_payload),
            "provided_inputs": provided_inputs,
            "priority": str(input_payload.get("priority") or "Medium").strip() or "Medium",
            "criticality": str(input_payload.get("criticality") or "Major").strip() or "Major",
        },
        "output": {
            "scenario_type": scenario_type,
            "required_inputs": required_inputs,
            "preflight_checks": preflight_checks,
            "steps": steps,
            "asserts": asserts,
        },
        "metadata": {
            "source": infer_source(source_path, metadata_payload),
            "quality": quality,
            "scenario_type": scenario_type,
        },
        "__internal": {
            "raw_scenario_type": raw_scenario,
            "source_has_generic_flags": source_has_generic_flags(record),
            "had_abstract_action": had_abstract_action,
            "unresolved_action": unresolved_action,
            "placeholder_corrections": dict(corrections),
        },
    }

    for key, count in corrections.items():
        stats["placeholder_corrections"][key] += count  # type: ignore[index]

    enforce_placeholder_contract(example, stats, corrections)

    return example


def is_broken_placeholder(text: str) -> bool:
    if "{{{{" in text or "}}}}" in text:
        return True
    if BROKEN_PLACEHOLDER_RE.search(text):
        return True
    return False


def contains_broken_placeholder(value: Any) -> bool:
    if isinstance(value, str):
        return is_broken_placeholder(value)
    if isinstance(value, dict):
        return any(contains_broken_placeholder(item) for item in value.values())
    if isinstance(value, list):
        return any(contains_broken_placeholder(item) for item in value)
    return False


def is_body_target(value: Any) -> bool:
    if isinstance(value, str):
        return value.strip().lower() == "body"
    return False


def useful_assert_present(asserts: list[dict[str, Any]]) -> bool:
    for assertion in asserts:
        if str(assertion.get("type") or "").strip().lower() in USEFUL_ASSERT_TYPES:
            if not (assertion.get("type") in {"selector_visible", "locator_visible"} and is_body_target(assertion.get("target"))):
                return True
    return False


def generic_plan_detected(example: dict[str, Any]) -> bool:
    steps = example["output"]["steps"]
    asserts = example["output"]["asserts"]

    if len(steps) == 2:
        first, second = steps
        if (
            first.get("action") == "goto"
            and second.get("action") == "wait_for_selector"
            and is_body_target(second.get("target"))
        ):
            return True

    if len(asserts) == 1:
        assertion = asserts[0]
        if str(assertion.get("type") or "").strip().lower() in {"expect_visible", "visible", "selector_visible", "locator_visible"} and is_body_target(assertion.get("target")):
            return True

    return False


def dangerous_workflow_detected(example: dict[str, Any]) -> bool:
    haystack = " ".join(
        [
            example["output"]["scenario_type"],
            example["input"]["title"],
            example["input"]["description"],
            json.dumps(example["output"], ensure_ascii=False),
        ]
    ).lower()

    if any(marker in haystack for marker in DANGEROUS_MARKERS):
        return True

    if "supprimer definitivement" in haystack or "permanent delete" in haystack:
        return True

    if "raw deletion" in haystack and "confirmation" not in haystack:
        return True

    return False


def compute_quality(example: dict[str, Any]) -> str:
    steps = example["output"]["steps"]
    asserts = example["output"]["asserts"]
    scenario_type = example["output"]["scenario_type"]
    meaningful_actions = sum(1 for step in steps if step.get("action") in MEANINGFUL_ACTIONS)
    useful_asserts = useful_assert_present(asserts)
    abstract_penalty = bool(example["__internal"]["had_abstract_action"])  # type: ignore[index]
    unresolved = bool(example["__internal"]["unresolved_action"])  # type: ignore[index]

    if unresolved or generic_plan_detected(example) or not useful_asserts:
        return "low"

    if meaningful_actions >= 1 and useful_asserts and scenario_type not in {"generic_ui", "generic_ui_check"} and not abstract_penalty:
        return "high"

    return "medium"


def validate_example(example: dict[str, Any]) -> tuple[bool, str]:
    if not example["input"]["title"]:
        return False, "missing_input_title"
    if not example["input"]["description"]:
        return False, "missing_input_description"
    if not example["output"]["steps"]:
        return False, "missing_output_steps"
    if not example["output"]["asserts"]:
        return False, "missing_output_asserts"
    if contains_broken_placeholder(example):
        return False, "broken_placeholder"
    if example["__internal"]["unresolved_action"]:  # type: ignore[index]
        return False, "unsupported_action"
    if dangerous_workflow_detected(example):
        return False, "dangerous_workflow"
    if example["__internal"]["source_has_generic_flags"]:  # type: ignore[index]
        return False, "generic_ui"
    if generic_plan_detected(example):
        return False, "generic_plan"
    if example["metadata"]["quality"] == "low":
        return False, "low_quality"
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
    richness = len(example["output"]["steps"]) + len(example["output"]["asserts"])
    return quality_score, richness


def choose_better(existing: dict[str, Any], candidate: dict[str, Any]) -> dict[str, Any]:
    return candidate if score_example(candidate) > score_example(existing) else existing


def write_jsonl(records: list[dict[str, Any]], path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="\n") as handle:
        for record in records:
            persisted = {key: value for key, value in record.items() if not key.startswith("__")}
            handle.write(json.dumps(persisted, ensure_ascii=False) + "\n")


def write_json(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")


def main() -> int:
    args = parse_args()
    final_output = resolve_path(args.final_output) if args.final_output else resolve_path(DEFAULT_FINAL_OUTPUT)
    rejected_output = resolve_path(args.rejected_output) if args.rejected_output else resolve_path(DEFAULT_REJECTED_OUTPUT)
    report_output = resolve_path(args.report_output) if args.report_output else resolve_path(DEFAULT_REPORT_OUTPUT)

    stats: dict[str, Any] = {
        "total_lines_read": 0,
        "exported_examples": 0,
        "rejected_examples": 0,
        "duplicates_removed": 0,
        "count_by_source": Counter(),
        "count_by_scenario_type": Counter(),
        "count_by_quality": Counter(),
        "rejection_reasons": Counter(),
        "placeholder_corrections": Counter(),
        "scenario_type_corrections": Counter(),
        "provided_inputs_added": Counter(),
        "required_inputs_added_from_placeholders": Counter(),
        "required_input_names_aligned": Counter(),
        "placeholder_contract_fixed": Counter(),
        "examples_with_fill": 0,
        "examples_with_click": 0,
        "examples_with_select_option": 0,
        "examples_with_hover": 0,
        "examples_with_set_file": 0,
        "examples_with_useful_asserts": 0,
    }

    deduped: dict[str, dict[str, Any]] = {}
    rejected_records: list[dict[str, Any]] = []

    for relative_path in DEFAULT_INPUTS:
        source_path = resolve_path(relative_path)
        if not source_path.exists():
            warn(f"missing input file ignored: {source_path}")
            continue

        for line_number, line in enumerate(read_lines(source_path), start=1):
            if not line.strip():
                continue

            stats["total_lines_read"] += 1

            try:
                record = json.loads(line)
            except json.JSONDecodeError:
                stats["rejected_examples"] += 1
                stats["rejection_reasons"]["invalid_json"] += 1
                rejected_records.append(
                    {
                        "reason": "invalid_json",
                        "source_file": str(relative_path),
                        "line_number": line_number,
                        "raw_line": line,
                    }
                )
                continue

            if not isinstance(record, dict):
                stats["rejected_examples"] += 1
                stats["rejection_reasons"]["invalid_record_type"] += 1
                rejected_records.append(
                    {
                        "reason": "invalid_record_type",
                        "source_file": str(relative_path),
                        "line_number": line_number,
                        "raw_record": record,
                    }
                )
                continue

            example = convert_record(record, source_path, stats)

            if not example["metadata"]["quality"]:
                example["metadata"]["quality"] = "medium"

            computed_quality = compute_quality(example)
            if QUALITY_RANK[computed_quality] < QUALITY_RANK.get(example["metadata"]["quality"], 0):
                example["metadata"]["quality"] = computed_quality
            elif example["metadata"]["quality"] == "":
                example["metadata"]["quality"] = computed_quality

            if example["metadata"]["quality"] not in {"high", "medium", "low"}:
                example["metadata"]["quality"] = computed_quality

            is_valid, reason = validate_example(example)
            if not is_valid:
                stats["rejected_examples"] += 1
                stats["rejection_reasons"][reason] += 1
                rejected_records.append(
                    {
                        "reason": reason,
                        "source_file": str(relative_path),
                        "line_number": line_number,
                        "example": {key: value for key, value in example.items() if key != "__internal"},
                    }
                )
                continue

            key = dedupe_key(example)
            if key in deduped:
                deduped[key] = choose_better(deduped[key], example)
                stats["duplicates_removed"] += 1
                continue

            deduped[key] = example

    exported_records = list(deduped.values())
    exported_records.sort(
        key=lambda item: (
            item["metadata"]["source"],
            item["output"]["scenario_type"],
            item["input"]["title"].lower(),
        )
    )

    for example in exported_records:
        stats["count_by_source"][example["metadata"]["source"]] += 1
        stats["count_by_scenario_type"][example["output"]["scenario_type"]] += 1
        stats["count_by_quality"][example["metadata"]["quality"]] += 1

        actions = {step.get("action") for step in example["output"]["steps"]}
        if "fill" in actions:
            stats["examples_with_fill"] += 1
        if "click" in actions:
            stats["examples_with_click"] += 1
        if "select_option" in actions:
            stats["examples_with_select_option"] += 1
        if "hover" in actions:
            stats["examples_with_hover"] += 1
        if "set_file" in actions:
            stats["examples_with_set_file"] += 1
        if useful_assert_present(example["output"]["asserts"]):
            stats["examples_with_useful_asserts"] += 1

    stats["exported_examples"] = len(exported_records)

    write_jsonl(exported_records, final_output)
    write_jsonl(rejected_records, rejected_output)

    report_payload = {
        "total_lines_read": stats["total_lines_read"],
        "exported_examples": stats["exported_examples"],
        "rejected_examples": stats["rejected_examples"],
        "duplicates_removed": stats["duplicates_removed"],
        "count_by_source": dict(sorted(stats["count_by_source"].items())),
        "count_by_scenario_type": dict(sorted(stats["count_by_scenario_type"].items())),
        "count_by_quality": dict(sorted(stats["count_by_quality"].items())),
        "rejection_reasons": dict(sorted(stats["rejection_reasons"].items())),
        "placeholder_corrections": dict(sorted(stats["placeholder_corrections"].items())),
        "scenario_type_corrections": dict(sorted(stats["scenario_type_corrections"].items())),
        "provided_inputs_added": dict(sorted(stats["provided_inputs_added"].items())),
        "required_inputs_added_from_placeholders": dict(sorted(stats["required_inputs_added_from_placeholders"].items())),
        "required_input_names_aligned": dict(sorted(stats["required_input_names_aligned"].items())),
        "placeholder_contract_fixed": dict(sorted(stats["placeholder_contract_fixed"].items())),
        "examples_with_fill": stats["examples_with_fill"],
        "examples_with_click": stats["examples_with_click"],
        "examples_with_select_option": stats["examples_with_select_option"],
        "examples_with_hover": stats["examples_with_hover"],
        "examples_with_set_file": stats["examples_with_set_file"],
        "examples_with_useful_asserts": stats["examples_with_useful_asserts"],
    }
    write_json(report_output, report_payload)

    print(f"total_lines_read: {stats['total_lines_read']}")
    print(f"exported_examples: {stats['exported_examples']}")
    print(f"rejected_examples: {stats['rejected_examples']}")
    print(f"duplicates_removed: {stats['duplicates_removed']}")
    print(f"final_output: {final_output}")
    print(f"rejected_output: {rejected_output}")
    print(f"report_output: {report_output}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
