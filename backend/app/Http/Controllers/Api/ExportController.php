<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectVersion;
use Illuminate\Http\Request;

class ExportController extends Controller
{
    public function exportProject(Project $project)
    {
        $project->load([
            'versions.items.comments.user',
            'versions.checklist',
            'creator'
        ]);

        $data = [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'created_by' => $project->creator?->name,
                'created_at' => $project->created_at,
            ],
            'versions' => $project->versions->map(function ($version) {
                return [
                    'version_number' => $version->version_number,
                    'checklist_name' => $version->checklist?->name,
                    'items' => $version->items->map(function ($item) {
                        return [
                            'title' => $item->title,
                            'description' => $item->description,
                            'priority' => $item->priority,
                            'criticality' => $item->criticality,
                            'status' => $item->status,
                            'comments_count' => $item->comments->count(),
                        ];
                    })->toArray(),
                ];
            })->toArray(),
        ];

        return response()->json($data);
    }

    public function exportProjectVersion(ProjectVersion $projectVersion)
    {
        $projectVersion->load([
            'items.comments.user',
            'checklist',
            'project.creator'
        ]);

        $data = [
            'project_name' => $projectVersion->project?->name,
            'version' => $projectVersion->version_number,
            'checklist_name' => $projectVersion->checklist?->name,
            'created_at' => $projectVersion->created_at,
            'items' => $projectVersion->items->map(function ($item) {
                return [
                    'title' => $item->title,
                    'description' => $item->description,
                    'priority' => $item->priority,
                    'criticality' => $item->criticality,
                    'status' => $item->status,
                    'tested_by' => $item->tested_by,
                    'tested_at' => $item->tested_at,
                    'comments' => $item->comments->map(function ($comment) {
                        return [
                            'author' => $comment->user?->name,
                            'content' => $comment->content,
                            'date' => $comment->created_at,
                        ];
                    })->toArray(),
                ];
            })->toArray(),
        ];

        return response()->json($data);
    }
}
