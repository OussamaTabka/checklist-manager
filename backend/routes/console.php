<?php

use App\Models\User;
use App\Services\AgentBenchmarkRunService;
use App\Services\AgentBenchmarkDatasetExportService;
use App\Services\AgentBenchmarkEvaluationService;
use App\Services\AgentTrainingDatasetExportService;
use App\Services\StaleTestRunReaper;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Process\Process;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('agent-training:export-dataset', function (AgentTrainingDatasetExportService $exporter) {
    $result = $exporter->export();

    $this->info('Agent training dataset export completed.');
    $this->line(sprintf('Runs found: %d', $result['total_runs_found']));
    $this->line(sprintf('Examples exported: %d', $result['count']));
    $this->line(sprintf('Examples rejected: %d', $result['rejected_count']));
    $this->line(sprintf('Plans with fill: %d', $result['plans_with_fill']));
    $this->line(sprintf('Plans with click: %d', $result['plans_with_click']));
    $this->line(sprintf('Plans with useful asserts: %d', $result['plans_with_useful_asserts']));

    $this->line('Rejection reasons:');
    if ($result['rejection_reasons'] === []) {
        $this->line('- none');
    } else {
        foreach ($result['rejection_reasons'] as $reason => $count) {
            $this->line(sprintf('- %s: %d', $reason, $count));
        }
    }

    $this->info(sprintf('Output: %s', $result['path']));
})->purpose('Export a JSONL dataset for future Playwright run_spec training');

Artisan::command('agent:evaluate-benchmark', function (AgentBenchmarkEvaluationService $evaluator) {
    $result = $evaluator->evaluate();

    $this->table(
        ['source_app', 'item title', 'expected_scenario_type', 'predicted_scenario_type', 'expected_status', 'actual_status', 'status_match', 'final_evaluation'],
        collect($result['cases'])->map(static function (array $row): array {
            return [
                $row['source_app'],
                $row['item_title'],
                $row['expected_scenario_type'],
                $row['predicted_scenario_type'] ?? 'n/a',
                $row['expected_status'] ?? 'n/a',
                $row['actual_status'] ?? 'missing',
                match ($row['status_match']) {
                    true => 'yes',
                    false => 'no',
                    default => 'n/a',
                },
                $row['final_evaluation'],
            ];
        })->all()
    );

    $this->newLine();
    $this->info('Global metrics');
    foreach ($result['metrics'] as $key => $value) {
        $display = is_float($value) ? number_format($value, 2) . '%' : (string) $value;
        $this->line(sprintf('- %s: %s', $key, $display));
    }
})->purpose('Evaluate enabled agent benchmark cases against the latest real test results');

Artisan::command('agent:export-benchmark-dataset', function (AgentBenchmarkDatasetExportService $exporter) {
    $result = $exporter->export();

    $this->info('Agent benchmark dataset export completed.');
    $this->line(sprintf('Examples exported: %d', $result['total_examples']));
    $this->line(sprintf('Dataset: %s', $result['dataset_path']));
    $this->line(sprintf('Report: %s', $result['report_path']));
})->purpose('Export the seeded benchmark checklist corpus as JSONL plus summary report');

Artisan::command('agent:run-benchmark {--source=} {--limit=} {--case-id=} {--dry-run} {--only-missing=1} {--debug}', function (AgentBenchmarkRunService $runner) {
    $onlyMissing = filter_var((string) $this->option('only-missing'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    if ($onlyMissing === null) {
        $onlyMissing = true;
    }

    $requestedBy = User::query()
        ->where('email', 'benchmark.tester@example.com')
        ->orWhere('email', 'testeur@test.com')
        ->value('id');

    if (!$requestedBy) {
        $requestedBy = User::query()->orderBy('id')->value('id');
    }

    $result = $runner->run([
        'source' => $this->option('source'),
        'limit' => $this->option('limit'),
        'case_id' => $this->option('case-id'),
        'dry_run' => (bool) $this->option('dry-run'),
        'only_missing' => $onlyMissing,
        'debug' => (bool) $this->option('debug'),
        'requested_by' => (int) $requestedBy,
    ]);

    $this->table(
        ['benchmark_case_id', 'source_app', 'checklist_item_id', 'item title', 'action', 'test_run_id'],
        collect($result['rows'])->map(static function (array $row): array {
            return [
                $row['benchmark_case_id'],
                $row['source_app'],
                $row['checklist_item_id'],
                $row['item_title'],
                $row['action'],
                $row['test_run_id'] ?? '',
            ];
        })->all()
    );

    $this->newLine();
    $this->line(sprintf('queued_count: %d', $result['queued_count']));
    $this->line(sprintf('skipped_count: %d', $result['skipped_count']));
    $this->line(sprintf('failed_to_start_count: %d', $result['failed_to_start_count']));
})->purpose('Queue real automatic benchmark runs using the existing checklist item execution flow');

Artisan::command('runs:reap {--minutes=20 : Age in minutes after which a still-running run is treated as timed out}', function (StaleTestRunReaper $reaper) {
    $minutes = (int) $this->option('minutes');
    if ($minutes < 1) {
        $minutes = 20;
    }

    $result = $reaper->reap($minutes);

    $this->info(sprintf('Reaped %d stale test run(s) older than %d minute(s).', $result['reaped'], $minutes));
    foreach ($result['runs'] as $line) {
        $this->line('- ' . $line);
    }

    return 0;
})->purpose('Mark test runs stuck in created/running past a timeout as blocked (timed out)');

// Safety net for runs whose web-process job was killed before reporting a
// result. The threshold (20min) stays above the runner's own Symfony timeout
// (<=15min) so genuinely in-progress runs are never reaped.
Schedule::command('runs:reap')->everyMinute()->withoutOverlapping();

Artisan::command('agent:diagnose-target-url {url}', function (string $url) {
    $workspaceRoot = realpath(base_path('..'));
    if (!$workspaceRoot) {
        $this->error('Unable to resolve workspace root.');
        return 1;
    }

    $runnerDir = $workspaceRoot . DIRECTORY_SEPARATOR . 'playwright-runner-job';
    $scriptPath = $runnerDir . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'diagnose-url.cjs';

    $process = new Process(
        ['node', $scriptPath, $url],
        $runnerDir,
        array_merge($_ENV, $_SERVER, ['PATH' => getenv('PATH') ?: getenv('Path') ?: ''])
    );

    $process->setTimeout(60);
    $process->run();

    $payload = json_decode(trim($process->getOutput()), true);
    if (!is_array($payload)) {
        $this->error('Diagnostic script did not return valid JSON.');
        $this->line(trim($process->getErrorOutput()));
        return 1;
    }

    $this->table(
        ['url', 'reachable', 'status', 'title', 'error_type'],
        [[
            $payload['url'] ?? $url,
            ($payload['reachable'] ?? false) ? 'yes' : 'no',
            $payload['status'] ?? '',
            $payload['title'] ?? '',
            $payload['error_type'] ?? '',
        ]]
    );

    $this->line('raw_error: ' . (string) ($payload['error_message'] ?? ''));

    return ($payload['reachable'] ?? false) ? 0 : 1;
})->purpose('Diagnose whether Playwright can reach a target URL using the local runner environment');
