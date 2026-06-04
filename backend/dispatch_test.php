<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\TestRun;
use App\Jobs\ExecuteSingleTestCaseRun;

// Get the project URL
$item = \App\Models\ChecklistItem::find(279);
$project = $item->checklist->project;

$runId = \Illuminate\Support\Str::uuid()->toString();
$testRun = TestRun::create([
    'run_id' => $runId,
    'checklist_item_id' => 279,
    'base_url' => $project->app_url,
    'status' => 'created',
    'triggered_by' => 'validation',
    'requested_by' => 1,  // Admin Système user
]);

echo "✓ Created TestRun:\n";
echo "  ID: {$testRun->id}\n";
echo "  Run ID: {$testRun->run_id}\n";
echo "  Item ID: {$testRun->checklist_item_id}\n";
echo "  Base URL: {$testRun->base_url}\n";
echo "  Status: {$testRun->status}\n\n";

// Dispatch the job
ExecuteSingleTestCaseRun::dispatch($testRun->id);
echo "✓ Job dispatched successfully!\n\n";

// Show queue status
$jobs = \Illuminate\Support\Facades\DB::table('jobs')->count();
echo "✓ Jobs in database queue: $jobs\n";
