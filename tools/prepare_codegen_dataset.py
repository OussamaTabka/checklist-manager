#!/usr/bin/env python3
"""Offline converter for Playwright codegen scripts into cleaned JSONL examples."""

from __future__ import annotations

import argparse
import json
import re
from collections import Counter
from pathlib import Path
from typing import Any


BASE_URL = "{{base_url}}"
DEFAULT_INPUT = Path("backend/storage/app/agent-training/codegen_examples/codegen_raw.spec.ts")
DEFAULT_OUTPUT = Path(
    "backend/storage/app/agent-training/codegen_examples/codegen_cleaned_examples.jsonl"
)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Convert Playwright codegen into cleaned JSONL scenarios.")
    parser.add_argument("--input", dest="input_path", type=Path, default=None)
    parser.add_argument("--output", dest="output_path", type=Path, default=None)
    return parser.parse_args()


def repo_root() -> Path:
    return Path(__file__).resolve().parent.parent


def resolve_default_path(relative_path: Path) -> Path:
    return repo_root() / relative_path


def load_text(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def anonymize_text(value: str) -> str:
    value = re.sub(r"\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b", "{{email}}", value, flags=re.I)
    value = re.sub(r"\bpassword123\b", "{{password}}", value, flags=re.I)
    value = re.sub(r"\b(?:yahiaghoufa910|tester\d+|tester|testeur|admin|chef)(?:\s+[A-Za-zÀ-ÿ]+)*\b", "user", value, flags=re.I)
    value = re.sub(r"\b[\w\-. ]+\.(?:png|jpg|jpeg|pdf|csv|zip)\b", "{{file_path}}", value, flags=re.I)
    value = re.sub(r"http://localhost:5173(?:/[^\s'\"}]*)?", BASE_URL, value)
    return value


def write_jsonl(records: list[dict[str, Any]], output_path: Path) -> None:
    output_path.parent.mkdir(parents=True, exist_ok=True)
    with output_path.open("w", encoding="utf-8", newline="\n") as handle:
        for record in records:
            handle.write(json.dumps(record, ensure_ascii=False) + "\n")


def build_record(
    title: str,
    description: str,
    scenario_type: str,
    provided_inputs: dict[str, str],
    required_inputs: list[dict[str, str]],
    preflight_checks: list[dict[str, str]],
    steps: list[dict[str, Any]],
    asserts: list[dict[str, Any]],
    priority: str,
    criticality: str,
    quality: str,
) -> dict[str, Any]:
    return {
        "input": {
            "title": title,
            "description": description,
            "base_url": BASE_URL,
            "provided_inputs": provided_inputs,
            "priority": priority,
            "criticality": criticality,
        },
        "output": {
            "scenario_type": scenario_type,
            "required_inputs": required_inputs,
            "preflight_checks": preflight_checks,
            "steps": steps,
            "asserts": asserts,
        },
        "metadata": {
            "source": "playwright_codegen",
            "quality": quality,
            "scenario_type": scenario_type,
        },
    }


def workflow_present(script: str, *markers: str) -> bool:
    return all(marker in script for marker in markers)


def build_workflows(script: str) -> tuple[list[dict[str, Any]], list[dict[str, str]]]:
    exported: list[dict[str, Any]] = []
    rejected: list[dict[str, str]] = []

    definitions: list[dict[str, Any]] = [
        {
            "scenario_type": "authentication",
            "markers": [
                "login-input-email').fill('tester@test.com')",
                "login-input-password').fill('password123')",
                "login-btn-submit').click()",
            ],
            "record": build_record(
                title="Connexion avec des identifiants valides",
                description="Un utilisateur renseigne son email et son mot de passe puis soumet le formulaire de connexion.",
                scenario_type="authentication",
                provided_inputs={"email": "{{email}}", "password": "{{password}}"},
                required_inputs=[
                    {"name": "email", "placeholder": "{{email}}"},
                    {"name": "password", "placeholder": "{{password}}"},
                ],
                preflight_checks=[
                    {"check": "page_reachable", "target": "login_page"},
                    {"check": "locator_visible", "target": "login-input-email"},
                    {"check": "locator_visible", "target": "login-input-password"},
                ],
                steps=[
                    {"action": "goto", "url": f"{BASE_URL}/login"},
                    {"action": "fill", "target": "getByTestId('login-input-email')", "value": "{{email}}"},
                    {"action": "fill", "target": "getByTestId('login-input-password')", "value": "{{password}}"},
                    {"action": "click", "target": "getByTestId('login-btn-submit')"},
                ],
                asserts=[
                    {"type": "url_not_contains", "value": "login"},
                    {"type": "page_section_visible", "value": "workspace_or_dashboard"},
                ],
                priority="High",
                criticality="Critical",
                quality="high",
            ),
        },
        {
            "scenario_type": "password_reset",
            "markers": [
                "Mot de passe oublie ?",
                "Send reset link",
                "Retour a la connexion",
            ],
            "record": build_record(
                title="Demande de réinitialisation du mot de passe",
                description="L utilisateur ouvre le parcours mot de passe oublié, saisit son email et demande un lien de réinitialisation.",
                scenario_type="password_reset",
                provided_inputs={"email": "{{email}}"},
                required_inputs=[{"name": "email", "placeholder": "{{email}}"}],
                preflight_checks=[
                    {"check": "page_reachable", "target": "login_page"},
                    {"check": "locator_visible", "target": "forgot_password_link"},
                ],
                steps=[
                    {"action": "goto", "url": f"{BASE_URL}/login"},
                    {"action": "click", "target": "getByRole('link', { name: 'Mot de passe oublie ?' })"},
                    {"action": "fill", "target": "getByRole('textbox')", "value": "{{email}}"},
                    {"action": "click", "target": "getByRole('button', { name: 'Send reset link' })"},
                ],
                asserts=[
                    {"type": "text_or_state_visible", "value": "reset_link_feedback"},
                    {"type": "locator_visible", "value": "return_to_login_link"},
                ],
                priority="Medium",
                criticality="Major",
                quality="medium",
            ),
        },
        {
            "scenario_type": "project_filtering",
            "markers": [
                "Rechercher un projet...",
                "Afficher les filtres",
                "Filtrer par nom de projet",
            ],
            "record": build_record(
                title="Filtrage de la liste des projets",
                description="Un administrateur ouvre la page projets puis applique un filtre par nom pour réduire la liste affichée.",
                scenario_type="project_filtering",
                provided_inputs={"project_name_filter": "{{project_name_filter}}"},
                required_inputs=[{"name": "project_name_filter", "placeholder": "{{project_name_filter}}"}],
                preflight_checks=[
                    {"check": "page_reachable", "target": "projects_page"},
                    {"check": "locator_visible", "target": "project_search_input"},
                ],
                steps=[
                    {"action": "click", "target": "getByRole('link', { name: 'Projets' })"},
                    {"action": "click", "target": "getByRole('button', { name: 'Afficher les filtres' })"},
                    {"action": "fill", "target": "getByRole('textbox', { name: 'Filtrer par nom de projet' })", "value": "{{project_name_filter}}"},
                    {"action": "click", "target": "getByRole('button', { name: 'Chef de projet' })"},
                ],
                asserts=[
                    {"type": "filtered_results_visible", "value": "project_rows"},
                    {"type": "filter_state_visible", "value": "{{project_name_filter}}"},
                ],
                priority="Medium",
                criticality="Major",
                quality="high",
            ),
        },
        {
            "scenario_type": "user_role_update",
            "markers": [
                "users-select-role').selectOption('chef')",
                "users-btn-submit').click()",
            ],
            "record": build_record(
                title="Modification du rôle d un utilisateur",
                description="Un administrateur ouvre les actions d un utilisateur, change son rôle puis enregistre la mise à jour.",
                scenario_type="user_role_update",
                provided_inputs={"new_role": "chef"},
                required_inputs=[{"name": "new_role", "placeholder": "{{role}}"}],
                preflight_checks=[
                    {"check": "page_reachable", "target": "users_roles_page"},
                    {"check": "locator_visible", "target": "users-select-role"},
                ],
                steps=[
                    {"action": "click", "target": "user_actions_menu"},
                    {"action": "click", "target": "getByRole('button', { name: 'Modifier' })"},
                    {"action": "select_option", "target": "getByTestId('users-select-role')", "value": "{{role}}"},
                    {"action": "click", "target": "getByTestId('users-btn-submit')"},
                ],
                asserts=[
                    {"type": "text_visible", "value": "role_update_success"},
                    {"type": "selected_value_visible", "value": "{{role}}"},
                ],
                priority="High",
                criticality="Major",
                quality="high",
            ),
        },
        {
            "scenario_type": "user_archive_restore",
            "markers": [
                "name: '6 Tester 3 tester3@test.com'",
                "name: 'Restaurer' }).click()",
                "users-btn-submit').click()",
            ],
            "record": build_record(
                title="Archivage puis restauration d un utilisateur",
                description="Un administrateur archive un utilisateur, ouvre les actions archivées puis restaure ce compte.",
                scenario_type="user_archive_restore",
                provided_inputs={},
                required_inputs=[],
                preflight_checks=[
                    {"check": "page_reachable", "target": "users_roles_page"},
                    {"check": "locator_visible", "target": "archived_actions_button"},
                ],
                steps=[
                    {"action": "click", "target": "user_actions_menu"},
                    {"action": "click", "target": "getByRole('button', { name: 'Archiver' })"},
                    {"action": "click", "target": "getByRole('button', { name: 'Ouvrir les actions archivees' })"},
                    {"action": "click", "target": "getByRole('button', { name: 'Restaurer' })"},
                    {"action": "click", "target": "getByTestId('users-btn-submit')"},
                ],
                asserts=[
                    {"type": "text_visible", "value": "restore_success"},
                    {"type": "user_visible_in_active_list", "value": "restored_user"},
                ],
                priority="High",
                criticality="Major",
                quality="medium",
            ),
        },
        {
            "scenario_type": "project_creation_validation",
            "markers": [
                "projects-btn-open-create",
                "projects-input-name').fill('')",
                "projects-input-app-url').fill('')",
            ],
            "record": build_record(
                title="Validation de création de projet avec champs invalides",
                description="Un chef de projet ouvre la création de projet, renseigne partiellement le formulaire puis vide les champs obligatoires pour déclencher la validation.",
                scenario_type="project_creation_validation",
                provided_inputs={"project_name": "{{project_name}}", "app_url": "{{project_app_url}}"},
                required_inputs=[
                    {"name": "project_name", "placeholder": "{{project_name}}"},
                    {"name": "app_url", "placeholder": "{{project_app_url}}"},
                ],
                preflight_checks=[
                    {"check": "page_reachable", "target": "projects_page"},
                    {"check": "locator_visible", "target": "projects-btn-open-create"},
                ],
                steps=[
                    {"action": "click", "target": "getByTestId('projects-btn-open-create')"},
                    {"action": "fill", "target": "getByTestId('projects-input-name')", "value": "{{project_name}}"},
                    {"action": "fill", "target": "getByTestId('projects-input-app-url')", "value": "{{project_app_url}}"},
                    {"action": "fill", "target": "getByTestId('projects-input-name')", "value": ""},
                    {"action": "fill", "target": "getByTestId('projects-input-app-url')", "value": ""},
                ],
                asserts=[
                    {"type": "validation_message_visible", "value": "required_project_fields"},
                    {"type": "submit_blocked_or_invalid_state", "value": "project_creation_form"},
                ],
                priority="High",
                criticality="Critical",
                quality="high",
            ),
        },
        {
            "scenario_type": "checklist_comment_creation",
            "markers": [
                "Ajouter un commentaire",
                "Ajouter une observation, un",
                "Enregistrer' }).click()",
            ],
            "record": build_record(
                title="Ajout d un commentaire sur un item de checklist",
                description="Le testeur ouvre l espace d exécution d une checklist, ajoute un commentaire puis l enregistre.",
                scenario_type="checklist_comment_creation",
                provided_inputs={"comment": "{{comment}}"},
                required_inputs=[{"name": "comment", "placeholder": "{{comment}}"}],
                preflight_checks=[
                    {"check": "page_reachable", "target": "checklist_execution_space"},
                    {"check": "locator_visible", "target": "add_comment_button"},
                ],
                steps=[
                    {"action": "goto", "url": f"{BASE_URL}/checklists/{{checklist_id}}?projectId={{project_id}}"},
                    {"action": "click", "target": "getByRole('button', { name: 'Ajouter un commentaire' }).first()"},
                    {"action": "fill", "target": "getByRole('textbox', { name: 'Ajouter une observation, un' })", "value": "{{comment}}"},
                    {"action": "click", "target": "getByRole('button', { name: 'Enregistrer' })"},
                ],
                asserts=[
                    {"type": "text_visible", "value": "{{comment}}"},
                    {"type": "history_entry_visible", "value": "comment_saved"},
                ],
                priority="Medium",
                criticality="Major",
                quality="high",
            ),
        },
        {
            "scenario_type": "checklist_item_status_update",
            "markers": [
                "selectOption('Failed')",
                "selectOption('Blocked')",
            ],
            "record": build_record(
                title="Mise à jour du statut d un item de checklist",
                description="Le testeur change le statut d un item de checklist depuis le sélecteur d état et vérifie que l historique se met à jour.",
                scenario_type="checklist_item_status_update",
                provided_inputs={"new_status": "{{status}}"},
                required_inputs=[{"name": "new_status", "placeholder": "{{status}}"}],
                preflight_checks=[
                    {"check": "page_reachable", "target": "checklist_execution_space"},
                    {"check": "locator_visible", "target": "status_select"},
                ],
                steps=[
                    {"action": "click", "target": "getByRole('button', { name: 'Afficher tout l’historique' }).first()"},
                    {"action": "select_option", "target": "item_status_select", "value": "Failed"},
                    {"action": "select_option", "target": "item_status_select", "value": "{{status}}"},
                ],
                asserts=[
                    {"type": "text_visible", "value": "Statut modifie"},
                    {"type": "selected_value_visible", "value": "{{status}}"},
                ],
                priority="Medium",
                criticality="Major",
                quality="high",
            ),
        },
        {
            "scenario_type": "automatic_test_launch",
            "markers": [
                "Lancer le test automatique",
                "Lancer maintenant",
            ],
            "record": build_record(
                title="Lancement d un test automatique",
                description="Le testeur déclenche le test automatique depuis un item de checklist puis confirme le lancement immédiat.",
                scenario_type="automatic_test_launch",
                provided_inputs={},
                required_inputs=[],
                preflight_checks=[
                    {"check": "page_reachable", "target": "checklist_execution_space"},
                    {"check": "locator_visible", "target": "launch_automatic_test_button"},
                ],
                steps=[
                    {"action": "click", "target": "getByRole('button', { name: 'Lancer le test automatique' }).first()"},
                    {"action": "click", "target": "getByRole('button', { name: 'Lancer maintenant' })"},
                ],
                asserts=[
                    {"type": "execution_panel_visible", "value": "automatic_test_started"},
                    {"type": "text_visible", "value": "Notes ou contraintes"},
                ],
                priority="High",
                criticality="Critical",
                quality="high",
            ),
        },
        {
            "scenario_type": "notifications_view",
            "markers": [
                "name: 'Notifications'",
                "Voir toutes les notifications",
                "Actualiser",
            ],
            "record": build_record(
                title="Consultation de la vue notifications",
                description="Le testeur ouvre le centre de notifications, affiche toutes les notifications puis rafraîchit la vue.",
                scenario_type="notifications_view",
                provided_inputs={},
                required_inputs=[],
                preflight_checks=[
                    {"check": "page_reachable", "target": "authenticated_workspace"},
                    {"check": "locator_visible", "target": "notifications_button"},
                ],
                steps=[
                    {"action": "click", "target": "getByRole('button', { name: 'Notifications' })"},
                    {"action": "click", "target": "getByRole('button', { name: 'Voir toutes les notifications' })"},
                    {"action": "click", "target": "getByRole('button', { name: 'Actualiser' })"},
                ],
                asserts=[
                    {"type": "notifications_list_visible", "value": "notifications_feed"},
                    {"type": "refresh_state_visible", "value": "notifications_updated"},
                ],
                priority="Low",
                criticality="Minor",
                quality="medium",
            ),
        },
        {
            "scenario_type": "checklist_archive",
            "markers": [
                "name: 'Checklists'",
                "name: 'Archiver' }).click()",
                "name: 'Supprimer definitivement' }).click()",
            ],
            "reject_reason": "dangerous_permanent_delete",
        },
    ]

    for definition in definitions:
        if not workflow_present(script, *definition["markers"]):
            continue

        reject_reason = definition.get("reject_reason")
        if reject_reason:
            rejected.append(
                {
                    "scenario_type": definition["scenario_type"],
                    "reason": reject_reason,
                }
            )
            continue

        exported.append(definition["record"])

    if workflow_present(script, "Supprimer definitivement", "Confirmer la suppression"):
        rejected.append({"scenario_type": "user_permanent_delete", "reason": "dangerous_permanent_delete"})

    return exported, rejected


def print_summary(detected: int, exported: list[dict[str, Any]], rejected: list[dict[str, str]]) -> None:
    rejection_counts = Counter(entry["reason"] for entry in rejected)
    scenario_counts = Counter(entry["metadata"]["scenario_type"] for entry in exported)

    print(f"Workflows detected: {detected}")
    print(f"Workflows exported: {len(exported)}")
    print(f"Workflows rejected: {len(rejected)}")
    print("Rejection reasons:")
    if rejection_counts:
        for reason, count in sorted(rejection_counts.items()):
            print(f"- {reason}: {count}")
    else:
        print("- none")
    print("Count by scenario_type:")
    for scenario_type, count in sorted(scenario_counts.items()):
        print(f"- {scenario_type}: {count}")


def main() -> int:
    args = parse_args()
    input_path = args.input_path or resolve_default_path(DEFAULT_INPUT)
    output_path = args.output_path or resolve_default_path(DEFAULT_OUTPUT)

    if not input_path.exists():
        print(f"Input file not found: {input_path}")
        return 1

    script = load_text(input_path)
    exported, rejected = build_workflows(script)
    detected = len(exported) + len(rejected)

    write_jsonl(exported, output_path)
    print_summary(detected, exported, rejected)
    print(f"Output file: {output_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
