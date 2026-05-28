<?php

namespace App\Services;

use App\Models\AgentBenchmarkCase;
use Illuminate\Support\Facades\File;

class AgentBenchmarkDatasetExportService
{
    /**
     * @return array{
     *     dataset_path: string,
     *     report_path: string,
     *     total_examples: int
     * }
     */
    public function export(): array
    {
        $cases = AgentBenchmarkCase::query()
            ->with('checklistItem')
            ->where('enabled', true)
            ->orderBy('source_app')
            ->orderBy('id')
            ->get();

        $datasetPath = storage_path('app/datasets/agent_benchmark_dataset.jsonl');
        $reportPath = storage_path('app/datasets/agent_benchmark_report.json');

        File::ensureDirectoryExists(dirname($datasetPath));

        $handle = fopen($datasetPath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open benchmark dataset file for writing.');
        }

        try {
            foreach ($cases as $case) {
                $item = $case->checklistItem;
                $record = [
                    'input' => [
                        'project_url' => $case->project_url,
                        'title' => $item?->title,
                        'description' => $item?->description,
                        'provided_inputs' => $case->provided_inputs ?? new \stdClass(),
                    ],
                    'output' => [
                        'scenario_type' => $case->expected_scenario_type,
                    ],
                    'expected_result' => $case->expected_result,
                    'metadata' => [
                        'source_app' => $case->source_app,
                        'test_kind' => $case->test_kind,
                        'priority' => $item?->priority,
                        'criticality' => $item?->criticality,
                    ],
                ];

                fwrite($handle, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            }
        } finally {
            fclose($handle);
        }

        $titles = $cases->map(static fn (AgentBenchmarkCase $case) => (string) ($case->checklistItem?->title ?? ''));
        $duplicates = $titles
            ->filter(static fn (string $title) => trim($title) !== '')
            ->countBy()
            ->filter(static fn (int $count) => $count > 1)
            ->sortKeys()
            ->all();

        $missingFields = [
            'title' => $cases->filter(static fn (AgentBenchmarkCase $case) => blank($case->checklistItem?->title))->count(),
            'description' => $cases->filter(static fn (AgentBenchmarkCase $case) => blank($case->checklistItem?->description))->count(),
            'priority' => $cases->filter(static fn (AgentBenchmarkCase $case) => blank($case->checklistItem?->priority))->count(),
            'criticality' => $cases->filter(static fn (AgentBenchmarkCase $case) => blank($case->checklistItem?->criticality))->count(),
            'project_url' => $cases->filter(static fn (AgentBenchmarkCase $case) => blank($case->project_url))->count(),
            'source_app' => $cases->filter(static fn (AgentBenchmarkCase $case) => blank($case->source_app))->count(),
            'expected_scenario_type' => $cases->filter(static fn (AgentBenchmarkCase $case) => blank($case->expected_scenario_type))->count(),
            'expected_result' => $cases->filter(static fn (AgentBenchmarkCase $case) => empty($case->expected_result))->count(),
            'test_kind' => $cases->filter(static fn (AgentBenchmarkCase $case) => blank($case->test_kind))->count(),
        ];

        $report = [
            'total_examples' => $cases->count(),
            'count_per_source_app' => $cases->countBy('source_app')->sortKeys()->all(),
            'count_per_scenario_type' => $cases->countBy('expected_scenario_type')->sortKeys()->all(),
            'count_per_test_kind' => $cases->countBy('test_kind')->sortKeys()->all(),
            'missing_fields_summary' => $missingFields,
            'duplicate_titles_summary' => [
                'duplicate_group_count' => count($duplicates),
                'duplicates' => $duplicates,
            ],
        ];

        file_put_contents(
            $reportPath,
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
        );

        return [
            'dataset_path' => $datasetPath,
            'report_path' => $reportPath,
            'total_examples' => $cases->count(),
        ];
    }
}
