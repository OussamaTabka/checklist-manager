<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProjectVersion;

class ProjectVersionController extends Controller
{
    public function show(ProjectVersion $projectVersion)
    {
        $projectVersion->loadMissing('project');
        $this->authorize('view', $projectVersion->project);

        return response()->json(
            $projectVersion->load([
                'items.comments.user',
                'checklist',
                'project.creator'
            ])
        );
    }

    public function progress(ProjectVersion $projectVersion)
    {
        $projectVersion->loadMissing('project');
        $this->authorize('view', $projectVersion->project);

        $items = $projectVersion->items();

        $total = (int) $items->count();
        $passed = (int) (clone $items)->where('status', 'Passed')->count();
        $failed = (int) (clone $items)->where('status', 'Failed')->count();
        $blocked = (int) (clone $items)->where('status', 'Blocked')->count();
        $notTested = (int) (clone $items)->where('status', 'Not Tested')->count();

        $tested = $passed + $failed + $blocked;
        $completionPercent = $total > 0 ? round(($tested / $total) * 100, 2) : 0;

        return response()->json([
            'project_version_id' => $projectVersion->id,
            'version_number' => $projectVersion->version_number,
            'total' => $total,
            'passed' => $passed,
            'failed' => $failed,
            'blocked' => $blocked,
            'not_tested' => $notTested,
            'completion_percent' => $completionPercent,
        ]);
    }
}
