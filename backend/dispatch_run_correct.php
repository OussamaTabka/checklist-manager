<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\TestRun;
use App\Jobs\ExecuteSingleTestCaseRun;

$item = \App\Models\ChecklistItem::find(279);
$project = $item->checklist->project;

$runId = \Illuminate\Support\Str::uuid()->toString();
$testRun = TestRun::create([
    'run_id' => $runId,
    'checklist_item_id' => 279,
    'base_url' => $project->app_url,
    'status' => 'created',
    'triggered_by' => 'validation',
    'request_payload' => json_encode([
        'test_case_id' => 279,
        'target_type' => 'checklist_item',
    ]),
]);

echo "✓ Created TestRun:\n";
echo "  ID: {$testRun->id}\n";
echo "  Run ID: {$testRun->run_id}\n";
echo "  Base URL: {$testRun->base_url}\n\n";

// Dispatch with run_id, not id!
ExecuteSingleTestCaseRun::dispatch($testRun->run_id);
echo "✓ Job dispatched with run_id: {$testRun->run_id}\n";
echo "  Expected DSL path: storage/app/agent-run-dsl/run-single-{$runId}.json\n";
