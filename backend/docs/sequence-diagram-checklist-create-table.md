# Sequence Diagram Table: Create Checklist with Already Filled Form

This table is derived from [sequence-diagram-checklist-create.md](/C:/Users/Yahia%20Ghoufa/Desktop/checklist-manager/backend/docs/sequence-diagram-checklist-create.md) and [sequence-diagram-checklist-create.puml](/C:/Users/Yahia%20Ghoufa/Desktop/checklist-manager/backend/docs/sequence-diagram-checklist-create.puml).

## Participants

| ID | Component |
|---|---|
| P1 | Utilisateur |
| P2 | Frontend `ChecklistsView.vue` |
| P3 | Frontend `api.js / apiRequest()` |
| P4 | Backend `auth:sanctum` |
| P5 | Backend `role:testeur` |
| P6 | Backend `Api\ChecklistController` |
| P7 | Backend `Project` |
| P8 | Backend `Checklist` |
| P9 | Backend `ChecklistItem` |
| P10 | Database `checklists / checklist_items / projects / users` |

## Arrows Table

| No | Source | Arrow | Target | Message / Method |
|---|---|---|---|---|
| 1 | Utilisateur | `->` | `ChecklistsView.vue` | click "Creer une checklist" |
| 2 | `ChecklistsView.vue` | `->` | `ChecklistsView.vue` | `openCreateChecklistForm()` |
| 3 | `ChecklistsView.vue` | `->` | `ChecklistsView.vue` | `resetForm()` |
| 4 | `ChecklistsView.vue` | `->` | `ChecklistsView.vue` | `showChecklistForm = true` |
| 5 | `ChecklistsView.vue` | `->` | Utilisateur | form is not displayed |
| 6 | Utilisateur | `->` | `ChecklistsView.vue` | submit form |
| 7 | `ChecklistsView.vue` | `->` | `ChecklistsView.vue` | `submitChecklist()` |
| 8 | `ChecklistsView.vue` | `->` | `ChecklistsView.vue` | `submitting.value = true` |
| 9 | `ChecklistsView.vue` | `->` | `ChecklistsView.vue` | build payload from already-filled form |
| 10 | `ChecklistsView.vue` | `->` | `ChecklistsView.vue` | `payload.project_id = Number(form.project_id || projectId)` |
| 11 | `ChecklistsView.vue` | `->` | `ChecklistsView.vue` | `payload.template_scope = 'project'` |
| 12 | `ChecklistsView.vue` | `->` | `ChecklistsView.vue` | `payload.lifecycle_status = 'draft'` |
| 13 | `ChecklistsView.vue` | `->` | `ChecklistsView.vue` | `payload.project_id = null` |
| 14 | `ChecklistsView.vue` | `->` | `ChecklistsView.vue` | `payload.template_scope = 'global'` |
| 15 | `ChecklistsView.vue` | `->` | `ChecklistsView.vue` | `payload.lifecycle_status = 'approved'` |
| 16 | `ChecklistsView.vue` | `->` | `ChecklistsView.vue` | loop over `form.items.map(...)` |
| 17 | `ChecklistsView.vue` | `->` | `ChecklistsView.vue` | map item to `{ ...(id ? { id } : {}), title, description, priority, criticality }` |
| 18 | `ChecklistsView.vue` | `->` | `apiRequest()` | `apiRequest('/checklists', { method: 'POST', body: payload }, auth.token)` |
| 19 | `apiRequest()` | `->` | `auth:sanctum` | `POST /api/checklists` |
| 20 | `auth:sanctum` | `->` | `role:testeur` | authenticated request |
| 21 | `role:testeur` | `->` | `Api\ChecklistController` | `ChecklistController::store(Request $request)` |
| 22 | `Api\ChecklistController` | `->` | `Api\ChecklistController` | `$request->validate([...])` |
| 23 | `Api\ChecklistController` | `->` | `apiRequest()` | `422 validation errors` |
| 24 | `apiRequest()` | `->` | `ChecklistsView.vue` | throw `Error(localized message)` |
| 25 | `ChecklistsView.vue` | `->` | `ChecklistsView.vue` | `errorMessage = localizeError(...)` |
| 26 | `ChecklistsView.vue` | `->` | `ChecklistsView.vue` | `submitting.value = false` |
| 27 | `Api\ChecklistController` | `->` | `Project` | `Project::findOrFail($data['project_id'])` |
| 28 | `Api\ChecklistController` | `->` | `Api\ChecklistController` | `ensureTesterOwnsProject(Project $project)` |
| 29 | `Api\ChecklistController` | `->` | `Api\ChecklistController` | skip `ensureTesterOwnsProject()` |
| 30 | `Api\ChecklistController` | `->` | `Api\ChecklistController` | `DB::beginTransaction()` |
| 31 | `Api\ChecklistController` | `->` | `Checklist` | `Checklist::create([...])` |
| 32 | `Api\ChecklistController` | `->` | Database | insert into `checklists` |
| 33 | `Api\ChecklistController` | `->` | `Api\ChecklistController` | loop `foreach ($data['items'] as $index => $item)` |
| 34 | `Api\ChecklistController` | `->` | `ChecklistItem` | `ChecklistItem::create([...])` |
| 35 | `Api\ChecklistController` | `->` | Database | insert into `checklist_items` |
| 36 | `Api\ChecklistController` | `->` | `Api\ChecklistController` | `DB::commit()` |
| 37 | `Api\ChecklistController` | `->` | `Checklist` | `$checklist->load('items')` |
| 38 | `Api\ChecklistController` | `->` | `apiRequest()` | `response()->json($checklist->load('items'), 201)` |
| 39 | `Api\ChecklistController` | `->` | `Api\ChecklistController` | `DB::rollBack()` |
| 40 | `Api\ChecklistController` | `->` | `apiRequest()` | `response()->json(['message' => 'Error creating checklist'], 500)` |
| 41 | `apiRequest()` | `->` | `ChecklistsView.vue` | created checklist payload |
| 42 | `ChecklistsView.vue` | `->` | `ChecklistsView.vue` | `successMessage = localizeMessage('Checklist creee avec succes.', settings.language)` |
| 43 | `ChecklistsView.vue` | `->` | `ChecklistsView.vue` | `resetForm()` |
| 44 | `ChecklistsView.vue` | `->` | `ChecklistsView.vue` | `showChecklistForm = false` |
| 45 | `ChecklistsView.vue` | `->` | `ChecklistsView.vue` | `loadChecklists()` |
| 46 | `ChecklistsView.vue` | `->` | `apiRequest()` | `apiRequest(withQuery('/checklists', { page, ...(projectId ? { project_id: projectId } : {}) }), {}, auth.token)` |
| 47 | `apiRequest()` | `->` | `auth:sanctum` | `GET /api/checklists` |
| 48 | `auth:sanctum` | `->` | `role:testeur` | authenticated request |
| 49 | `role:testeur` | `->` | `Api\ChecklistController` | `ChecklistController::index(Request $request)` |
| 50 | `Api\ChecklistController` | `->` | Database | `paginate($perPage)` |
| 51 | Database | `->` | `Api\ChecklistController` | paginated checklists JSON |
| 52 | `Api\ChecklistController` | `->` | `apiRequest()` | `response()->json($query->paginate($perPage))` |
| 53 | `apiRequest()` | `->` | `ChecklistsView.vue` | `{ data, current_page, last_page }` |
| 54 | `ChecklistsView.vue` | `->` | `ChecklistsView.vue` | assign `checklists.value` and pagination |

## Notes on Alternative Paths

| Branch | Meaning |
|---|---|
| `1-4` vs `5` | checklist form visible vs hidden |
| `10-12` vs `13-15` | project checklist vs global checklist |
| `23-26` | validation failure path |
| `27-28` vs `29` | project authorization check vs no project |
| `36-38` vs `39-40` | success commit path vs rollback error path |

## Notes on Loops and Self Calls

| Type | Numbers | Details |
|---|---|---|
| Self-call | `2-4, 7-17, 22, 28-30, 33, 36, 39, 42-45, 54` | local processing inside the same component/controller |
| Loop | `16-17` | frontend loop over `form.items.map(...)` |
| Loop | `33-35` | backend loop over `foreach ($data['items'] as $index => $item)` |

## Main Methods to Cite

| Layer | Methods / Calls |
|---|---|
| Frontend | `openCreateChecklistForm()`, `submitChecklist()`, `loadChecklists()` |
| API client | `apiRequest('/checklists', { method: 'POST', body: payload }, auth.token)` |
| Backend controller | `ChecklistController::store(Request $request)`, `ChecklistController::index(Request $request)` |
| Backend model | `Project::findOrFail($data['project_id'])`, `Checklist::create([...])`, `ChecklistItem::create([...])`, `$checklist->load('items')` |
| Persistence | `DB::beginTransaction()`, `DB::commit()`, `DB::rollBack()`, `paginate($perPage)` |
