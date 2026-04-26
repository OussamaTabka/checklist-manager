<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Checklist;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\User;
use App\Models\VersionItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProjectController extends Controller
{
    public function createVersion(Request $request, Project $project)
{
    $this->authorize('update', $project);

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

        return response()->json(
            $version->load(['items', 'checklist:id,name']),
            201
        );
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
        $query = Project::with(['creator:id,name,email', 'checklists:id,name,description', 'testers:id,name,email']);

        // Chef voit seulement ses projets, Admin voit tous, Testeur voit seulement les projets assignés
        if ($user->hasRole('chef') && !$user->hasRole('admin')) {
            $query->where('created_by', $user->id);
        }

        if ($user->hasRole('testeur') && !$user->hasRole('admin') && !$user->hasRole('chef')) {
            $query->whereHas('testers', fn ($testerQuery) => $testerQuery->where('users.id', $user->id));
        }

        return response()->json(
            $query->orderByDesc('id')->paginate(10)
        );
    }

    // GET /api/projects/metadata
    public function metadata()
    {
        $checklists = Checklist::query()
            ->where('is_active', true)
            ->select(['id', 'name', 'description'])
            ->orderBy('name')
            ->get();

        $testers = User::with(['roles:id,name'])
            ->select(['id', 'name', 'email'])
            ->whereHas('roles', function ($query) {
                $query->whereIn('name', ['testeur', 'chef', 'admin_contenus', 'admin']);
            })
            ->orderBy('name')
            ->get();

        return response()->json([
            'checklists' => $checklists,
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
                'versions.checklist:id,name',
                'versions.items' => fn ($q) => $q->orderBy('order'),
                'checklists:id,name,description,template_scope,lifecycle_status,generated_from',
                'testers:id,name,email',
                'userStories' => fn ($q) => $q->with(['creator:id,name,email', 'checklists:id,name,lifecycle_status,generated_from'])->orderByDesc('priority')->orderByDesc('created_at'),
            ])
        );
    }

    // POST /api/projects  => crée projet + version 1 + snapshot items
    public function store(Request $request)
    {
        $this->authorize('create', Project::class);

        $data = $request->validate([
            'name' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'app_url' => ['required', 'url', 'max:2048'],
            'checklist_id' => ['nullable', 'integer', 'exists:checklists,id'], // Optional initial checklist
            'checklist_ids' => ['nullable', 'array'], // Additional checklists to assign
            'checklist_ids.*' => ['integer', 'exists:checklists,id'],
            'tester_ids' => ['nullable', 'array'], // Testers to assign
            'tester_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $primaryChecklist = null;
        if (!empty($data['checklist_id'])) {
            $primaryChecklist = Checklist::where('id', $data['checklist_id'])
                ->where('is_active', true)
                ->with(['items' => fn ($q) => $q->orderBy('order')])
                ->first();

            if (!$primaryChecklist) {
                return response()->json(['message' => 'Checklist not found or not active'], 422);
            }
        }

        // Validate additional checklists if provided
        $additionalChecklistIds = $data['checklist_ids'] ?? [];
        if (!empty($data['checklist_id']) && !in_array($data['checklist_id'], $additionalChecklistIds)) {
            $additionalChecklistIds[] = $data['checklist_id'];
        }

        if (!empty($additionalChecklistIds)) {
            $checklists = Checklist::whereIn('id', $additionalChecklistIds)
                ->where('is_active', true)
                ->get();

            if ($checklists->count() !== count($additionalChecklistIds)) {
                return response()->json(['message' => 'One or more checklists not found or not active'], 422);
            }
        }

        // Validate testers if provided
        $testerIds = $data['tester_ids'] ?? [];
        if (!empty($testerIds)) {
            $testers = User::whereIn('id', $testerIds)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['testeur', 'chef', 'admin_contenus']))
                ->get();

            if ($testers->count() !== count($testerIds)) {
                return response()->json(['message' => 'One or more testers not found or invalid role'], 422);
            }
        }

        DB::beginTransaction();
        try {
            // Create project
            $project = Project::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'app_url' => $data['app_url'],
                'created_by' => Auth::id(),
            ]);

            // Attach all checklists to project
            if (!empty($additionalChecklistIds)) {
                $project->checklists()->attach($additionalChecklistIds);
            }

            // Attach testers to project
            if (!empty($testerIds)) {
                $project->testers()->attach($testerIds);
            }

            if ($primaryChecklist) {
                $version = ProjectVersion::create([
                    'project_id' => $project->id,
                    'checklist_id' => $primaryChecklist->id,
                    'version_number' => 1,
                ]);

                foreach ($primaryChecklist->items as $item) {
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
            }

            DB::commit();

            return response()->json(
                $project->load([
                    'versions.items',
                    'versions.checklist',
                    'checklists:id,name',
                    'testers:id,name,email',
                ]),
                201
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating project', [
                'name' => $data['name'] ?? null,
                'checklist_id' => $data['checklist_id'] ?? null,
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
            'name' => ['sometimes', 'required', 'string'],
            'description' => ['nullable', 'string'],
            'app_url' => ['sometimes', 'required', 'url', 'max:2048'],
        ]);

        $project->update($data);
        $project->load(['creator:id,name,email', 'versions']);
        return response()->json($project);
    }

    public function destroy(Project $project)
    {
        $this->authorize('delete', $project);

        $project->delete();
        return response()->json(null, 204);
    }

    /**
     * Assign testers to a project
     */
    public function assignTesters(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'tester_ids' => ['required', 'array'],
            'tester_ids.*' => ['integer', 'exists:users,id'],
        ]);

        // Validate testers have correct roles
        $testers = User::whereIn('id', $data['tester_ids'])
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['testeur', 'chef', 'admin_contenus']))
            ->get();

        if ($testers->count() !== count($data['tester_ids'])) {
            return response()->json(['message' => 'One or more testers not found or invalid role'], 422);
        }

        // Sync testers (replace all with new ones)
        $project->testers()->sync($data['tester_ids']);

        return response()->json(
            $project->load('testers:id,name,email'),
            200
        );
    }

    /**
     * Assign checklists to a project
     */
    public function assignChecklists(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'checklist_ids' => ['required', 'array'],
            'checklist_ids.*' => ['integer', 'exists:checklists,id'],
        ]);

        // Validate checklists are active
        $checklists = Checklist::whereIn('id', $data['checklist_ids'])
            ->where('is_active', true)
            ->get();

        if ($checklists->count() !== count($data['checklist_ids'])) {
            return response()->json(['message' => 'One or more checklists not found or not active'], 422);
        }

        // Sync checklists (replace all with new ones)
        $project->checklists()->sync($data['checklist_ids']);

        return response()->json(
            $project->load('checklists:id,name,description'),
            200
        );
    }
}
