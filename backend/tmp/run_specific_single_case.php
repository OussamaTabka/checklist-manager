<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$runId = $argv[1] ?? null;
if (!$runId) {
    fwrite(STDERR, "Usage: php tmp/run_specific_single_case.php <run_id>\n");
    exit(1);
}

$job = new App\Jobs\ExecuteSingleTestCaseRun($runId);
$job->handle();

echo "processed_run_id={$runId}\n";
