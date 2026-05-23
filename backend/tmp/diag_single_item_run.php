<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$itemId = 28;
$run = App\Models\TestRun::where('request_payload->test_case_id', $itemId)->latest('id')->first();

if (!$run) {
    echo "no-run\n";
    exit(0);
}

echo 'run_db_id=' . $run->id . PHP_EOL;
echo 'run_id=' . $run->run_id . PHP_EOL;
echo 'status=' . $run->status . PHP_EOL;
echo 'summary=' . json_encode([
    'total' => $run->summary_total,
    'passed' => $run->summary_passed,
    'failed' => $run->summary_failed,
    'blocked' => $run->summary_blocked,
    'skipped' => $run->summary_skipped,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;

$payload = is_array($run->request_payload) ? $run->request_payload : [];
echo 'request_payload=' . json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;

$result = App\Models\TestResult::where('test_run_id', $run->id)
    ->where('version_item_id', $itemId)
    ->first();

echo 'result_status=' . ($result ? $result->status : 'none') . PHP_EOL;
echo 'result_error=' . ($result ? (string) $result->error_message : '') . PHP_EOL;
echo 'result_payload=' . json_encode($result?->result_payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;

echo 'recent_runs=' . PHP_EOL;
$recentRuns = App\Models\TestRun::where('request_payload->test_case_id', $itemId)
    ->latest('id')
    ->limit(5)
    ->get();

foreach ($recentRuns as $recentRun) {
    $recentResult = App\Models\TestResult::where('test_run_id', $recentRun->id)
        ->where('version_item_id', $itemId)
        ->first();

    echo '- run_id=' . $recentRun->run_id
        . ' status=' . $recentRun->status
        . ' summary=' . json_encode([
            'p' => $recentRun->summary_passed,
            'f' => $recentRun->summary_failed,
            'b' => $recentRun->summary_blocked,
            's' => $recentRun->summary_skipped,
        ], JSON_UNESCAPED_SLASHES)
        . ' result_status=' . ($recentResult?->status ?? 'none')
        . ' result_error=' . (string) ($recentResult?->error_message ?? '')
        . PHP_EOL;
}
