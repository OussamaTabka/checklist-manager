<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Checklist;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\UserStory;
use App\Models\User;
use App\Models\VersionItem;
use App\Services\ProjectUserStoryImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectUserStoryImportService $projectUserStoryImportService
    ) {
    }

    public function createVersion(Request $request, Project $project)
    {
        $this->authorize('view', $project);
        $this->ensureExecutionAccess($project);

        $data = $request->validate([
            'checklist_id' => ['required', 'integer', 'exists:checklists,id'],
        ]);

        $checklist = Checklist::where('id', $data['checklist_id'])
            ->where('is_active', true)
            ->with(['items' => fn ($q) => $q->orderBy('order')])
            ->first();

        if (!$checklist) {
            return response()->json(['message' => 'Checklist not found or not active'], 422);
        }

        $lastVersion = $project->versions()->max('version_number') ?? 0;
        $nextVersionNumber = $lastVersion + 1;

        DB::beginTransaction();

        try {
            $version = ProjectVersion::create([
                'project_id' => $project->id,
                'checklist_id' => $checklist->id,
                'version_number' => $nextVersionNumber,
            ]);

            foreach ($checklist->items as $item) {
                VersionItem::create([
                    'project_version_id' => $version->id,
                    'title' => $item->title,
                    'description' => $item->description,
                    'priority' => $item->priority,
                    'criticality' => $item->criticality,
                    'order' => $item->order,
                    'status' => 'Not Tested',
                ]);
            }

            DB::commit();

            return response()->json($version->load(['items', 'checklist:id,name']), 201);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error creating project version', [
                'project_id' => $project->id,
                'checklist_id' => $data['checklist_id'] ?? null,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Error creating version'], 500);
        }
    }

    public function index()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $query = Project::with([
            'creator:id,name,email',
            'testers:id,name,email',
        ])->withCount(['userStories', 'versions']);

        if ($user->hasRole('chef') && !$user->hasRole('admin')) {
            $query->where('created_by', $user->id);
        }

        if ($user->hasRole('testeur') && !$user->hasRole('admin') && !$user->hasRole('chef')) {
            $query->whereHas('testers', fn ($testerQuery) => $testerQuery->where('users.id', $user->id));
        }

        return response()->json($query->orderByDesc('id')->paginate(10));
    }

    public function metadata()
    {
        $testers = User::with(['roles:id,name'])
            ->select(['id', 'name', 'email'])
            ->whereHas('roles', function ($query) {
                $query->where('name', 'testeur');
            })
            ->orderBy('name')
            ->get();

        return response()->json([
            'testers' => $testers,
        ]);
    }

    public function show(Project $project)
    {
        $this->authorize('view', $project);

        return response()->json(
            $project->load([
                'creator:id,name,email',
                'versions' => fn ($q) => $q->orderByDesc('version_number'),
                'versions.checklist:id,name,project_id,template_scope,lifecycle_status,generated_from',
                'versions.items' => fn ($q) => $q->orderBy('order'),
                'testers:id,name,email',
                'userStories' => fn ($q) => $q
                    ->with(['creator:id,name,email', 'checklists:id,name,project_id,lifecycle_status,generated_from'])
                    ->orderByDesc('priority')
                    ->orderByDesc('created_at'),
            ])
        );
    }

    public function store(Request $request)
    {
        $this->authorize('create', Project::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'test_objectives' => ['nullable', 'string'],
            'app_url' => ['required', 'url', 'max:2048'],
            'tester_ids' => ['required', 'array', 'min:1'],
            'tester_ids.*' => ['integer', 'exists:users,id'],
            'user_stories_file' => ['nullable', 'file', 'mimes:csv,txt,xlsx,json'],
        ]);

        $testerIds = $data['tester_ids'] ?? [];
        $testers = User::whereIn('id', $testerIds)
            ->whereHas('roles', fn ($q) => $q->where('name', 'testeur'))
            ->get();

        if ($testers->count() !== count($testerIds)) {
            return response()->json(['message' => 'One or more testers not found or invalid role'], 422);
        }

        try {
            $importedStories = $this->projectUserStoryImportService->prepareImportedStories(
                $request->file('user_stories_file')
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $validStories = $importedStories['stories'];
        $summary = [
            'manual_created' => 0,
            'imported_created' => count($importedStories['stories']),
            'total_created' => count($validStories),
            'failed_manuals' => 0,
            'failed_imports' => $importedStories['failed_count'],
            'errors' => $importedStories['errors'],
        ];

        if ($summary['total_created'] < 1) {
            return response()->json([
                'message' => 'Veuillez importer un fichier contenant au moins une User Story valide.',
                'user_stories_summary' => $summary,
            ], 422);
        }

        DB::beginTransaction();

        try {
            $project = Project::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'test_objectives' => $data['test_objectives'] ?? null,
                'app_url' => $data['app_url'],
                'created_by' => Auth::id(),
            ]);

            $project->testers()->attach($testerIds);
            $userId = Auth::id();

            foreach ($validStories as $storyData) {
                UserStory::create([
                    ...$storyData,
                    'project_id' => $project->id,
                    'created_by' => $userId,
                ]);
            }

            DB::commit();

            return response()->json(
                [
                    'project' => $project->load([
                        'creator:id,name,email',
                        'testers:id,name,email',
                    ]),
                    'user_stories_summary' => $summary,
                ],
                201
            );
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error creating project', [
                'name' => $data['name'] ?? null,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Error creating project'], 500);
        }
    }

    public function update(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'test_objectives' => ['nullable', 'string'],
            'app_url' => ['sometimes', 'required', 'url', 'max:2048'],
            'tester_ids' => ['sometimes', 'array', 'min:1'],
            'tester_ids.*' => ['integer', 'exists:users,id'],
        ]);

        if (array_key_exists('tester_ids', $data)) {
            $testers = User::whereIn('id', $data['tester_ids'])
                ->whereHas('roles', fn ($q) => $q->where('name', 'testeur'))
                ->get();

            if ($testers->count() !== count($data['tester_ids'])) {
                return response()->json(['message' => 'One or more testers not found or invalid role'], 422);
            }
        }

        $project->update([
            'name' => $data['name'] ?? $project->name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $project->description,
            'test_objectives' => array_key_exists('test_objectives', $data) ? $data['test_objectives'] : $project->test_objectives,
            'app_url' => $data['app_url'] ?? $project->app_url,
        ]);

        if (array_key_exists('tester_ids', $data)) {
            $project->testers()->sync($data['tester_ids']);
        }

        return response()->json(
            $project->load([
                'creator:id,name,email',
                'testers:id,name,email',
            ])
        );
    }

    public function destroy(Project $project)
    {
        $this->authorize('delete', $project);

        $project->delete();

        return response()->json(null, 204);
    }

    public function assignTesters(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'tester_ids' => ['required', 'array', 'min:1'],
            'tester_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $testers = User::whereIn('id', $data['tester_ids'])
            ->whereHas('roles', fn ($q) => $q->where('name', 'testeur'))
            ->get();

        if ($testers->count() !== count($data['tester_ids'])) {
            return response()->json(['message' => 'One or more testers not found or invalid role'], 422);
        }

        $project->testers()->sync($data['tester_ids']);

        return response()->json($project->load('testers:id,name,email'), 200);
    }

    private function ensureExecutionAccess(Project $project): void
    {
        $user = Auth::user();

        abort_unless(
            $user && $user->hasRole('testeur') && $project->testers()->where('users.id', $user->id)->exists(),
            403,
            'Only assigned testers can design execution checklists for this project.'
        );
    }
}
