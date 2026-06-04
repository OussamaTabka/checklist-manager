<?php

namespace App\Jobs;

use App\Models\ChecklistItem;
use App\Models\ChecklistItemHistory;
use App\Models\ItemChange;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\VersionItem;
use App\Services\ExecutionInputNormalizer;
use App\Services\ExecutionProfileService;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class ExecuteSingleTestCaseRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly string $runId)
    {
    }

    private function inputNormalizer(): ExecutionInputNormalizer
    {
        return app(ExecutionInputNormalizer::class);
    }

    public function handle(): void
    {
        $run = TestRun::where('run_id', $this->runId)->first();
        if (!$run) {
            return;
        }

        // Ensure requested_by is set (default to system admin if null)
        if (!$run->requested_by) {
            $run->update(['requested_by' => 1]);
        }

        $payload = is_array($run->request_payload) ? $run->request_payload : [];
        $testCaseId = (int) ($payload['test_case_id'] ?? 0);
        $targetType = (string) ($payload['target_type'] ?? 'version_item');
        $item = $targetType === 'checklist_item'
            ? ChecklistItem::find($testCaseId)
            : VersionItem::find($testCaseId);

        if (!$item) {
            $this->markBlocked($run, null, 'test_case_not_found', 'Selected test case could not be found.');
            return;
        }

        $run->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        $environmentName = trim((string) ($payload['environment_name'] ?? ''));
        $notes = trim((string) ($payload['notes'] ?? ''));
        $priority = trim((string) ($payload['priority'] ?? ($item->priority ?? '')));
        $criticality = trim((string) ($payload['criticality'] ?? ($item->criticality ?? '')));
        $currentStatus = trim((string) ($payload['current_status'] ?? ($item->status ?? '')));
        $projectVersionId = $item instanceof VersionItem
            ? (int) ($payload['project_version_id'] ?? $item->project_version_id)
            : null;
        $watchMode = (bool) ($payload['watch_mode'] ?? true);
        $debugMode = (bool) ($payload['debug'] ?? false);
        $providedInputs = $this->inputNormalizer()->normalizeProvidedInputs(
            is_array($payload['provided_inputs'] ?? null) ? $payload['provided_inputs'] : []
        );

        try {
            $workspaceRoot = realpath(base_path('..'));
            if (!$workspaceRoot) {
                throw new \RuntimeException('Unable to resolve workspace root from backend path.');
            }

            $orchestratorDir = $workspaceRoot . DIRECTORY_SEPARATOR . 'playwright-orchestrator';
            $runnerDir = $workspaceRoot . DIRECTORY_SEPARATOR . 'playwright-runner-job';
            $runsRoot = storage_path('app' . DIRECTORY_SEPARATOR . 'agent-runs');

            $orchestratorEntrypoint = $orchestratorDir . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'fromDsl.js';
            $runnerEntrypoint = $runnerDir . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'main.js';

            if ($watchMode) {
                if (!is_file($runnerEntrypoint)) {
                    throw new \RuntimeException('Missing built runner entrypoint dist/main.js. Run npm run build in playwright-runner-job.');
                }
            } else {
                if (!is_file($orchestratorEntrypoint)) {
                    throw new \RuntimeException('Missing built orchestrator entrypoint dist/cli/fromDsl.js. Run npm run build in playwright-orchestrator.');
                }
            }

            $dslPath = storage_path('app' . DIRECTORY_SEPARATOR . 'agent-run-dsl' . DIRECTORY_SEPARATOR . 'run-single-' . $run->run_id . '.json');

            $this->ensureDirectory(dirname($dslPath));

            $planner = app(ExecutionProfileService::class);

            try {
                $generated = $item instanceof VersionItem
                    ? $planner->generateForVersionItem($item, $run->base_url, [
                        'run_id' => $run->run_id,
                        'use_auth' => (bool) ($payload['use_auth'] ?? true),
                        'environment_name' => $environmentName,
                        'notes' => $notes,
                        'priority' => $priority,
                        'criticality' => $criticality,
                        'current_status' => $currentStatus,
                        'project_version_id' => $projectVersionId,
                        'target_type' => $targetType,
                        'source_app' => isset($payload['source_app']) ? (string) $payload['source_app'] : '',
                        'provided_inputs' => $providedInputs,
                        'expected_result' => is_array($payload['expected_result'] ?? null) ? $payload['expected_result'] : [],
                    ])
                    : $planner->generateForChecklistItem($item, $run->base_url, [
                        'run_id' => $run->run_id,
                        'use_auth' => (bool) ($payload['use_auth'] ?? true),
                        'environment_name' => $environmentName,
                        'notes' => $notes,
                        'priority' => $priority,
                        'criticality' => $criticality,
                        'current_status' => $currentStatus,
                        'target_type' => $targetType,
                        'source_app' => isset($payload['source_app']) ? (string) $payload['source_app'] : '',
                        'provided_inputs' => $providedInputs,
                        'expected_result' => is_array($payload['expected_result'] ?? null) ? $payload['expected_result'] : [],
                    ]);
            } catch (\Throwable $error) {
                $this->markBlocked(
                    $run->fresh(),
                    $item->fresh(),
                    'script_generation_failed',
                    $this->buildScriptGenerationErrorMessage($error),
                );
                return;
            }

            $runSpec = $generated['run_spec'];

            // Enforce single-case payload and id coupling before handing to orchestrator.
            $runSpec['run_id'] = $run->run_id;
            $runSpec['target']['base_url'] = rtrim($run->base_url, '/');
            $runSpec['cases'][0]['external_id'] = $item->id;
            $runSpec['cases'][0]['use_auth'] = (bool) ($payload['use_auth'] ?? true);
            $runSpec['cases'][0]['execution_profile'] = $this->mergeProvidedInputsIntoExecutionProfile(
                is_array($runSpec['cases'][0]['execution_profile'] ?? null) ? $runSpec['cases'][0]['execution_profile'] : [],
                $providedInputs,
                $payload,
            );
            $runSpec['cases'][0]['generated_plan'] = is_array($runSpec['cases'][0]['generated_plan'] ?? null)
                ? $runSpec['cases'][0]['generated_plan']
                : ($runSpec['cases'][0]['execution_profile']['last_generated_plan'] ?? null);

            $item->update([
                'execution_profile' => $runSpec['cases'][0]['execution_profile'],
            ]);

            file_put_contents($dslPath, json_encode($runSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            $resultPath = $runsRoot . DIRECTORY_SEPARATOR . $run->run_id . DIRECTORY_SEPARATOR . 'result.json';

            if ($watchMode) {
                $this->ensureDirectory(dirname($resultPath));

                $localOutcome = $this->runCommandWithExitCode(
                    ['node', $runnerEntrypoint],
                    $runnerDir,
                    [
                        'RUN_JSON_PATH' => $dslPath,
                        'RESULT_JSON_PATH' => $resultPath,
                        'ARTIFACTS_DIR' => dirname($resultPath) . DIRECTORY_SEPARATOR . 'artifacts',
                        'LIVE_TRACE_PATH' => dirname($resultPath) . DIRECTORY_SEPARATOR . 'live-trace.json',
                        'AGENT_RUNNER_DEBUG' => $debugMode ? '1' : '0',
                    ],
                    900,
                );

                if (($localOutcome['exitCode'] ?? 0) !== 0) {
                    Log::warning('local runner returned non-zero exit code in watch mode', [
                        'run_id' => $run->run_id,
                        'exit_code' => $localOutcome['exitCode'],
                        'stderr' => $localOutcome['errorOutput'],
                    ]);
                }

                if (!is_file($resultPath)) {
                    $details = [];
                    if (($localOutcome['exitCode'] ?? 0) !== 0) {
                        $details[] = 'local runner exit code: ' . (string) $localOutcome['exitCode'];
                    }

                    $stderr = trim((string) ($localOutcome['errorOutput'] ?? ''));
                    if ($stderr !== '') {
                        $details[] = 'stderr: ' . $stderr;
                    }

                    $stdout = trim((string) ($localOutcome['output'] ?? ''));
                    if ($stdout !== '' && ($localOutcome['exitCode'] ?? 0) !== 0) {
                        $details[] = 'stdout: ' . $stdout;
                    }

                    $message = 'result.json was not generated by local runner.';
                    if ($details !== []) {
                        $message .= ' ' . implode(' | ', $details);
                    }

                    throw new \RuntimeException($message);
                }
            } else {
                $fromDslOutcome = $this->runCommandWithExitCode(
                    ['node', $orchestratorEntrypoint, $dslPath],
                    $orchestratorDir,
                    [
                        'BASE_URL' => rtrim($run->base_url, '/'),
                        'RUNS_ROOT' => $runsRoot,
                        'ENABLE_LARAVEL' => 'false',
                        'ENABLE_CREATE_RUN' => 'false',
                        'ENABLE_PUBLISH_RESULTS' => 'false',
                        'ENABLE_BATCH_RESULTS' => 'false',
                        'ENABLE_FALLBACK_PATCH' => 'false',
                    ],
                    600,
                );

                if ($fromDslOutcome['exitCode'] !== 0) {
                    Log::warning('from-dsl returned non-zero exit code', [
                        'run_id' => $run->run_id,
                        'exit_code' => $fromDslOutcome['exitCode'],
                        'stderr' => $fromDslOutcome['errorOutput'],
                    ]);
                }

                if (!is_file($resultPath)) {
                    $details = [];
                    if (($fromDslOutcome['exitCode'] ?? 0) !== 0) {
                        $details[] = 'from-dsl exit code: ' . (string) $fromDslOutcome['exitCode'];
                    }

                    $stderr = trim((string) ($fromDslOutcome['errorOutput'] ?? ''));
                    if ($stderr !== '') {
                        $details[] = 'stderr: ' . $stderr;
                    }

                    $stdout = trim((string) ($fromDslOutcome['output'] ?? ''));
                    if ($stdout !== '' && ($fromDslOutcome['exitCode'] ?? 0) !== 0) {
                        $details[] = 'stdout: ' . $stdout;
                    }

                    $message = 'result.json was not generated by orchestrator/runner.';
                    if ($details !== []) {
                        $message .= ' ' . implode(' | ', $details);
                    }

                    throw new \RuntimeException($message);
                }
            }

            $resultPayload = json_decode((string) file_get_contents($resultPath), true);
            if (!is_array($resultPayload)) {
                throw new \RuntimeException('result.json is not valid JSON.');
            }

            if (!$watchMode && $this->isDockerInfraErrorResult($resultPayload)) {
                $localFallbackSucceeded = $this->runLocalRunnerFallback(
                    $workspaceRoot,
                    $dslPath,
                    $resultPath,
                    $run->run_id,
                );

                if ($localFallbackSucceeded) {
                    $localResultPayload = json_decode((string) file_get_contents($resultPath), true);
                    if (is_array($localResultPayload)) {
                        $resultPayload = $localResultPayload;
                    }
                }
            }

            $this->persistResult($run->fresh(), $item->fresh(), $resultPayload);
        } catch (\Throwable $error) {
            $safeMessage = $this->sanitizeUtf8($error->getMessage());

            Log::error('Single test-case run failed', [
                'run_id' => $run->run_id,
                'test_case_id' => $item->id,
                'error' => $safeMessage,
            ]);

            $this->markBlocked($run->fresh(), $item->fresh(), 'infra_error', $safeMessage);
        }
    }

    private function persistResult(TestRun $run, VersionItem|ChecklistItem $item, array $resultPayload): void
    {
        $summary = is_array($resultPayload['summary'] ?? null) ? $resultPayload['summary'] : [];
        $results = is_array($resultPayload['results'] ?? null) ? $resultPayload['results'] : [];
        $runnerStatus = (string) ($resultPayload['status'] ?? 'unknown');

        if ($results === [] && $runnerStatus !== 'done') {
            $this->markBlocked(
                $run,
                $item,
                'runner_error',
                sprintf('Runner exited with status "%s" before producing case results.', $runnerStatus),
            );
            return;
        }

        $runPayload = is_array($run->request_payload) ? $run->request_payload : [];
        $caseResult = $this->resolveCaseResult($results, $item, $runPayload);

        if (!$caseResult) {
            $this->markBlocked(
                $run,
                $item,
                'missing_case_result',
                'Runner result did not include the requested test case.',
            );
            return;
        }

        $resultStatus = (string) ($caseResult['status'] ?? 'blocked');
        $mappedItemStatus = $item instanceof ChecklistItem
            ? match ($resultStatus) {
                'passed' => 'passed',
                'failed' => 'failed',
                'blocked' => 'blocked',
                'skipped' => 'pending',
                default => 'blocked',
            }
            : match ($resultStatus) {
            'passed' => 'Passed',
            'failed' => 'Failed',
            'blocked' => 'Blocked',
            'skipped' => 'Not Tested',
            default => 'Blocked',
        };

        $artifacts = is_array($caseResult['artifacts'] ?? null) ? $caseResult['artifacts'] : [];
        $artifactPayload = [
            'trace' => isset($artifacts['trace_path']) && is_string($artifacts['trace_path']) ? [$artifacts['trace_path']] : [],
            'screenshot' => isset($artifacts['screenshot_path']) && is_string($artifacts['screenshot_path']) ? [$artifacts['screenshot_path']] : [],
            'video' => isset($artifacts['video_path']) && is_string($artifacts['video_path']) ? [$artifacts['video_path']] : [],
            'raw_paths' => $artifacts,
        ];

        DB::transaction(function () use ($run, $item, $summary, $caseResult, $mappedItemStatus, $artifactPayload, $resultStatus) {
            $oldStatus = $item->status;
            $this->updateItemStatusFromRun($item, $run->requested_by, $mappedItemStatus, 'Updated from single test-case run ' . $run->run_id, $oldStatus);

            TestResult::updateOrCreate(
                $this->resolveTestResultIdentity($run->id, $item),
                [
                    'status' => $resultStatus,
                    'error_type' => isset($caseResult['error_type']) && is_string($caseResult['error_type']) ? $caseResult['error_type'] : null,
                    'error_message' => isset($caseResult['error_message']) && is_string($caseResult['error_message']) ? $caseResult['error_message'] : null,
                    'duration_ms' => isset($caseResult['duration_ms']) ? (int) $caseResult['duration_ms'] : null,
                    'artifacts' => $artifactPayload,
                    'result_payload' => $caseResult,
                    'executed_at' => now(),
                ],
            );

            $run->update([
                'status' => 'completed',
                'finished_at' => now(),
                'summary_total' => (int) ($summary['total'] ?? 1),
                'summary_passed' => (int) ($summary['passed'] ?? 0),
                'summary_failed' => (int) ($summary['failed'] ?? 0),
                'summary_blocked' => (int) ($summary['blocked'] ?? 0),
                'summary_skipped' => (int) ($summary['skipped'] ?? 0),
            ]);

            if ($item instanceof ChecklistItem) {
                $this->logChecklistExecutionEvent(
                    $item,
                    $run->requested_by,
                    'automated_test_finished',
                    'running',
                    $this->displayChecklistStatus($mappedItemStatus),
                    $this->buildAutomatedResultNotes($run->run_id, $resultStatus, $caseResult),
                );
            }
        });

        app(NotificationService::class)->notifyAutomatedExecutionCompleted(
            $run->fresh([
                'requester.roles',
                'projectVersion.project.creator.roles',
                'projectVersion.project.testers.roles',
            ])
        );
    }

    private function resolveCaseResult(array $results, VersionItem|ChecklistItem $item, array $runPayload): ?array
    {
        $entries = array_values(array_filter($results, static fn ($entry) => is_array($entry)));
        if ($entries === []) {
            return null;
        }

        $requestedId = (string) $item->id;
        $payloadTestCaseId = isset($runPayload['test_case_id']) ? (string) $runPayload['test_case_id'] : '';

        // First pass: exact id match when runner included external_id.
        foreach ($entries as $entry) {
            if (!array_key_exists('external_id', $entry)) {
                continue;
            }

            $externalId = (string) $entry['external_id'];
            if ($externalId === $requestedId || ($payloadTestCaseId !== '' && $externalId === $payloadTestCaseId)) {
                return $entry;
            }
        }

        $expectedTitle = mb_strtolower(trim((string) ($runPayload['test_case_title'] ?? $item->title)));
        if ($expectedTitle !== '') {
            foreach ($entries as $entry) {
                $entryTitle = mb_strtolower(trim((string) ($entry['title'] ?? '')));
                if ($entryTitle !== '' && $entryTitle === $expectedTitle) {
                    return $entry;
                }
            }
        }

        // Single-case job safety net: if only one result exists, it is the requested case.
        if (count($entries) === 1) {
            return $entries[0];
        }

        return null;
    }

    private function markBlocked(TestRun $run, VersionItem|ChecklistItem|null $item, string $errorType, string $message): void
    {
        $safeMessage = $this->sanitizeUtf8($message);

        DB::transaction(function () use ($run, $item, $errorType, $safeMessage) {
            $payload = is_array($run->request_payload) ? $run->request_payload : [];
            $payload['last_error'] = $safeMessage;

            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'summary_total' => 1,
                'summary_passed' => 0,
                'summary_failed' => 0,
                'summary_blocked' => 1,
                'summary_skipped' => 0,
                'request_payload' => $payload,
            ]);

            if (!$item) {
                return;
            }

            $this->updateItemStatusFromRun($item, $run->requested_by, 'Blocked', 'Infra error during run ' . $run->run_id, $item->status);

            TestResult::updateOrCreate(
                $this->resolveTestResultIdentity($run->id, $item),
                [
                    'status' => 'blocked',
                    'error_type' => $errorType,
                    'error_message' => $safeMessage,
                    'duration_ms' => null,
                    'artifacts' => [
                        'trace' => [],
                        'screenshot' => [],
                        'video' => [],
                    ],
                    'result_payload' => [
                        'external_id' => $item->id,
                        'status' => 'blocked',
                        'error_type' => $errorType,
                        'error_message' => $safeMessage,
                    ],
                    'executed_at' => now(),
                ],
            );

            if ($item instanceof ChecklistItem) {
                $this->logChecklistExecutionEvent(
                    $item,
                    $run->requested_by,
                    'automated_test_finished',
                    'running',
                    'Blocked',
                    'Automated test failed for run ' . $run->run_id . '. Result: blocked. Reason: ' . $safeMessage,
                );
            }
        });

        app(NotificationService::class)->notifySystemExecutionError($run->fresh(['projectVersion.project']), $errorType, $safeMessage);
    }

    private function resolveTestResultIdentity(int $runId, VersionItem|ChecklistItem $item): array
    {
        return $item instanceof VersionItem
            ? ['test_run_id' => $runId, 'version_item_id' => $item->id]
            : ['test_run_id' => $runId, 'checklist_item_id' => $item->id];
    }

    private function updateItemStatusFromRun(
        VersionItem|ChecklistItem $item,
        ?int $requestedBy,
        string $newStatus,
        string $notes,
        ?string $oldStatus = null,
    ): void {
        $oldStatus = $oldStatus ?? (string) $item->status;
        if ($item instanceof ChecklistItem && !in_array($oldStatus, ['Passed', 'Failed', 'Blocked', 'Not Tested'], true)) {
            $oldStatus = 'Not Tested';
        }

        $storedStatus = $item instanceof ChecklistItem
            ? match ($newStatus) {
                'Passed', 'passed' => 'passed',
                'Failed', 'failed' => 'failed',
                'Blocked', 'blocked' => 'blocked',
                default => 'pending',
            }
            : $newStatus;
        $displayNewStatus = $item instanceof ChecklistItem
            ? match ($storedStatus) {
                'passed' => 'Passed',
                'failed' => 'Failed',
                'blocked' => 'Blocked',
                default => 'Not Tested',
            }
            : $newStatus;

        $item->update([
            'status' => $storedStatus,
            'tested_by' => $displayNewStatus === 'Not Tested' ? null : $requestedBy,
            'tested_at' => $displayNewStatus === 'Not Tested' ? null : now(),
        ]);

        if ($oldStatus === $displayNewStatus) {
            return;
        }

        if ($item instanceof VersionItem) {
            ItemChange::create([
                'version_item_id' => $item->id,
                'changed_by' => $requestedBy,
                'field_name' => 'status',
                'old_value' => $oldStatus,
                'new_value' => $displayNewStatus,
                'change_type' => 'status_changed',
                'notes' => $notes,
            ]);
            return;
        }

        ChecklistItemHistory::create([
            'checklist_item_id' => $item->id,
            'changed_by' => $requestedBy,
            'field_name' => 'status',
            'old_value' => $oldStatus,
            'new_value' => $displayNewStatus,
            'change_type' => 'status_changed',
            'notes' => $notes,
        ]);
    }

    private function logChecklistExecutionEvent(
        ChecklistItem $item,
        ?int $requestedBy,
        string $changeType,
        ?string $oldValue,
        ?string $newValue,
        ?string $notes,
    ): void {
        ChecklistItemHistory::create([
            'checklist_item_id' => $item->id,
            'changed_by' => $requestedBy,
            'field_name' => 'execution',
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'change_type' => $changeType,
            'notes' => $notes,
        ]);
    }

    private function displayChecklistStatus(string $status): string
    {
        return match ($status) {
            'passed', 'Passed' => 'Passed',
            'failed', 'Failed' => 'Failed',
            'blocked', 'Blocked' => 'Blocked',
            default => 'Not Tested',
        };
    }

    private function buildAutomatedResultNotes(string $runId, string $resultStatus, array $caseResult): string
    {
        $details = ['Automated test completed for run ' . $runId . '. Result: ' . $resultStatus . '.'];

        $errorMessage = trim((string) ($caseResult['error_message'] ?? ''));
        if ($errorMessage !== '') {
            $details[] = 'Details: ' . $errorMessage;
        }

        return implode(' ', $details);
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new \RuntimeException('Unable to create directory: ' . $path);
        }
    }

    private function runCommand(
        array $command,
        string $workingDirectory,
        array $extraEnv = [],
        int $timeoutSeconds = 300,
    ): string {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS') && strtolower((string) ($command[0] ?? '')) === 'npm') {
            $command[0] = 'npm.cmd';
        }

        $process = new Process($command, $workingDirectory, $this->buildProcessEnvironment($extraEnv));
        $process->setTimeout($timeoutSeconds);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process->getOutput();
    }

    /**
     * @return array{exitCode:int,output:string,errorOutput:string}
     */
    private function runCommandWithExitCode(
        array $command,
        string $workingDirectory,
        array $extraEnv = [],
        int $timeoutSeconds = 300,
    ): array {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS') && strtolower((string) ($command[0] ?? '')) === 'npm') {
            $command[0] = 'npm.cmd';
        }

        $process = new Process($command, $workingDirectory, $this->buildProcessEnvironment($extraEnv));
        $process->setTimeout($timeoutSeconds);
        $process->run();

            return [
                'exitCode' => $process->getExitCode() ?? 1,
                'output' => $process->getOutput(),
                'errorOutput' => $process->getErrorOutput(),
            ];
    }

    private function sanitizeUtf8(string $message): string
    {
        $normalized = @iconv('UTF-8', 'UTF-8//IGNORE', $message);
        if ($normalized !== false) {
            return $normalized;
        }

        return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $message) ?? $message;
    }

    private function buildScriptGenerationErrorMessage(\Throwable $error): string
    {
        $message = trim($this->sanitizeUtf8($error->getMessage()));
        if ($message === '') {
            return 'The Playwright script could not be generated for this test case.';
        }

        return 'The Playwright script could not be generated for this test case. Details: ' . $message;
    }

    private function isDockerInfraErrorResult(array $resultPayload): bool
    {
        if (($resultPayload['status'] ?? null) !== 'runner_error') {
            return false;
        }

        $results = is_array($resultPayload['results'] ?? null) ? $resultPayload['results'] : [];
        foreach ($results as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $errorType = (string) ($entry['error_type'] ?? '');
            $errorMessage = (string) ($entry['error_message'] ?? '');

            if ($errorType !== 'infra_error') {
                continue;
            }

            if (str_contains($errorMessage, 'docker_exit_code_') || str_contains($errorMessage, 'docker_failed:')) {
                return true;
            }
        }

        return false;
    }

    private function runLocalRunnerFallback(
        string $workspaceRoot,
        string $dslPath,
        string $resultPath,
        string $runId,
    ): bool {
        $runnerDir = $workspaceRoot . DIRECTORY_SEPARATOR . 'playwright-runner-job';
        $runnerEntrypoint = $runnerDir . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'main.js';

        if (!is_file($runnerEntrypoint)) {
            Log::warning('Local runner fallback skipped: missing dist/main.js', [
                'run_id' => $runId,
            ]);
            return false;
        }

        $artifactsDir = dirname($resultPath) . DIRECTORY_SEPARATOR . 'artifacts';
        $this->ensureDirectory($artifactsDir);

        $outcome = $this->runCommandWithExitCode(
            ['node', $runnerEntrypoint],
            $runnerDir,
            [
                'RUN_JSON_PATH' => $dslPath,
                'RESULT_JSON_PATH' => $resultPath,
                'ARTIFACTS_DIR' => $artifactsDir,
                'LIVE_TRACE_PATH' => dirname($resultPath) . DIRECTORY_SEPARATOR . 'live-trace.json',
            ],
            900,
        );

        Log::info('Local runner fallback attempted', [
            'run_id' => $runId,
            'exit_code' => $outcome['exitCode'],
        ]);

        return is_file($resultPath);
    }

    private function buildProcessEnvironment(array $extraEnv = []): array
    {
        $environment = [];

        $systemEnvironment = getenv();
        if (is_array($systemEnvironment)) {
            $environment = $systemEnvironment;
        }

        $environment = array_merge($environment, $_SERVER, $_ENV);

        $path = getenv('PATH');
        if (!$path) {
            $path = getenv('Path');
        }

        if (is_string($path) && $path !== '') {
            $environment['PATH'] = $path;
            $environment['Path'] = $path;
        }

        $systemRoot = getenv('SystemRoot');
        if (is_string($systemRoot) && $systemRoot !== '') {
            $environment['SystemRoot'] = $systemRoot;
        }

        return array_merge($environment, $extraEnv);
    }

    /**
     * @param  array<string, mixed>  $executionProfile
     * @param  array<string, mixed>  $providedInputs
     * @param  array<string, mixed>  $runPayload
     * @return array<string, mixed>
     */
    private function mergeProvidedInputsIntoExecutionProfile(array $executionProfile, array $providedInputs, array $runPayload): array
    {
        $requiredInputs = is_array($executionProfile['required_inputs'] ?? null)
            ? $executionProfile['required_inputs']
            : [];

        foreach ($requiredInputs as $index => $input) {
            if (!is_array($input)) {
                continue;
            }

            $key = isset($input['key']) && is_string($input['key']) ? $input['key'] : null;
            if (!$key) {
                continue;
            }

            $resolvedValue = $this->resolveProvidedInputValue($key, $providedInputs);
            if (is_string($resolvedValue)) {
                $requiredInputs[$index]['value'] = $resolvedValue;
            }

            if ($this->shouldAllowEmptyInput($runPayload, $key, $providedInputs)) {
                $requiredInputs[$index]['allow_empty'] = true;
            }
        }

        $executionProfile['required_inputs'] = $requiredInputs;
        return $executionProfile;
    }

    /**
     * @param  array<string, mixed>  $runPayload
     * @param  array<string, mixed>  $providedInputs
     */
    private function shouldAllowEmptyInput(array $runPayload, string $key, array $providedInputs): bool
    {
        $resolvedValue = $this->resolveProvidedInputValue($key, $providedInputs);
        if (!is_string($resolvedValue) || $resolvedValue !== '') {
            return false;
        }

        $text = mb_strtolower(trim(implode("\n", array_filter([
            (string) ($runPayload['test_case_title'] ?? ''),
            (string) ($runPayload['test_case_description'] ?? ''),
            (string) ($runPayload['test_case_text'] ?? ''),
            (string) ($runPayload['notes'] ?? ''),
            (string) data_get($runPayload, 'expected_result.visible_text_contains', ''),
            implode(' ', array_filter(is_array(data_get($runPayload, 'expected_result.assertion_keywords')) ? data_get($runPayload, 'expected_result.assertion_keywords') : [])),
        ]))));

        $isRequiredFieldScenario = preg_match('/missing|required field|required|blank field|empty field|leave .* blank|leaving .* blank|username is required|password is required/', $text) === 1;
        if (!$isRequiredFieldScenario) {
            return false;
        }

        return match ($key) {
            'email', 'username', 'user', 'login', 'identifier' => preg_match('/missing username|missing email|blank username|empty username|username is required|email is required/', $text) === 1,
            'password' => preg_match('/missing password|blank password|empty password|password is required/', $text) === 1,
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $providedInputs
     */
    private function resolveProvidedInputValue(string $key, array $providedInputs): ?string
    {
        $aliases = match ($key) {
            'email', 'username', 'user', 'login', 'identifier' => ['email', 'username', 'user', 'login', 'identifier'],
            default => [$key],
        };

        foreach ($aliases as $alias) {
            if (array_key_exists($alias, $providedInputs) && is_string($providedInputs[$alias])) {
                return $providedInputs[$alias];
            }
        }

        return null;
    }
}
