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
]);

echo "Run ID: {$runId}\n";
ExecuteSingleTestCaseRun::dispatch($testRun->id);
echo "Dispatched\n";
