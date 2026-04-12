# Playwright Orchestrator (Model B)

Orchestrateur Node/TypeScript pour exécuter un run Playwright dans **1 container Docker par run**, puis publier les résultats vers Laravel.

## Ce que fait le pipeline

1. Construit un `run.json` DSL v1 (smoke pages statiques + login UI dédié optionnel).
2. Optionnel: crée un run Laravel (`POST /api/test-runs`) pour récupérer `run_id`.
3. Écrit `runs/<run_id>/run.json` et crée `runs/<run_id>/artifacts/`.
4. Lance le container runner (`docker run --rm --mount ...`).
5. Lit `runs/<run_id>/result.json`.
6. Publie vers Laravel:
- option propre: `POST /api/test-runs/<run_id>/results` (batch)
- fallback: `PATCH /api/version-items/<id>/status` en boucle

## Prérequis

- Docker installé
- Image runner disponible: `checklist-playwright-job`
- API Laravel accessible et token Sanctum valide (si intégration activée)

## Installation

```bash
cd playwright-orchestrator
npm install
npm run build
```

## Configuration

Copier `.env.example` en `.env` puis ajuster les valeurs.

Variables clés:

- `BASE_URL`
- `TARGET_APP` (mettre `checklist-manager` pour activer le mode testid-only)
- `SMOKE_PAGES` (ex: `dashboard,checklists,projects,users`)
- `LOGIN_EMAIL_TESTID`
- `LOGIN_PASSWORD_TESTID`
- `LOGIN_SUBMIT_TESTID`
- `AUTH_MODE=api|ui`
- `AUTH_UI_TEST_ENABLED=true|false`
- `SANCTUM_CSRF_COOKIE_PATH`
- `LOGIN_PATH`
- `AUTH_VERIFY_PATH`
- `E2E_EMAIL`
- `E2E_PASSWORD`
- `LARAVEL_BASE_URL`
- `LARAVEL_TOKEN`
- `PROJECT_VERSION_ID`

Compromis recommandé:

- Smoke principal: `AUTH_MODE=api` (storage state via API auth).
- Test UI login dédié: `AUTH_UI_TEST_ENABLED=true`.
- Pages smoke statiques (sans IDs dynamiques): `dashboard,checklists,projects` (+ `users` optionnel).

Si `AUTH_MODE=api` et que `SMOKE_PAGES` contient des pages protégées, l'orchestrateur force `ENABLE_LOGIN_FLOW=true`.

## Contrat auth (DSL v1)

Pour `run:from-dsl`, le contrat est le suivant:

- `auth` est défini au niveau run (`runSpec.auth`).
- Les cases sont authentifiées par défaut si `use_auth` est omis.
- Pour forcer un case non authentifié: `use_auth=false`.
- Si `runSpec.auth` est absent mais qu'au moins un case requiert l'auth, `run:from-dsl` tente une injection depuis la config.
- L'injection réussie est loggée sur stderr avec:
	- `[from-dsl] injected auth from config because run spec missing auth block`
- Si l'auth est requise mais indisponible (injection impossible), la commande échoue avec une erreur explicite.

Conséquence: pas d'exécution silencieuse non authentifiée pour des cases protégés.

### Examples (auth contract)

#### 1) Protected case (default: auth enabled)
If `use_auth` is omitted, the case is treated as auth-required.

```json
{
	"cases": [
		{
			"external_id": 101,
			"title": "Dashboard visible",
			"steps": [],
			"asserts": []
		}
	]
}
```

#### 2) Public case (explicit opt-out)
Set `use_auth=false` to run the case without authenticated context.

```json
{
	"cases": [
		{
			"external_id": 102,
			"title": "Public landing page",
			"use_auth": false,
			"steps": [],
			"asserts": []
		}
	]
}
```

#### 3) Auth omitted at run-level (injection behavior)
When using `run:from-dsl` only:

- if `runSpec.auth` is missing and at least one case is auth-required, the CLI attempts auth injection from config;
- on success, it logs to stderr: `[from-dsl] injected auth from config because run spec missing auth block`;
- if injection is not possible, the CLI fails with a clear error.

Other execution paths should not assume automatic injection and may require explicit `runSpec.auth`.

## Exécution

PowerShell

```powershell
Set-Location "c:\Users\Yahia Ghoufa\Desktop\checklist-manager\playwright-orchestrator"
npm run run:smoke
```

Bash

```bash
cd ./playwright-orchestrator
npm run run:smoke
```

## Commandes Docker réseau localhost

Pour Linux, si `BASE_URL` utilise `host.docker.internal`, l'orchestrateur ajoute automatiquement:

```text
--add-host=host.docker.internal:host-gateway
```

Pour Windows/Mac, utiliser directement `http://host.docker.internal:<port>`.

## Sorties

- `runs/<run_id>/run.json`
- `runs/<run_id>/result.json`
- `runs/<run_id>/artifacts/<external_id>/*`

## Codes de sortie

- `0`: succès
- `2`: invalid_spec (runner)
- `3`: runner_error ou erreur orchestrateur

## Notes d'industrialisation

- Ajouter une stratégie de retry uniquement sur erreurs infra.
- Ajouter upload artifacts vers S3/MinIO et ne stocker en DB que les URLs.
- Conserver `run.json` + `result.json` pour audit.
