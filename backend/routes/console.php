<?php

use App\Services\AgentTrainingDatasetExportService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

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
