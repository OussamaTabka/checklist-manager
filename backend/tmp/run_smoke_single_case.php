<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$item = \App\Models\VersionItem::query()->orderBy('id')->firstOrFail();
$runId = (string) \Illuminate\Support\Str::uuid();

\App\Models\TestRun::create([
    'run_id' => $runId,
    'project_version_id' => $item->project_version_id,
    'schema_version' => '1.0',
    'base_url' => 'http://host.docker.internal:5174',
    'mode' => 'single-test-case',
    'status' => 'created',
    'requested_by' => null,
    'summary_total' => 1,
    'request_payload' => [
        'schema_version' => '1.0',
        'test_case_id' => $item->id,
        'test_case_title' => $item->title,
        'test_case_text' => trim(($item->title ?? '') . "\n" . ($item->description ?? '')),
        'base_url' => 'http://host.docker.internal:5174',
        'use_auth' => true,
        'environment_name' => '',
        'notes' => '',
    ],
]);

(new \App\Jobs\ExecuteSingleTestCaseRun($runId))->handle();

$run = \App\Models\TestRun::query()->where('run_id', $runId)->first();

$result = [
    'run_id' => $runId,
    'status' => $run?->status,
    'summary_total' => $run?->summary_total,
    'summary_passed' => $run?->summary_passed,
    'summary_failed' => $run?->summary_failed,
    'summary_blocked' => $run?->summary_blocked,
    'summary_skipped' => $run?->summary_skipped,
    'last_error' => $run?->request_payload['last_error'] ?? null,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
