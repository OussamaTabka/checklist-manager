<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Checklist;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index()
    {
        $projects = Project::with('creator', 'versions')->paginate(15);
        return response()->json($projects);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'checklist_id' => 'required|exists:checklists,id'
        ]);

        $validated['created_by'] = auth()->id();

        $project = Project::create($validated);
        $project->load('creator', 'versions');
        return response()->json($project, 201);
    }

    public function show(Project $project)
    {
        $project->load('creator', 'versions.items.comments.user');
        return response()->json($project);
    }

    public function update(Request $request, Project $project)
    {
        // Vérifier les permissions
        if ($project->created_by !== auth()->id() && !auth()->user()->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'checklist_id' => 'sometimes|required|exists:checklists,id'
        ]);

        $project->update($validated);
        $project->load('creator', 'versions');
        return response()->json($project);
    }

    public function destroy(Project $project)
    {
        // Vérifier les permissions
        if ($project->created_by !== auth()->id() && !auth()->user()->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $project->delete();
        return response()->json(null, 204);
    }
}
