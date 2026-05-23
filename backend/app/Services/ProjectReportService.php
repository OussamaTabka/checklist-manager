<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Models\VersionItem;
use Illuminate\Support\Collection;

class ProjectReportService
{
    public function build(Project $project, User $generatedBy, string $format, string $type, array $options = []): array
    {
        $project->load([
            'creator:id,name,email',
            'testers:id,name,email',
            'userStories.creator:id,name,email',
            'userStories.checklists:id,name,description,project_id,lifecycle_status,generated_from',
            'versions' => fn ($query) => $query->orderByDesc('version_number'),
            'versions.checklist:id,name,description,project_id,lifecycle_status,generated_from',
            'versions.items' => fn ($query) => $query->orderBy('order'),
            'versions.items.tester:id,name,email',
            'versions.items.comments.user:id,name,email',
            'versions.items.testResults.testRun',
            'versions.items.changes.changedBy:id,name,email',
            'versions.testRuns.requester:id,name,email',
            'versions.testRuns.results',
        ]);

        $includes = $this->normalizeIncludes($options);
        $versionItems = $project->versions->flatMap(fn ($version) => $version->items)->values();
        $summary = $this->buildSummary($versionItems);
        $failedAndBlocked = $this->buildFailedAndBlocked($versionItems);
        $comments = $this->buildComments($versionItems);
        $executionHistory = $this->buildExecutionHistory($project);
        $automationResults = $this->buildAutomationResults($project);

        return [
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'generated_by' => $generatedBy->name,
                'generated_by_email' => $generatedBy->email,
                'format' => $format,
                'type' => $type,
                'includes' => $includes,
            ],
            'project' => $this->buildProjectData($project),
            'summary' => $summary,
            'user_stories' => $includes['include_user_stories'] ? $this->buildUserStories($project) : [],
            'checklists' => $includes['include_checklists'] ? $this->buildChecklists($project) : [],
            'test_cases' => $includes['include_execution_results'] ? $this->buildTestCases($project) : [],
            'failed_and_blocked' => $includes['include_failed_blocked'] ? $failedAndBlocked : [],
            'comments' => $includes['include_comments'] ? $comments : [],
            'execution_history' => $includes['include_history'] ? $executionHistory : [],
            'automation_results' => $includes['include_automation_traces'] ? $automationResults : [],
            'recommendations' => $this->buildRecommendations($project, $summary, $failedAndBlocked, $automationResults),
            'quality_risks' => $this->buildQualityRisks($summary, $failedAndBlocked),
            'scope' => $this->buildScope($project),
            'coverage' => $this->buildCoverage($project, $versionItems),
            'conclusion' => $this->buildConclusion($summary),
        ];
    }

    public function normalizeIncludes(array $options = []): array
    {
        $defaults = [
            'include_user_stories' => true,
            'include_checklists' => true,
            'include_execution_results' => true,
            'include_failed_blocked' => true,
            'include_comments' => true,
            'include_history' => true,
            'include_automation_traces' => true,
        ];

        foreach ($defaults as $key => $default) {
            if (!array_key_exists($key, $options)) {
                $options[$key] = $default;
                continue;
            }

            $options[$key] = filter_var($options[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            $options[$key] = $options[$key] ?? $default;
        }

        return $options;
    }

    private function buildProjectData(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name ?: 'Non disponible',
            'description' => $project->description ?: 'Non disponible',
            'test_objectives' => $project->test_objectives ?: 'Non disponible',
            'app_url' => $project->app_url ?: 'Non disponible',
            'project_manager' => $project->creator?->name ?: 'Non disponible',
            'project_manager_email' => $project->creator?->email ?: 'Non disponible',
            'assigned_testers' => $project->testers->map(fn ($tester) => [
                'id' => $tester->id,
                'name' => $tester->name,
                'email' => $tester->email,
            ])->values()->all(),
            'created_at' => optional($project->created_at)->toIso8601String(),
            'updated_at' => optional($project->updated_at)->toIso8601String(),
            'versions_count' => $project->versions->count(),
            'user_stories_count' => $project->userStories->count(),
        ];
    }

    private function buildSummary(Collection $versionItems): array
    {
        $total = $versionItems->count();
        $passed = $versionItems->where('status', 'Passed')->count();
        $failed = $versionItems->where('status', 'Failed')->count();
        $blocked = $versionItems->where('status', 'Blocked')->count();
        $notTested = $versionItems->reject(fn (VersionItem $item) => in_array($item->status, ['Passed', 'Failed', 'Blocked'], true))->count();
        $executed = $passed + $failed + $blocked;

        return [
            'total_test_cases' => $total,
            'executed_tests' => $executed,
            'not_tested' => $notTested,
            'passed' => $passed,
            'failed' => $failed,
            'blocked' => $blocked,
            'execution_rate' => $total > 0 ? round(($executed / $total) * 100, 2) : 0.0,
            'pass_rate' => $executed > 0 ? round(($passed / $executed) * 100, 2) : 0.0,
            'risk_count' => $failed + $blocked,
        ];
    }

    private function buildUserStories(Project $project): array
    {
        return $project->userStories
            ->map(fn ($story) => [
                'id' => $story->id,
                'story_id' => $story->story_id,
                'title' => $story->title ?: 'Non disponible',
                'description' => $story->description ?: 'Non disponible',
                'status' => $story->status ?: 'Non disponible',
                'priority' => $story->priority ?: 'Non disponible',
                'acceptance_criteria' => $story->acceptance_criteria ?: 'Non disponible',
                'business_value' => $story->business_value ?? 'Non disponible',
                'created_by' => $story->creator?->name ?: 'Non disponible',
                'checklists' => $story->checklists->map(fn ($checklist) => [
                    'id' => $checklist->id,
                    'name' => $checklist->name,
                    'lifecycle_status' => $checklist->lifecycle_status ?: 'Non disponible',
                    'generated_from' => $checklist->generated_from ?: 'Non disponible',
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    private function buildChecklists(Project $project): array
    {
        $checklists = collect();

        foreach ($project->versions as $version) {
            if ($version->checklist) {
                $checklists->push([
                    'id' => $version->checklist->id,
                    'name' => $version->checklist->name ?: 'Non disponible',
                    'description' => $version->checklist->description ?: 'Non disponible',
                    'source' => 'project_version',
                    'version_number' => $version->version_number,
                    'lifecycle_status' => $version->checklist->lifecycle_status ?: 'Non disponible',
                    'generated_from' => $version->checklist->generated_from ?: 'Non disponible',
                    'test_cases_count' => $version->items->count(),
                ]);
            }
        }

        foreach ($project->userStories as $story) {
            foreach ($story->checklists as $checklist) {
                $checklists->push([
                    'id' => $checklist->id,
                    'name' => $checklist->name ?: 'Non disponible',
                    'description' => $checklist->description ?: 'Non disponible',
                    'source' => 'user_story',
                    'version_number' => null,
                    'lifecycle_status' => $checklist->lifecycle_status ?: 'Non disponible',
                    'generated_from' => $checklist->generated_from ?: 'Non disponible',
                    'test_cases_count' => null,
                ]);
            }
        }

        return $checklists
            ->unique(fn (array $checklist) => $checklist['source'] . ':' . $checklist['id'] . ':' . ($checklist['version_number'] ?? 'na'))
            ->values()
            ->all();
    }

    private function buildTestCases(Project $project): array
    {
        $cases = [];

        foreach ($project->versions as $version) {
            foreach ($version->items as $item) {
                $latestResult = $item->testResults
                    ->sortByDesc(fn ($result) => optional($result->executed_at)?->timestamp ?? 0)
                    ->first();

                $cases[] = [
                    'id' => $item->id,
                    'version_id' => $version->id,
                    'version_number' => $version->version_number,
                    'checklist_id' => $version->checklist?->id,
                    'checklist_name' => $version->checklist?->name ?: 'Non disponible',
                    'title' => $item->title,
                    'description' => $item->description ?: 'Non disponible',
                    'priority' => $item->priority ?: 'Non disponible',
                    'criticality' => $item->criticality ?: 'Non disponible',
                    'status' => $this->displayStatus($item->status),
                    'tested_by' => $item->tester?->name ?: 'Non disponible',
                    'tested_at' => optional($item->tested_at)->toIso8601String(),
                    'comments_count' => $item->comments->count(),
                    'latest_comment' => optional($item->comments->sortByDesc('created_at')->first())->content ?: 'Non disponible',
                    'latest_automation_result' => $latestResult ? [
                        'status' => $latestResult->status,
                        'error_type' => $latestResult->error_type,
                        'error_message' => $latestResult->error_message ?: 'Non disponible',
                        'executed_at' => optional($latestResult->executed_at)->toIso8601String(),
                        'artifact_counts' => $this->artifactCounts($latestResult->artifacts ?? []),
                    ] : null,
                    'history' => $item->changes->map(fn ($change) => [
                        'changed_at' => optional($change->created_at)->toIso8601String(),
                        'changed_by' => $change->changedBy?->name ?: 'Non disponible',
                        'field_name' => $change->field_name ?: 'Non disponible',
                        'old_value' => $change->old_value ?: 'Non disponible',
                        'new_value' => $change->new_value ?: 'Non disponible',
                        'change_type' => $change->change_type ?: 'Non disponible',
                        'notes' => $change->notes ?: 'Non disponible',
                    ])->values()->all(),
                ];
            }
        }

        return $cases;
    }

    private function buildFailedAndBlocked(Collection $versionItems): array
    {
        return $versionItems
            ->filter(fn (VersionItem $item) => in_array($item->status, ['Failed', 'Blocked'], true))
            ->map(function (VersionItem $item) {
                $latestResult = $item->testResults
                    ->sortByDesc(fn ($result) => optional($result->executed_at)?->timestamp ?? 0)
                    ->first();

                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'status' => $this->displayStatus($item->status),
                    'priority' => $item->priority ?: 'Non disponible',
                    'criticality' => $item->criticality ?: 'Non disponible',
                    'tested_by' => $item->tester?->name ?: 'Non disponible',
                    'tested_at' => optional($item->tested_at)->toIso8601String(),
                    'latest_comment' => optional($item->comments->sortByDesc('created_at')->first())->content ?: 'Non disponible',
                    'latest_error' => $latestResult?->error_message ?: 'Non disponible',
                ];
            })
            ->values()
            ->all();
    }

    private function buildComments(Collection $versionItems): array
    {
        return $versionItems
            ->flatMap(function (VersionItem $item) {
                return $item->comments->map(fn ($comment) => [
                    'id' => $comment->id,
                    'version_item_id' => $item->id,
                    'test_case_title' => $item->title,
                    'author' => $comment->user?->name ?: 'Non disponible',
                    'content' => $comment->content ?: 'Non disponible',
                    'file_name' => $comment->file_name ?: 'Non disponible',
                    'file_path' => $comment->file_path ?: 'Non disponible',
                    'created_at' => optional($comment->created_at)->toIso8601String(),
                ]);
            })
            ->sortByDesc(fn (array $comment) => $comment['created_at'] ?? '')
            ->values()
            ->all();
    }

    private function buildExecutionHistory(Project $project): array
    {
        return $project->versions
            ->flatMap(function ($version) {
                return $version->testRuns->map(fn ($run) => [
                    'run_id' => $run->run_id,
                    'version_id' => $version->id,
                    'version_number' => $version->version_number,
                    'checklist_name' => $version->checklist?->name ?: 'Non disponible',
                    'requested_by' => $run->requester?->name ?: 'Non disponible',
                    'status' => $run->status ?: 'Non disponible',
                    'mode' => $run->mode ?: 'Non disponible',
                    'started_at' => optional($run->started_at)->toIso8601String(),
                    'finished_at' => optional($run->finished_at)->toIso8601String(),
                    'summary' => [
                        'total' => $run->summary_total ?? 0,
                        'passed' => $run->summary_passed ?? 0,
                        'failed' => $run->summary_failed ?? 0,
                        'blocked' => $run->summary_blocked ?? 0,
                        'skipped' => $run->summary_skipped ?? 0,
                    ],
                ]);
            })
            ->sortByDesc(fn (array $run) => $run['finished_at'] ?? $run['started_at'] ?? '')
            ->values()
            ->all();
    }

    private function buildAutomationResults(Project $project): array
    {
        return $project->versions
            ->flatMap(function ($version) {
                return $version->testRuns->flatMap(function ($run) use ($version) {
                    return $run->results->map(fn ($result) => [
                        'run_id' => $run->run_id,
                        'version_number' => $version->version_number,
                        'version_item_id' => $result->version_item_id,
                        'status' => $result->status,
                        'error_type' => $result->error_type ?: 'Non disponible',
                        'error_message' => $result->error_message ?: 'Non disponible',
                        'duration_ms' => $result->duration_ms,
                        'executed_at' => optional($result->executed_at)->toIso8601String(),
                        'artifacts' => [
                            'trace_count' => $this->artifactCounts($result->artifacts ?? [])['trace'],
                            'screenshot_count' => $this->artifactCounts($result->artifacts ?? [])['screenshot'],
                            'video_count' => $this->artifactCounts($result->artifacts ?? [])['video'],
                            'artifact_paths' => $this->artifactPaths($result->artifacts ?? []),
                        ],
                    ]);
                });
            })
            ->sortByDesc(fn (array $result) => $result['executed_at'] ?? '')
            ->values()
            ->all();
    }

    private function buildRecommendations(Project $project, array $summary, array $failedAndBlocked, array $automationResults): array
    {
        $recommendations = [];

        if ($summary['not_tested'] > 0) {
            $recommendations[] = 'Prioriser l\'execution des cas encore non testes pour ameliorer la couverture.';
        }

        if ($summary['failed'] > 0) {
            $recommendations[] = 'Analyser les echecs et ouvrir des actions correctives ciblees sur les cas en anomalie.';
        }

        if ($summary['blocked'] > 0) {
            $recommendations[] = 'Lever les blocages techniques ou environnementaux avant la prochaine campagne.';
        }

        if (count($automationResults) === 0) {
            $recommendations[] = 'Aucun resultat automatique disponible. Verifier si des executions automatisees doivent etre rattachees au projet.';
        }

        if (empty($recommendations)) {
            $recommendations[] = 'Le projet presente un etat d\'execution stable. Maintenir le suivi sur les prochaines versions.';
        }

        if (!empty($failedAndBlocked) && $project->userStories->isNotEmpty()) {
            $recommendations[] = 'Recroiser les tests a risque avec les user stories prioritaires avant la decision finale.';
        }

        return $recommendations;
    }

    private function buildQualityRisks(array $summary, array $failedAndBlocked): array
    {
        return [
            'risk_count' => $summary['risk_count'],
            'risk_level' => match (true) {
                $summary['risk_count'] >= 5 => 'Eleve',
                $summary['risk_count'] >= 1 => 'Modere',
                default => 'Faible',
            },
            'failed_or_blocked_tests' => count($failedAndBlocked),
            'not_tested' => $summary['not_tested'],
        ];
    }

    private function buildScope(Project $project): array
    {
        return [
            'project_name' => $project->name ?: 'Non disponible',
            'versions_included' => $project->versions->pluck('version_number')->values()->all(),
            'user_stories_included' => $project->userStories->count(),
            'generated_from_versions' => $project->versions->count() > 0 ? 'Oui' : 'Non',
        ];
    }

    private function buildCoverage(Project $project, Collection $versionItems): array
    {
        return [
            'user_stories_total' => $project->userStories->count(),
            'checklists_total' => collect($this->buildChecklists($project))->count(),
            'test_cases_total' => $versionItems->count(),
        ];
    }

    private function buildConclusion(array $summary): array
    {
        $decision = 'Go';

        if ($summary['failed'] > 0 || $summary['blocked'] > 0) {
            $decision = 'Go with risks';
        }

        if ($summary['failed'] >= 3 || $summary['pass_rate'] < 60) {
            $decision = 'No Go';
        }

        return [
            'decision' => $decision,
            'statement' => match ($decision) {
                'No Go' => 'Le niveau de risque actuel ne permet pas une validation sereine du projet.',
                'Go with risks' => 'Le projet peut avancer avec une surveillance rapprochee des cas a risque.',
                default => 'Le projet presente des indicateurs compatibles avec une validation favorable.',
            },
        ];
    }

    private function displayStatus(?string $status): string
    {
        return match ($status) {
            'passed' => 'Passed',
            'failed' => 'Failed',
            'blocked' => 'Blocked',
            'Passed', 'Failed', 'Blocked' => $status,
            default => 'Not Tested',
        };
    }

    private function artifactCounts(array $artifacts): array
    {
        return [
            'trace' => count($artifacts['trace'] ?? []),
            'screenshot' => count($artifacts['screenshot'] ?? []),
            'video' => count($artifacts['video'] ?? []),
        ];
    }

    private function artifactPaths(array $artifacts): array
    {
        return collect(['trace', 'screenshot', 'video'])
            ->flatMap(function (string $key) use ($artifacts) {
                return collect($artifacts[$key] ?? [])
                    ->map(fn ($value) => is_array($value) ? ($value['path'] ?? $value['url'] ?? null) : $value)
                    ->filter()
                    ->values();
            })
            ->values()
            ->all();
    }
}
