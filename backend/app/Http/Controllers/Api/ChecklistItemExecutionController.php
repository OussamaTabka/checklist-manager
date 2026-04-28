<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ExecuteSingleTestCaseRun;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\TestResult;
use App\Models\TestRun;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ChecklistItemExecutionController extends Controller
{
    public function show(Checklist $checklist, ChecklistItem $item)
    {
        $this->ensureChecklistOwnership($checklist, $item);

        $latestResult = TestResult::with('testRun')
            ->where('checklist_item_id', $item->id)
            ->orderByDesc('executed_at')
            ->orderByDesc('id')
            ->first();

        $latestTargetedRun = TestRun::where('request_payload->test_case_id', $item->id)
            ->where('request_payload->target_type', 'checklist_item')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $latestRun = $this->pickLatestRun($latestTargetedRun, $latestResult?->testRun);
        $executionState = $this->resolveExecutionState($item, $latestRun, $latestResult);

        $artifactPayload = $latestResult?->artifacts;
        if (!is_array($artifactPayload)) {
            $artifactPayload = ['trace' => [], 'screenshot' => [], 'video' => []];
        }

        $resultPayload = $latestResult?->result_payload;
        $executionTrace = [];
        if (is_array($resultPayload) && is_array($resultPayload['execution_trace'] ?? null)) {
            $executionTrace = array_values(array_filter(
                $resultPayload['execution_trace'],
                static fn ($entry) => is_string($entry) && trim($entry) !== '',
            ));
        }

        $lastErrorMessage = $latestRun && in_array($latestRun->status, ['created', 'running'], true)
            ? null
            : $latestResult?->error_message;

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
            'status' => $this->displayStatus($item->status),
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

    public function run(Request $request, Checklist $checklist, ChecklistItem $item)
    {
        $this->ensureChecklistOwnership($checklist, $item);

        $data = $request->validate([
            'base_url' => ['required', 'url', 'max:2048'],
            'use_auth' => ['sometimes', 'boolean'],
            'watch_mode' => ['sometimes', 'boolean'],
            'environment_name' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $run = TestRun::create([
            'run_id' => (string) Str::uuid(),
            'project_version_id' => null,
            'checklist_id' => $checklist->id,
            'schema_version' => '1.0',
            'base_url' => rtrim(trim($data['base_url']), '/'),
            'mode' => 'single-test-case',
            'status' => 'created',
            'requested_by' => Auth::id(),
            'summary_total' => 1,
            'request_payload' => [
                'schema_version' => '1.0',
                'target_type' => 'checklist_item',
                'test_case_id' => $item->id,
                'test_case_title' => (string) $item->title,
                'test_case_description' => (string) ($item->description ?? ''),
                'test_case_text' => trim((string) $item->title . "\n" . (string) ($item->description ?? '')),
                'base_url' => rtrim(trim($data['base_url']), '/'),
                'use_auth' => array_key_exists('use_auth', $data) ? (bool) $data['use_auth'] : true,
                'watch_mode' => array_key_exists('watch_mode', $data) ? (bool) $data['watch_mode'] : true,
                'environment_name' => (string) ($data['environment_name'] ?? ''),
                'notes' => (string) ($data['notes'] ?? ''),
                'priority' => (string) ($item->priority ?? ''),
                'criticality' => (string) ($item->criticality ?? ''),
                'current_status' => (string) ($item->status ?? ''),
                'checklist_id' => (int) $checklist->id,
            ],
        ]);

        $watchMode = array_key_exists('watch_mode', $data) ? (bool) $data['watch_mode'] : true;
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

    private function ensureChecklistOwnership(Checklist $checklist, ChecklistItem $item): void
    {
        abort_unless((int) $item->checklist_id === (int) $checklist->id, 422, 'Checklist item does not belong to this checklist.');
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

    private function resolveExecutionState(ChecklistItem $item, ?TestRun $latestRun, ?TestResult $latestResult): string
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

        return match ($this->displayStatus($item->status)) {
            'Passed' => 'passed',
            'Failed' => 'failed',
            'Blocked' => 'blocked',
            default => 'idle',
        };
    }

    private function displayStatus(?string $status): string
    {
        return match ($status) {
            'passed' => 'Passed',
            'failed' => 'Failed',
            'blocked' => 'Blocked',
            'Passed', 'Failed', 'Blocked' => $status,
            default => 'Not Tested',
        };
    }
}
