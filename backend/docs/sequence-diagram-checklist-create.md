# Sequence Diagram: Create Checklist with Already Filled Form

This document describes the real sequence currently implemented in the codebase for creating a checklist through `POST /api/checklists`, when the checklist is not linked to a user story.

It is intentionally focused on the submit flow only. The sequence starts when the user clicks `Creer une checklist`, and it assumes the form data is already filled before submission.

## Summary

Real implemented flow:

`Utilisateur` -> `ChecklistsView.vue` -> `apiRequest()` -> `POST /api/checklists` -> `auth:sanctum` -> `role:testeur` -> `Api\ChecklistController::store()` -> `Checklist` + `ChecklistItem` -> database -> `201` -> refresh via `loadChecklists()`

This scenario documents:

- the exact create request path used by the frontend
- the assumption that the form is already filled before submit
- the controller validation and authorization branches
- the transaction used to persist checklist and items
- the post-create refresh performed by the frontend
- the fact that this checklist creation flow is not tied to a user story

## Participants

Display the participants in this order:

1. `Utilisateur`
2. `Frontend <<view>> ChecklistsView.vue`
3. `Frontend <<service>> api.js / apiRequest()`
4. `Backend <<middleware>> auth:sanctum`
5. `Backend <<middleware>> role:testeur`
6. `Backend <<controller>> Api\ChecklistController`
7. `Backend <<model>> Project`
8. `Backend <<model>> Checklist`
9. `Backend <<model>> ChecklistItem`
10. `Database <<tables>> checklists / checklist_items / projects / users`

## Main Sequence

### A. Open checklist form

1. `Utilisateur -> Frontend <<view>> ChecklistsView.vue : click "Creer une checklist"`
2. `ChecklistsView.vue -> ChecklistsView.vue : openCreateChecklistForm()`
3. `ChecklistsView.vue -> ChecklistsView.vue : resetForm()`
4. `ChecklistsView.vue -> ChecklistsView.vue : showChecklistForm = true`

`alt [auth.canManageChecklists == false]`

5. `ChecklistsView.vue -> Utilisateur : form is not displayed`

### B. Submit already-filled form

6. `Utilisateur -> Frontend <<view>> ChecklistsView.vue : submit form`
7. `ChecklistsView.vue -> ChecklistsView.vue : submitChecklist()`
8. `ChecklistsView.vue -> ChecklistsView.vue : submitting.value = true`
9. `ChecklistsView.vue -> ChecklistsView.vue : build payload from already-filled form`

`alt [projectId present in route.query]`

10. `ChecklistsView.vue -> ChecklistsView.vue : payload.project_id = Number(form.project_id || projectId)`
11. `ChecklistsView.vue -> ChecklistsView.vue : payload.template_scope = 'project'`
12. `ChecklistsView.vue -> ChecklistsView.vue : payload.lifecycle_status = 'draft'`

`else [projectId absent]`

13. `ChecklistsView.vue -> ChecklistsView.vue : payload.project_id = null`
14. `ChecklistsView.vue -> ChecklistsView.vue : payload.template_scope = 'global'`
15. `ChecklistsView.vue -> ChecklistsView.vue : payload.lifecycle_status = 'approved'`

16. `loop [for each form item in form.items.map(...)]`
17. `ChecklistsView.vue -> ChecklistsView.vue : map item -> { ...(id ? { id } : {}), title, description, priority, criticality }`

Resulting request:

- `apiRequest('/checklists', { method: 'POST', body: payload }, auth.token)`
- payload fields:
  `project_id`, `template_scope`, `lifecycle_status`, `name`, `description`, `category`, `is_active`, `items[]`

### C. Backend create flow

18. `apiRequest() -> Backend <<middleware>> auth:sanctum : POST /api/checklists`
19. `auth:sanctum -> Backend <<middleware>> role:testeur : authenticated request`
20. `role:testeur -> Backend <<controller>> Api\ChecklistController : ChecklistController::store(Request $request)`
21. `ChecklistController -> ChecklistController : $request->validate([...])`

`alt [validation KO]`

22. `ChecklistController -> apiRequest() : 422 validation errors`
23. `apiRequest() -> ChecklistsView.vue : throw Error(localized message)`
24. `ChecklistsView.vue -> ChecklistsView.vue : errorMessage = localizeError(...)`
25. `ChecklistsView.vue -> ChecklistsView.vue : submitting.value = false`

`else [validation OK]`

26. `alt [project_id provided]`
27. `ChecklistController -> Backend <<model>> Project : Project::findOrFail($data['project_id'])`
28. `ChecklistController -> ChecklistController : ensureTesterOwnsProject(Project $project)`

`else [project_id null]`

29. `ChecklistController -> ChecklistController : skip ensureTesterOwnsProject()`

30. `ChecklistController -> ChecklistController : DB::beginTransaction()`
31. `ChecklistController -> Backend <<model>> Checklist : Checklist::create([...])`
32. `ChecklistController -> Database : insert into checklists`
33. `loop [foreach ($data['items'] as $index => $item)]`
34. `ChecklistController -> Backend <<model>> ChecklistItem : ChecklistItem::create([...])`
35. `ChecklistController -> Database : insert into checklist_items`

`alt [success]`

36. `ChecklistController -> ChecklistController : DB::commit()`
37. `ChecklistController -> Backend <<model>> Checklist : $checklist->load('items')`
38. `ChecklistController -> apiRequest() : response()->json($checklist->load('items'), 201)`

`else [exception during transaction]`

39. `ChecklistController -> ChecklistController : DB::rollBack()`
40. `ChecklistController -> apiRequest() : response()->json(['message' => 'Error creating checklist'], 500)`

### D. Frontend post-create refresh

41. `apiRequest() -> Frontend <<view>> ChecklistsView.vue : created checklist payload`
42. `ChecklistsView.vue -> ChecklistsView.vue : successMessage = localizeMessage('Checklist creee avec succes.', settings.language)`
43. `ChecklistsView.vue -> ChecklistsView.vue : resetForm()`
44. `ChecklistsView.vue -> ChecklistsView.vue : showChecklistForm = false`
45. `ChecklistsView.vue -> ChecklistsView.vue : loadChecklists()`
46. `ChecklistsView.vue -> Frontend <<service>> api.js / apiRequest() : apiRequest(withQuery('/checklists', { page, ...(projectId ? { project_id: projectId } : {}) }), {}, auth.token)`
47. `apiRequest() -> Backend <<middleware>> auth:sanctum : GET /api/checklists`
48. `auth:sanctum -> Backend <<middleware>> role:testeur : authenticated request`
49. `role:testeur -> Backend <<controller>> Api\ChecklistController : ChecklistController::index(Request $request)`
50. `ChecklistController -> Database : paginate($perPage)`
51. `Database -> ChecklistController : paginated checklists JSON`
52. `ChecklistController -> apiRequest() : response()->json($query->paginate($perPage))`
53. `apiRequest() -> ChecklistsView.vue : { data, current_page, last_page }`
54. `ChecklistsView.vue -> ChecklistsView.vue : assign checklists.value and pagination`

## Required UML Fragments

Include these fragments explicitly:

- `alt [auth.canManageChecklists == true]` vs form hidden
- `alt [projectId present in route.query]` vs `projectId absent`
- `alt [validation KO]` vs `validation OK`
- `alt [project_id provided]` vs `project_id null`
- `alt [success]` vs `exception during transaction`
- `loop [for each form item in form.items.map(...)]`
- `loop [foreach ($data['items'] as $index => $item)]`

## Included Interfaces and Methods

Methods that should appear exactly on arrows:

- `openCreateChecklistForm()`
- `submitChecklist()`
- `loadChecklists()`
- `apiRequest('/checklists', { method: 'POST', body: payload }, auth.token)`
- `ChecklistController::store(Request $request)`
- `Project::findOrFail($data['project_id'])`
- `Checklist::create([...])`
- `ChecklistItem::create([...])`
- `$checklist->load('items')`

## Explicit Exclusions

This sequence must make these points clear:

- it starts from the click on `Creer une checklist`
- it assumes the form is already filled before submit
- it is scoped to checklist creation not linked to a user story
- it does not rely on AppMap traces
- `UserStoryController` does not participate
- the pivot `user_story_checklists` does not participate
- no dedicated backend service layer exists in this scenario
- security is enforced by `auth:sanctum` then `role:testeur`

## Source References

Main implementation sources used for this sequence:

- [ChecklistsView.vue](/C:/Users/Yahia%20Ghoufa/Desktop/checklist-manager/frontend/src/views/ChecklistsView.vue)
- [api.js](/C:/Users/Yahia%20Ghoufa/Desktop/checklist-manager/frontend/src/lib/api.js)
- [ChecklistController.php](/C:/Users/Yahia%20Ghoufa/Desktop/checklist-manager/backend/app/Http/Controllers/Api/ChecklistController.php)
- [api.php](/C:/Users/Yahia%20Ghoufa/Desktop/checklist-manager/backend/routes/api.php)
