<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Checklist;
use App\Models\Project;
use App\Models\User;
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
        ])->withCount('userStories');

        if ($user->hasRole('chef') && !$user->hasRole('admin')) {
            $query->where('created_by', $user->id);
        }

        $projects = $query->get(['id', 'name', 'created_by']);

        $totalItems = 0;
        $testedItems = 0;
        $passedItems = 0;
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
            $passedItems += $passed;
            $criticalFailures += $criticalFailed;
            $failedItems += $failed;

            $projectRows[] = [
                'id' => $project->id,
                'name' => $project->name,
                'userStoriesCount' => (int) ($project->user_stories_count ?? 0),
                'successRate' => $successRate,
                'completion' => $completion,
                'failed' => $failed,
                'pending' => $pending,
                'status' => $this->resolveProjectStatus($completion, $successRate, $failed, $pending),
            ];
        }

        $projectsAtRisk = collect($projectRows)
            ->whereIn('status', ['at-risk', 'delayed'])
            ->count();

        $successRate = $testedItems > 0 ? round(($passedItems / $testedItems) * 100, 2) : 0;

        $adminMetrics = [
            'totalUsers' => 0,
            'activeUsers' => 0,
            'pendingInvitations' => 0,
            'totalTesters' => 0,
            'projectManagers' => 0,
            'administrators' => 0,
        ];

        if ($user->hasRole('admin')) {
            $activeUserStatuses = ['active', 'pending'];

            $adminMetrics = [
                'totalUsers' => User::query()->whereIn('account_status', $activeUserStatuses)->count(),
                'activeUsers' => User::query()->where('account_status', 'active')->count(),
                'pendingInvitations' => User::query()->where('account_status', 'pending')->count(),
                'totalTesters' => User::query()
                    ->whereIn('account_status', $activeUserStatuses)
                    ->whereHas('roles', fn ($query) => $query->where('name', 'testeur'))
                    ->count(),
                'projectManagers' => User::query()
                    ->whereIn('account_status', $activeUserStatuses)
                    ->whereHas('roles', fn ($query) => $query->where('name', 'chef'))
                    ->count(),
                'administrators' => User::query()
                    ->whereIn('account_status', $activeUserStatuses)
                    ->whereHas('roles', fn ($query) => $query->where('name', 'admin'))
                    ->count(),
            ];
        }

        return response()->json([
            'totalProjects' => $projects->count(),
            'totalChecklists' => $user->hasRole('admin') ? Checklist::count() : null,
            'globalProgress' => $totalItems > 0 ? round(($testedItems / $totalItems) * 100, 2) : 0,
            'failedCriticalItems' => $criticalFailures,
            'testsRun' => $testedItems,
            'testsFailed' => $failedItems,
            'projectsAtRisk' => $projectsAtRisk,
            'successRate' => $successRate,
            'projectSuccessRows' => $projectRows,
            ...$adminMetrics,
        ]);
    }

    private function resolveProjectStatus(float|int $completion, float|int $successRate, int $failed, int $pending): string
    {
        if ($completion === 0 && $pending > 0) {
            return 'not-started';
        }

        if ($failed >= 3 || $successRate < 40) {
            return 'at-risk';
        }

        if ($pending > 0 && $completion < 100) {
            if ($failed > 0) {
                return 'delayed';
            }

            return 'in-progress';
        }

        if ($failed === 0 && $successRate >= 95) {
            return 'perfect';
        }

        if ($failed > 0) {
            return 'delayed';
        }

        return 'in-progress';
    }
}
