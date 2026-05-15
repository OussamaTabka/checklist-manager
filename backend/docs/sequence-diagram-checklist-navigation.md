# Sequence Diagram: Add a Checklist Not Linked to a User Story

This document describes the real sequence currently implemented in the codebase for creating a checklist from the navigation flow, when the checklist is not directly linked to a user story.

It is written as a structured UML-ready scenario so it can be transformed into a visual sequence diagram afterward.

## Summary

Real implemented flow:

`Utilisateur` -> `Frontend/App.vue sidebar` -> `vue-router` -> `ChecklistsView.vue` -> `apiRequest()` -> `POST /api/checklists` -> `Api\ChecklistController::store()` -> `Checklist` + `ChecklistItem` -> database -> refresh via `GET /api/checklists`

This scenario documents:

- exact methods shown on each arrow
- frontend and backend self-calls
- `alt` fragments
- `loop` fragments
- explicit participants for views, controller, models, middleware, and database
- absence of `UserStoryController` and `user_story_checklists` in this specific flow

## Participants

Display the participants in this order:

1. `Utilisateur`
2. `Frontend <<view>> App.vue`
3. `Frontend <<router>> vue-router`
4. `Frontend <<view>> ChecklistsView.vue`
5. `Frontend <<service>> api.js / apiRequest()`
6. `Backend <<middleware>> auth:sanctum`
7. `Backend <<middleware>> role:testeur`
8. `Backend <<controller>> Api\ChecklistController`
9. `Backend <<model>> Project`
10. `Backend <<model>> Checklist`
11. `Backend <<model>> ChecklistItem`
12. `Database <<tables>> checklists / checklist_items / projects / users`

## Main Sequence

### A. Navigation and route resolution

1. `Utilisateur -> Frontend <<view>> App.vue : click "Checklists" in sidebar`
2. `App.vue -> vue-router : router.push({ name: 'checklists' })`
3. `vue-router -> vue-router : router.beforeEach(to)`

`alt [to.meta.requiresAuth && auth.isAuthenticated]`

4. `vue-router -> Frontend <<view>> ChecklistsView.vue : resolve route { name: 'checklists' }`

`else [not authenticated or role mismatch]`

5. `vue-router -> Frontend <<view>> App.vue : redirect to { name: 'login' } or { name: 'dashboard' }`

### B. Initial page loading

6. `vue-router -> Frontend <<view>> ChecklistsView.vue : mount component`
7. `ChecklistsView.vue -> ChecklistsView.vue : onMounted()`
8. `ChecklistsView.vue -> ChecklistsView.vue : resetForm()`
9. `ChecklistsView.vue -> ChecklistsView.vue : loadChecklists(page = 1)`
10. `ChecklistsView.vue -> Frontend <<service>> api.js / apiRequest() : apiRequest(withQuery('/checklists', { page, ...(projectId ? { project_id: projectId } : {}) }), {}, auth.token)`
11. `apiRequest() -> Backend <<middleware>> auth:sanctum : GET /api/checklists`
12. `auth:sanctum -> Backend <<middleware>> role:testeur : authenticated request`
13. `role:testeur -> Backend <<controller>> Api\ChecklistController : ChecklistController::index(Request $request)`
14. `ChecklistController -> Backend <<model>> Checklist : Checklist::with('items')->where('is_active', true)->orderByDesc('id')`

`alt [project_id present in query]`

15. `ChecklistController -> Backend <<model>> Checklist : where(project_id = projectId) / orWhere(template_scope = 'global') / orWhereNull(project_id) / related story filters`

`else [project_id absent]`

16. `ChecklistController -> Backend <<model>> Project : Project::whereHas('testers', fn (...) => where('users.id', Auth::id()))->pluck('id')`
17. `ChecklistController -> Backend <<model>> Checklist : filter accessible checklists for tester`

18. `ChecklistController -> Database : paginate($perPage)`
19. `Database --> ChecklistController : paginated checklists JSON`
20. `ChecklistController --> apiRequest() : response()->json($query->paginate($perPage))`
21. `apiRequest() --> ChecklistsView.vue : { data, current_page, last_page }`
22. `ChecklistsView.vue -> ChecklistsView.vue : assign checklists.value and pagination`

23. `ChecklistsView.vue -> ChecklistsView.vue : loadAvailableItems()`
24. `ChecklistsView.vue -> Frontend <<service>> api.js / apiRequest() : apiRequest('/checklists/items/available', {}, auth.token)`
25. `apiRequest() -> Backend <<middleware>> auth:sanctum : GET /api/checklists/items/available`
26. `auth:sanctum -> Backend <<middleware>> role:testeur : authenticated request`
27. `role:testeur -> Backend <<controller>> Api\ChecklistController : ChecklistController::getAvailableItems()`
28. `ChecklistController -> Backend <<model>> ChecklistItem : ChecklistItem::selectRaw('MIN(id) as id, title, description, priority, criticality')`
29. `ChecklistController -> Database : groupBy(title, description, priority, criticality) + orderBy('title') + get()`
30. `Database --> ChecklistController : reusable items JSON`
31. `ChecklistController --> apiRequest() : response()->json($items)`
32. `apiRequest() --> ChecklistsView.vue : availableItems`

### C. Open checklist creation form

`alt [auth.canManageChecklists == true]`

33. `Utilisateur -> Frontend <<view>> ChecklistsView.vue : click "Créer une checklist"`
34. `ChecklistsView.vue -> ChecklistsView.vue : openCreateChecklistForm()`
35. `ChecklistsView.vue -> ChecklistsView.vue : resetForm()`
36. `ChecklistsView.vue -> ChecklistsView.vue : showChecklistForm = true`

`else [auth.canManageChecklists == false]`

37. `ChecklistsView.vue -> Utilisateur : form is not displayed`

### D. Fill form and build payload

38. `Utilisateur -> Frontend <<view>> ChecklistsView.vue : fill name, description, category, is_active, items[]`
39. `Utilisateur -> Frontend <<view>> ChecklistsView.vue : submit form`
40. `ChecklistsView.vue -> ChecklistsView.vue : submitChecklist()`
41. `ChecklistsView.vue -> ChecklistsView.vue : submitting.value = true`
42. `ChecklistsView.vue -> ChecklistsView.vue : build payload`

`alt [projectId present in route.query]`

43. `ChecklistsView.vue -> ChecklistsView.vue : payload.project_id = Number(form.project_id || projectId)`
44. `ChecklistsView.vue -> ChecklistsView.vue : payload.template_scope = 'project'`
45. `ChecklistsView.vue -> ChecklistsView.vue : payload.lifecycle_status = 'draft'`

`else [projectId absent]`

46. `ChecklistsView.vue -> ChecklistsView.vue : payload.project_id = null`
47. `ChecklistsView.vue -> ChecklistsView.vue : payload.template_scope = 'global'`
48. `ChecklistsView.vue -> ChecklistsView.vue : payload.lifecycle_status = 'approved'`

49. `loop [for each form item in form.items.map(...)]`
50. `ChecklistsView.vue -> ChecklistsView.vue : map item -> { title, description, priority, criticality, ...(id ? { id } : {}) }`

Resulting payload fields:

- `project_id`
- `template_scope`
- `lifecycle_status`
- `name`
- `description`
- `category`
- `is_active`
- `items[] { title, description, priority, criticality }`

### E. Create checklist request

51. `ChecklistsView.vue -> Frontend <<service>> api.js / apiRequest() : apiRequest('/checklists', { method: 'POST', body: payload }, auth.token)`
52. `apiRequest() -> Backend <<middleware>> auth:sanctum : POST /api/checklists`
53. `auth:sanctum -> Backend <<middleware>> role:testeur : authenticated request`
54. `role:testeur -> Backend <<controller>> Api\ChecklistController : ChecklistController::store(Request $request)`
55. `ChecklistController -> ChecklistController : $request->validate([...])`

`alt [validation KO]`

56. `ChecklistController --> apiRequest() : 422 validation errors`
57. `apiRequest() --> ChecklistsView.vue : throw Error(localized message)`
58. `ChecklistsView.vue -> ChecklistsView.vue : errorMessage = localizeError(error, 'error_generic', settings.language)`
59. `ChecklistsView.vue -> ChecklistsView.vue : submitting.value = false`

`else [validation OK]`

60. `alt [project_id provided]`
61. `ChecklistController -> Backend <<model>> Project : Project::findOrFail($data['project_id'])`
62. `Project -> Database : select project by id`
63. `Database --> Project : project row`
64. `Project --> ChecklistController : Project instance`
65. `ChecklistController -> ChecklistController : ensureTesterOwnsProject(Project $project)`

`else [project_id null]`

66. `ChecklistController -> ChecklistController : skip ensureTesterOwnsProject()`

67. `ChecklistController -> ChecklistController : source_user_story_id flow not applicable in this scenario`
68. `ChecklistController -> ChecklistController : DB::beginTransaction()`
69. `ChecklistController -> Backend <<model>> Checklist : Checklist::create([...])`
70. `Checklist -> Database : insert into checklists`
71. `Database --> Checklist : created checklist row`
72. `Checklist --> ChecklistController : Checklist instance`

73. `loop [foreach ($data['items'] as $index => $item)]`
74. `ChecklistController -> Backend <<model>> ChecklistItem : ChecklistItem::create([...])`
75. `ChecklistItem -> Database : insert into checklist_items`
76. `Database --> ChecklistItem : created checklist_item row`
77. `ChecklistItem --> ChecklistController : ChecklistItem instance`

78. `ChecklistController -> ChecklistController : DB::commit()`
79. `ChecklistController -> Backend <<model>> Checklist : $checklist->load('items')`
80. `Checklist -> Database : select checklist + related items`
81. `Database --> Checklist : hydrated checklist with items`
82. `Checklist --> ChecklistController : checklist JSON-ready model`
83. `ChecklistController --> apiRequest() : response()->json($checklist->load('items'), 201)`
84. `apiRequest() --> ChecklistsView.vue : created checklist payload`

`alt [exception during transaction]`

85. `ChecklistController -> ChecklistController : DB::rollBack()`
86. `ChecklistController --> apiRequest() : response()->json(['message' => 'Error creating checklist'], 500)`
87. `apiRequest() --> ChecklistsView.vue : throw Error(localized message)`

### F. Post-create refresh and feedback

88. `ChecklistsView.vue -> ChecklistsView.vue : successMessage = localizeMessage('Checklist creee avec succes.', settings.language)`
89. `ChecklistsView.vue -> ChecklistsView.vue : resetForm()`
90. `ChecklistsView.vue -> ChecklistsView.vue : showChecklistForm = false`
91. `ChecklistsView.vue -> ChecklistsView.vue : loadChecklists()`

`ref loadChecklists`

92. Reuse steps `10` to `22` for the refresh sequence after creation.

93. `watch(successMessage, ...) -> toast.success(message)`
94. `ChecklistsView.vue -> ChecklistsView.vue : successMessage = ''`
95. `ChecklistsView.vue -> ChecklistsView.vue : submitting.value = false`
96. `Frontend <<view>> ChecklistsView.vue -> Utilisateur : updated list + success toast`

## Required UML Fragments

## `alt`

Include these alternatives explicitly:

- `[to.meta.requiresAuth && auth.isAuthenticated]` vs redirect
- `[auth.canManageChecklists == true]` vs form hidden
- `[projectId present in query]` vs `[projectId absent]`
- `[validation OK]` vs `[validation KO]`
- `[project_id provided]` vs `[project_id null]`
- `[exception during transaction]` vs `[success]`

## `loop`

Include these loops explicitly:

- frontend payload construction loop over `form.items.map(...)`
- backend persistence loop over `foreach ($data['items'] as $index => $item)`

## `self-call`

Show these self-calls explicitly:

- `ChecklistsView.vue -> ChecklistsView.vue : onMounted()`
- `ChecklistsView.vue -> ChecklistsView.vue : resetForm()`
- `ChecklistsView.vue -> ChecklistsView.vue : submitChecklist()`
- `ChecklistsView.vue -> ChecklistsView.vue : build payload`
- `ChecklistController -> ChecklistController : $request->validate([...])`
- `ChecklistController -> ChecklistController : ensureTesterOwnsProject(Project $project)`
- `ChecklistController -> ChecklistController : DB::beginTransaction()`
- `ChecklistController -> ChecklistController : DB::commit()`
- `ChecklistController -> ChecklistController : DB::rollBack()`

## `ref`

Use one optional `ref` fragment:

- `ref loadChecklists`

This avoids redrawing the entire `GET /checklists` refresh a second time after successful creation.

## Included Interfaces and Methods

Methods that should appear exactly on arrows:

- `router.push({ name: 'checklists' })`
- `router.beforeEach(to)`
- `onMounted()`
- `resetForm()`
- `loadChecklists(page = 1)`
- `loadAvailableItems()`
- `openCreateChecklistForm()`
- `submitChecklist()`
- `apiRequest(withQuery('/checklists', { page, ...(projectId ? { project_id: projectId } : {}) }), {}, auth.token)`
- `apiRequest('/checklists/items/available', {}, auth.token)`
- `apiRequest('/checklists', { method: 'POST', body: payload }, auth.token)`
- `ChecklistController::index(Request $request)`
- `ChecklistController::getAvailableItems()`
- `ChecklistController::store(Request $request)`
- `Project::findOrFail($data['project_id'])`
- `Checklist::create([...])`
- `ChecklistItem::create([...])`
- `$checklist->load('items')`

## Explicit Exclusions

This sequence must make these points clear:

- it starts from navigation to `ChecklistsView.vue`
- it concerns a checklist not linked to a user story when `projectId` is absent
- `UserStoryController` does not participate in this flow
- the pivot `user_story_checklists` does not participate in this flow
- no dedicated backend service layer exists in this scenario
- persistence happens through `checklists` and `checklist_items`
- security is enforced by `auth:sanctum` then `role:testeur`

## Source References

Main implementation sources used for this sequence:

- [frontend/src/App.vue](/C:/Users/Yahia%20Ghoufa/Desktop/checklist-manager/frontend/src/App.vue)
- [frontend/src/router/index.js](/C:/Users/Yahia%20Ghoufa/Desktop/checklist-manager/frontend/src/router/index.js)
- [frontend/src/views/ChecklistsView.vue](/C:/Users/Yahia%20Ghoufa/Desktop/checklist-manager/frontend/src/views/ChecklistsView.vue)
- [frontend/src/lib/api.js](/C:/Users/Yahia%20Ghoufa/Desktop/checklist-manager/frontend/src/lib/api.js)
- [backend/routes/api.php](/C:/Users/Yahia%20Ghoufa/Desktop/checklist-manager/backend/routes/api.php)
- [backend/app/Http/Controllers/Api/ChecklistController.php](/C:/Users/Yahia%20Ghoufa/Desktop/checklist-manager/backend/app/Http/Controllers/Api/ChecklistController.php)
- [backend/app/Models/Checklist.php](/C:/Users/Yahia%20Ghoufa/Desktop/checklist-manager/backend/app/Models/Checklist.php)
- [backend/app/Models/ChecklistItem.php](/C:/Users/Yahia%20Ghoufa/Desktop/checklist-manager/backend/app/Models/ChecklistItem.php)
- [backend/app/Models/Project.php](/C:/Users/Yahia%20Ghoufa/Desktop/checklist-manager/backend/app/Models/Project.php)
- [backend/database/migrations/2026_02_26_121725_create_checklists_table.php](/C:/Users/Yahia%20Ghoufa/Desktop/checklist-manager/backend/database/migrations/2026_02_26_121725_create_checklists_table.php)
- [backend/database/migrations/2026_02_26_121732_create_checklist_items_table.php](/C:/Users/Yahia%20Ghoufa/Desktop/checklist-manager/backend/database/migrations/2026_02_26_121732_create_checklist_items_table.php)
- [backend/database/migrations/2026_04_20_add_user_story_fields_to_checklists.php](/C:/Users/Yahia%20Ghoufa/Desktop/checklist-manager/backend/database/migrations/2026_04_20_add_user_story_fields_to_checklists.php)
- [backend/database/migrations/2026_04_26_140000_add_checklist_governance_fields.php](/C:/Users/Yahia%20Ghoufa/Desktop/checklist-manager/backend/database/migrations/2026_04_26_140000_add_checklist_governance_fields.php)
