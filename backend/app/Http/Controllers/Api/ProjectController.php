<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Checklist;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\VersionItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    public function createVersion(Request $request, Project $project)
{
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
        return response()->json(['message' => 'Error creating version'], 500);
    }
}
    public function index()
    {
        return response()->json(
            Project::with(['creator:id,name,email'])
                ->orderByDesc('id')
                ->paginate(10)
        );
    }

    public function show(Project $project)
    {
        return response()->json(
            $project->load([
                'creator:id,name,email',
                'versions' => fn ($q) => $q->orderByDesc('version_number'),
                'versions.checklist:id,name',
                'versions.items' => fn ($q) => $q->orderBy('order')
            ])
        );
    }

    // POST /api/projects  => crée projet + version 1 + snapshot items
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'checklist_id' => ['required', 'integer', 'exists:checklists,id'],
        ]);

        // On ne permet que les checklists actives
        $checklist = Checklist::where('id', $data['checklist_id'])
            ->where('is_active', true)
            ->with(['items' => fn ($q) => $q->orderBy('order')])
            ->first();

        if (!$checklist) {
            return response()->json(['message' => 'Checklist not found or not active'], 422);
        }

        DB::beginTransaction();
        try {
            $project = Project::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $version = ProjectVersion::create([
                'project_id' => $project->id,
                'checklist_id' => $checklist->id,
                'version_number' => 1,
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
                $project->load(['versions.items', 'versions.checklist']),
                201
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error creating project'], 500);
        }
    }
}