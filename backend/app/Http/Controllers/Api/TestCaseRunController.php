<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ExecuteSingleTestCaseRun;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\VersionItem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class TestCaseRunController extends Controller
{
    public function show(int $id)
    {
        $item = VersionItem::findOrFail($id);

        $latestResult = TestResult::with('testRun')
            ->where('version_item_id', $item->id)
            ->orderByDesc('executed_at')
            ->orderByDesc('id')
            ->first();

        $latestTargetedRun = TestRun::where('request_payload->test_case_id', $item->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $latestRun = $this->pickLatestRun($latestTargetedRun, $latestResult?->testRun);

        $latestRunResult = null;
        if ($latestRun) {
            $latestRunResult = TestResult::where('test_run_id', $latestRun->id)
                ->where('version_item_id', $item->id)
                ->orderByDesc('executed_at')
                ->orderByDesc('id')
                ->first();
        }

        $effectiveResult = $latestRunResult ?: $latestResult;

        $executionState = $this->resolveExecutionState($item, $latestRun, $effectiveResult);

        $artifactPayload = $effectiveResult?->artifacts;
        if (!is_array($artifactPayload)) {
            $artifactPayload = [
                'trace' => [],
                'screenshot' => [],
                'video' => [],
            ];
        }

        $resultPayload = $effectiveResult?->result_payload;
        $executionTrace = [];
        if (is_array($resultPayload) && is_array($resultPayload['execution_trace'] ?? null)) {
            $executionTrace = array_values(array_filter(
                $resultPayload['execution_trace'],
                static fn ($entry) => is_string($entry) && trim($entry) !== '',
            ));
        }

        if ($latestRun && in_array($latestRun->status, ['created', 'running'], true)) {
            $executionTrace = $this->readLiveExecutionTrace($latestRun->run_id, $item->id);
        }

        $lastErrorMessage = null;
        if ($latestRun && in_array($latestRun->status, ['created', 'running'], true)) {
            $lastErrorMessage = null;
        } else {
            $lastErrorMessage = $effectiveResult?->error_message;
        }

        if (!$lastErrorMessage && $latestRun && $latestRun->status === 'failed') {
            $requestPayload = is_array($latestRun->request_payload) ? $latestRun->request_payload : [];
            $lastErrorMessage = isset($requestPayload['last_error']) && is_string($requestPayload['last_error'])
                ? $requestPayload['last_error']
                : null;
        }

        return response()->json([
            'id' => $item->id,
            'title' => $item->title,
            'description' => $item->description,
            'status' => $item->status,
            'execution_state' => $executionState,
            'last_run_id' => $latestRun?->run_id,
            'last_run_status' => $latestRun ? $this->mapRunStatus($latestRun->status) : null,
            'last_run_started_at' => optional($latestRun?->started_at)->toISOString(),
            'last_run_finished_at' => optional($latestRun?->finished_at)->toISOString(),
            'last_error_message' => $lastErrorMessage,
            'execution_trace' => $executionTrace,
            'artifacts' => [
                'trace' => is_array($artifactPayload['trace'] ?? null) ? $artifactPayload['trace'] : [],
                'screenshot' => is_array($artifactPayload['screenshot'] ?? null) ? $artifactPayload['screenshot'] : [],
                'video' => is_array($artifactPayload['video'] ?? null) ? $artifactPayload['video'] : [],
            ],
        ]);
    }

    public function run(Request $request, int $id)
    {
        $item = VersionItem::findOrFail($id);

        $data = $request->validate([
            'base_url' => ['required', 'url', 'max:2048'],
            'use_auth' => ['sometimes', 'boolean'],
            'watch_mode' => ['sometimes', 'boolean'],
            'environment_name' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $baseUrl = $this->normalizeBaseUrl($data['base_url']);
        $useAuth = array_key_exists('use_auth', $data) ? (bool) $data['use_auth'] : true;
        $watchMode = array_key_exists('watch_mode', $data) ? (bool) $data['watch_mode'] : true;
        $testCaseTitle = (string) $item->title;
        $testCaseDescription = (string) ($item->description ?? '');

        $run = TestRun::create([
            'run_id' => (string) Str::uuid(),
            'project_version_id' => $item->project_version_id,
            'schema_version' => '1.0',
            'base_url' => $baseUrl,
            'mode' => 'single-test-case',
            'status' => 'created',
            'requested_by' => Auth::id(),
            'summary_total' => 1,
            'request_payload' => [
                'schema_version' => '1.0',
                'test_case_id' => $item->id,
                'test_case_title' => $testCaseTitle,
                'test_case_description' => $testCaseDescription,
                'test_case_text' => trim($testCaseTitle . "\n" . $testCaseDescription),
                'base_url' => $baseUrl,
                'use_auth' => $useAuth,
                'watch_mode' => $watchMode,
                'environment_name' => (string) ($data['environment_name'] ?? ''),
                'notes' => (string) ($data['notes'] ?? ''),
                'priority' => (string) ($item->priority ?? ''),
                'criticality' => (string) ($item->criticality ?? ''),
                'current_status' => (string) ($item->status ?? ''),
                'project_version_id' => (int) $item->project_version_id,
            ],
        ]);

        if ($watchMode) {
            ExecuteSingleTestCaseRun::dispatch($run->run_id)->afterResponse();
        } else {
            ExecuteSingleTestCaseRun::dispatch($run->run_id);
        }

        return response()->json([
            'run_id' => $run->run_id,
            'status' => $watchMode ? 'started' : 'queued',
        ], 202);
    }

    private function normalizeBaseUrl(string $baseUrl): string
    {
        return rtrim(trim($baseUrl), '/');
    }

    private function mapRunStatus(string $status): string
    {
        return match ($status) {
            'created' => 'queued',
            'running' => 'running',
            'completed' => 'completed',
            'failed' => 'failed',
            default => 'failed',
        };
    }

    private function resolveExecutionState(VersionItem $item, ?TestRun $latestRun, ?TestResult $latestResult): string
    {
        if ($latestRun) {
            if ($latestRun->status === 'created') {
                return 'queued';
            }

            if ($latestRun->status === 'running') {
                return 'running';
            }

            if ($latestRun->status === 'failed') {
                return 'blocked';
            }
        }

        if ($latestResult) {
            return match ($latestResult->status) {
                'passed' => 'passed',
                'failed' => 'failed',
                'blocked' => 'blocked',
                'skipped' => 'idle',
                default => 'idle',
            };
        }

        return match ($item->status) {
            'Passed' => 'passed',
            'Failed' => 'failed',
            'Blocked' => 'blocked',
            default => 'idle',
        };
    }

    private function pickLatestRun(?TestRun $a, ?TestRun $b): ?TestRun
    {
        if (!$a) {
            return $b;
        }

        if (!$b) {
            return $a;
        }

        $aDate = $a->created_at instanceof Carbon ? $a->created_at : Carbon::parse($a->created_at);
        $bDate = $b->created_at instanceof Carbon ? $b->created_at : Carbon::parse($b->created_at);

        if ($aDate->equalTo($bDate)) {
            return ((int) $a->id >= (int) $b->id) ? $a : $b;
        }

        return $aDate->greaterThanOrEqualTo($bDate) ? $a : $b;
    }

    private function readLiveExecutionTrace(string $runId, int $itemId): array
    {
        $workspaceRoot = realpath(base_path('..'));
        if (!$workspaceRoot) {
            return [];
        }

        $liveTracePath = $workspaceRoot . DIRECTORY_SEPARATOR . 'runs' . DIRECTORY_SEPARATOR . $runId . DIRECTORY_SEPARATOR . 'live-trace.json';
        if (!is_file($liveTracePath)) {
            return [];
        }

        $payload = json_decode((string) file_get_contents($liveTracePath), true);
        if (!is_array($payload) || !is_array($payload['cases'] ?? null)) {
            return [];
        }

        foreach ($payload['cases'] as $caseEntry) {
            if (!is_array($caseEntry)) {
                continue;
            }

            $externalId = (int) ($caseEntry['external_id'] ?? 0);
            if ($externalId !== $itemId) {
                continue;
            }

            $lines = $caseEntry['execution_trace'] ?? [];
            if (!is_array($lines)) {
                return [];
            }

            return array_values(array_filter(
                $lines,
                static fn ($entry) => is_string($entry) && trim($entry) !== '',
            ));
        }

        return [];
    }
}
