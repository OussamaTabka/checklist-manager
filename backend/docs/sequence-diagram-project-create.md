# Sequence Diagram: Create Project

This document describes the real sequence currently implemented in the codebase for creating a project through `POST /api/projects`.

It is focused on the create flow in `ProjectsView.vue`, including project name validation, tester validation, manual or imported user stories, transactional persistence, notifications, and the frontend refresh after success.

## Summary

Real implemented flow:

`Utilisateur` -> `ProjectsView.vue` -> `apiRequest()` -> `GET /api/projects/validate-name` -> `POST /api/projects` -> `auth:sanctum` -> `role:chef` -> `Api\ProjectController::store()` -> `Project` + testers + `UserStory` + notifications -> database -> `201` -> refresh via `GET /api/projects`

This scenario documents:

- the project creation request path used by the frontend
- the preliminary project name validation
- the validation of assigned testers
- the preparation of manual and imported user stories
- the transaction used to persist the project and related records
- the notifications triggered during project creation
- the frontend list refresh after a successful creation

## Participants

Display the participants in this order:

1. `Utilisateur`
2. `Frontend <<view>> ProjectsView.vue`
3. `Frontend <<service>> api.js / apiRequest()`
4. `Frontend <<helper>> projectUserStories.js`
5. `Backend <<middleware>> auth:sanctum`
6. `Backend <<middleware>> role:chef`
7. `Backend <<controller>> Api\ProjectController`
8. `Backend <<service>> ProjectUserStoryImportService`
9. `Backend <<service>> NotificationService`
10. `Backend <<model>> User`
11. `Backend <<model>> Project`
12. `Backend <<model>> UserStory`
13. `Database <<tables>> projects / project_testers / user_stories / users / notifications`

## Main Sequence

### A. Open project form

1. `Utilisateur -> Frontend <<view>> ProjectsView.vue : click "Creer un projet"`
2. `ProjectsView.vue -> ProjectsView.vue : openCreateForm()`
3. `ProjectsView.vue -> ProjectsView.vue : resetForm(false)`
4. `ProjectsView.vue -> ProjectsView.vue : showProjectForm = true`

`alt [auth.canManageProjects == false]`

5. `ProjectsView.vue -> Utilisateur : form is not displayed`

### B. Validate and submit

6. `Utilisateur -> Frontend <<view>> ProjectsView.vue : submit form`
7. `ProjectsView.vue -> ProjectsView.vue : submitProject()`
8. `ProjectsView.vue -> ProjectsView.vue : creating.value = true`
9. `ProjectsView.vue -> ProjectsView.vue : validateProjectName()`
10. `ProjectsView.vue -> Frontend <<service>> api.js / apiRequest() : apiRequest(withQuery('/projects/validate-name', { name, ignore_id }), {}, auth.token)`
11. `apiRequest() -> Backend <<middleware>> auth:sanctum : GET /api/projects/validate-name`
12. `auth:sanctum -> Backend <<middleware>> role:chef : authenticated request`
13. `role:chef -> Backend <<controller>> Api\ProjectController : ProjectController::validateName(Request $request)`
14. `ProjectController -> Database : exists() on active project name`
15. `ProjectController -> apiRequest() : response()->json({ exists, message })`
16. `apiRequest() -> ProjectsView.vue : name validation result`

`alt [project name already exists]`

17. `ProjectsView.vue -> ProjectsView.vue : createError = tr('project_choose_another_name', ...)`
18. `ProjectsView.vue -> ProjectsView.vue : creating.value = false`

`else [project name valid]`

19. `alt [importedStoriesFile present && no analysis yet]`
20. `ProjectsView.vue -> ProjectsView.vue : analyzeSelectedUserStoriesFile()`
21. `ProjectsView.vue -> Frontend <<helper>> projectUserStories.js : analyzeImportedUserStories(importedStoriesFile)`
22. `projectUserStories.js -> ProjectsView.vue : importedStoriesAnalysis`

`else [no file analysis needed]`

23. `ProjectsView.vue -> ProjectsView.vue : skip file analysis`

24. `ProjectsView.vue -> ProjectsView.vue : buildManualStoriesPayload()`

`alt [no imported stories && no valid manual stories]`

25. `ProjectsView.vue -> ProjectsView.vue : createError = tr('user_story_required', ...)`
26. `ProjectsView.vue -> ProjectsView.vue : creating.value = false`

### C. Backend create flow

27. `ProjectsView.vue -> Frontend <<service>> api.js / apiRequest() : apiRequest('/projects', { method: 'POST', body: payload }, auth.token)`
28. `apiRequest() -> Backend <<middleware>> auth:sanctum : POST /api/projects`
29. `auth:sanctum -> Backend <<middleware>> role:chef : authenticated request`
30. `role:chef -> Backend <<controller>> Api\ProjectController : ProjectController::store(Request $request)`
31. `ProjectController -> ProjectController : authorize('create', Project::class)`
32. `ProjectController -> ProjectController : $request->validate([...])`
33. `ProjectController -> Backend <<model>> User : validate tester_ids against active testeurs`

`alt [invalid tester ids]`

34. `ProjectController -> apiRequest() : 422 one or more testers invalid`
35. `apiRequest() -> ProjectsView.vue : throw Error(localized message)`
36. `ProjectsView.vue -> ProjectsView.vue : createError = localizeError(...)`
37. `ProjectsView.vue -> ProjectsView.vue : creating.value = false`

`else [testers valid]`

38. `ProjectController -> Backend <<service>> ProjectUserStoryImportService : prepareManualStories(...)`
39. `ProjectController -> Backend <<service>> ProjectUserStoryImportService : prepareImportedStories(...)`
40. `ProjectController -> ProjectController : build user_stories_summary`

`alt [import service throws RuntimeException]`

41. `ProjectController -> apiRequest() : 422 import/validation message`
42. `apiRequest() -> ProjectsView.vue : throw Error(localized message)`
43. `ProjectsView.vue -> ProjectsView.vue : createError = localizeError(...)`
44. `ProjectsView.vue -> ProjectsView.vue : creating.value = false`

`else [story preparation succeeded]`

45. `alt [total_created < 1]`
46. `ProjectController -> apiRequest() : 422 no valid user story`
47. `apiRequest() -> ProjectsView.vue : throw Error(localized message)`
48. `ProjectsView.vue -> ProjectsView.vue : createError = localizeError(...)`
49. `ProjectsView.vue -> ProjectsView.vue : creating.value = false`

`else [at least one valid story]`

50. `ProjectController -> ProjectController : DB::beginTransaction()`
51. `ProjectController -> Backend <<model>> Project : Project::create([...])`
52. `ProjectController -> Database : insert into projects`
53. `ProjectController -> Backend <<model>> Project : $project->testers()->attach($testerIds)`
54. `ProjectController -> Database : insert into project_testers`
55. `ProjectController -> ProjectController : notifyNewAssignedTesters($project, $testerIds)`
56. `ProjectController -> Backend <<service>> NotificationService : notifyProjectAssigned(...)`
57. `ProjectController -> loop : foreach valid story`
58. `ProjectController -> Backend <<model>> UserStory : UserStory::create([...])`
59. `ProjectController -> Database : insert into user_stories`
60. `ProjectController -> Backend <<service>> NotificationService : notifyProjectCreated($project, Auth::user())`

`alt [success]`

61. `ProjectController -> ProjectController : DB::commit()`
62. `ProjectController -> Backend <<model>> Project : $project->load(['creator:id,name,email', 'testers:id,name,email'])`
63. `ProjectController -> apiRequest() : response()->json({ project, user_stories_summary }, 201)`

`else [exception during transaction]`

64. `ProjectController -> ProjectController : DB::rollBack()`
65. `ProjectController -> apiRequest() : response()->json(['message' => 'Error creating project'], 500)`

### D. Frontend post-create refresh

66. `apiRequest() -> Frontend <<view>> ProjectsView.vue : created project payload`
67. `ProjectsView.vue -> ProjectsView.vue : successMessage = buildUserStoriesSuccessMessage(summary)`
68. `ProjectsView.vue -> ProjectsView.vue : resetForm(true)`
69. `ProjectsView.vue -> ProjectsView.vue : loadProjectLists(1, archivedPagination.current_page)`
70. `ProjectsView.vue -> Frontend <<service>> api.js / apiRequest() : apiRequest(withQuery('/projects', { page: 1, status: 'active' }), {}, auth.token)`
71. `apiRequest() -> Backend <<middleware>> auth:sanctum : GET /api/projects`
72. `auth:sanctum -> Backend <<middleware>> role:chef : authenticated request`
73. `role:chef -> Backend <<controller>> Api\ProjectController : ProjectController::index(Request $request)`
74. `ProjectController -> Database : paginate(10)->withQueryString()`
75. `ProjectController -> apiRequest() : response()->json(...)`
76. `apiRequest() -> ProjectsView.vue : active projects`
77. `ProjectsView.vue -> ProjectsView.vue : creating.value = false`

## Required UML Fragments

Include these fragments explicitly:

- `alt [auth.canManageProjects == true]` vs form hidden
- `alt [project name already exists]` vs valid name
- `alt [importedStoriesFile present && no analysis yet]` vs no file analysis
- `alt [no imported stories && no valid manual stories]` vs continue
- `alt [invalid tester ids]` vs testers valid
- `alt [import service throws RuntimeException]` vs story preparation succeeded
- `alt [total_created < 1]` vs at least one valid story
- `alt [success]` vs `exception during transaction`
- loop over valid stories

## Included Interfaces and Methods

Methods that should appear exactly on arrows:

- `openCreateForm()`
- `submitProject()`
- `validateProjectName()`
- `apiRequest(withQuery('/projects/validate-name', { name, ignore_id }), {}, auth.token)`
- `apiRequest('/projects', { method: 'POST', body: payload }, auth.token)`
- `ProjectController::validateName(Request $request)`
- `ProjectController::store(Request $request)`
- `Project::create([...])`
- `UserStory::create([...])`
- `$project->testers()->attach($testerIds)`

## Explicit Exclusions

This sequence must make these points clear:

- it is scoped to project creation
- it does not include project update, archive, restore, or delete flows
- it does not include project version creation
- it supports manual and imported user stories in the same create flow
- security is enforced by `auth:sanctum` then `role:chef`

## Source References

Main implementation sources used for this sequence:

- [ProjectsView.vue](/C:/Users/Yahia%20Ghoufa/Desktop/checklist-manager/frontend/src/views/ProjectsView.vue)
- [api.js](/C:/Users/Yahia%20Ghoufa/Desktop/checklist-manager/frontend/src/lib/api.js)
- [ProjectController.php](/C:/Users/Yahia%20Ghoufa/Desktop/checklist-manager/backend/app/Http/Controllers/Api/ProjectController.php)
- [api.php](/C:/Users/Yahia%20Ghoufa/Desktop/checklist-manager/backend/routes/api.php)
