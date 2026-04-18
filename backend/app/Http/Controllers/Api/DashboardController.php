<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Checklist;
use App\Models\Project;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function summary()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $query = Project::query()->with([
            'versions' => fn ($q) => $q->orderByDesc('version_number')->with([
                'items' => fn ($itemQuery) => $itemQuery->orderBy('order'),
            ]),
        ]);

        if ($user->hasRole('chef') && !$user->hasRole('admin')) {
            $query->where('created_by', $user->id);
        }

        $projects = $query->get(['id', 'name', 'created_by']);

        $totalItems = 0;
        $testedItems = 0;
        $criticalFailures = 0;
        $failedItems = 0;
        $projectRows = [];

        foreach ($projects as $project) {
            $latestVersion = $project->versions->first();
            $items = $latestVersion?->items ?? collect();

            $passed = $items->where('status', 'Passed')->count();
            $failed = $items->where('status', 'Failed')->count();
            $blocked = $items->where('status', 'Blocked')->count();
            $pending = $items->where('status', 'Not Tested')->count();
            $total = $items->count();

            $tested = $passed + $failed + $blocked;
            $completion = $total > 0 ? round(($tested / $total) * 100, 2) : 0;
            $successRate = $tested > 0 ? round(($passed / $tested) * 100, 2) : 0;
            $criticalFailed = $items->where('criticality', 'Critical')->where('status', 'Failed')->count();

            $totalItems += $total;
            $testedItems += $tested;
            $criticalFailures += $criticalFailed;
            $failedItems += $failed;

            $projectRows[] = [
                'id' => $project->id,
                'name' => $project->name,
                'version' => $latestVersion?->version_number,
                'successRate' => $successRate,
                'completion' => $completion,
                'failed' => $failed,
                'pending' => $pending,
            ];
        }

        return response()->json([
            'totalProjects' => $projects->count(),
            'totalChecklists' => $user->hasRole('admin') ? Checklist::count() : null,
            'globalProgress' => $totalItems > 0 ? round(($testedItems / $totalItems) * 100, 2) : 0,
            'failedCriticalItems' => $criticalFailures,
            'testsRun' => $testedItems,
            'testsFailed' => $failedItems,
            'projectSuccessRows' => $projectRows,
        ]);
    }
}
