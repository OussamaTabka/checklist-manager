<?php

namespace App\Services;

use App\Models\AgentBenchmarkCase;
use App\Models\TestResult;
use Illuminate\Support\Collection;

class AgentBenchmarkRunService
{
    public function __construct(
        private readonly ChecklistItemRunService $checklistItemRunService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     queued_count: int,
     *     skipped_count: int,
     *     failed_to_start_count: int
     * }
     */
    public function run(array $filters): array
    {
        $dryRun = (bool) ($filters['dry_run'] ?? false);
        $onlyMissing = (bool) ($filters['only_missing'] ?? true);
        $limit = isset($filters['limit']) ? max(1, (int) $filters['limit']) : null;
        $source = isset($filters['source']) ? (string) $filters['source'] : null;
        $caseId = isset($filters['case_id']) ? (int) $filters['case_id'] : null;
        $requestedBy = (int) ($filters['requested_by'] ?? 0);

        $query = AgentBenchmarkCase::query()
            ->with(['project', 'checklist', 'checklistItem'])
            ->where('enabled', true)
            ->orderBy('source_app')
            ->orderBy('id');

        if ($source) {
            $query->where('source_app', $source);
        }

        if ($caseId) {
            $query->where('id', $caseId);
        }

        $cases = $query->get();

        $latestResults = $this->latestResultsForChecklistItems(
            $cases->pluck('checklist_item_id')->filter()->map(static fn ($id) => (int) $id)->all()
        );

        $rows = [];
        $queuedCount = 0;
        $skippedCount = 0;
        $failedToStartCount = 0;
        $selected = 0;

        foreach ($cases as $case) {
            if ($limit !== null && $selected >= $limit) {
                break;
            }

            $row = [
                'benchmark_case_id' => $case->id,
                'source_app' => $case->source_app,
                'checklist_item_id' => $case->checklist_item_id,
                'item_title' => $case->checklistItem?->title ?? 'Unknown item',
                'action' => 'skipped',
                'test_run_id' => null,
            ];

            if (!$case->project || !$case->checklist || !$case->checklistItem) {
                $row['action'] = 'failed_to_start';
                $rows[] = $row;
                $failedToStartCount++;
                $selected++;
                continue;
            }

            if ($onlyMissing && $latestResults->has($case->checklist_item_id)) {
                $rows[] = $row;
                $skippedCount++;
                $selected++;
                continue;
            }

            if ($dryRun) {
                $row['action'] = 'queued';
                $rows[] = $row;
                $queuedCount++;
                $selected++;
                continue;
            }

            try {
                $baseUrl = $this->resolveBenchmarkBaseUrl($case);

                $run = $this->checklistItemRunService->start(
                    $case->checklist,
                    $case->checklistItem,
                    $requestedBy,
                    [
                        'base_url' => $baseUrl,
                        'use_auth' => false,
                        'watch_mode' => true,
                        'environment_name' => 'agent-benchmark',
                        'notes' => 'Seeded benchmark run for benchmark_case_id=' . $case->id,
                        'debug' => (bool) ($filters['debug'] ?? false),
                        'source_app' => $case->source_app,
                        'provided_inputs' => is_array($case->provided_inputs) ? $case->provided_inputs : [],
                        'expected_result' => is_array($case->expected_result) ? $case->expected_result : [],
                    ]
                );

                $row['action'] = 'queued';
                $row['test_run_id'] = $run->run_id;
                $rows[] = $row;
                $queuedCount++;
            } catch (\Throwable) {
                $row['action'] = 'failed_to_start';
                $rows[] = $row;
                $failedToStartCount++;
            }

            $selected++;
        }

        return [
            'rows' => $rows,
            'queued_count' => $queuedCount,
            'skipped_count' => $skippedCount,
            'failed_to_start_count' => $failedToStartCount,
        ];
    }

    private function resolveBenchmarkBaseUrl(AgentBenchmarkCase $case): string
    {
        $targetMode = strtolower((string) env('BENCHMARK_TARGET_MODE', 'external'));

        if ($targetMode === 'local' && $case->source_app === 'sauce_demo') {
            return (string) env('BENCHMARK_LOCAL_SAUCE_URL', 'http://127.0.0.1:4177');
        }

        return $case->project_url ?: (string) $case->project?->app_url;
    }

    /**
     * @param  array<int, int>  $checklistItemIds
     * @return Collection<int, TestResult>
     */
    private function latestResultsForChecklistItems(array $checklistItemIds): Collection
    {
        return TestResult::query()
            ->whereIn('checklist_item_id', $checklistItemIds)
            ->whereNotNull('checklist_item_id')
            ->orderByDesc('executed_at')
            ->orderByDesc('id')
            ->get()
            ->unique('checklist_item_id')
            ->keyBy('checklist_item_id');
    }
}
