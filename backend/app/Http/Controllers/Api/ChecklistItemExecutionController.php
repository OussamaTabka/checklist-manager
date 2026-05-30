<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\ChecklistItemHistory;
use App\Models\Project;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Services\ChecklistItemRunService;
use App\Services\ExecutionProfileService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class ChecklistItemExecutionController extends Controller
{
    public function __construct(
        private readonly ExecutionProfileService $executionProfileService,
        private readonly ChecklistItemRunService $checklistItemRunService,
    ) {
    }

    public function show(Checklist $checklist, ChecklistItem $item)
    {
        $this->ensureChecklistOwnership($checklist, $item);
        $this->ensureChecklistAccess($checklist);

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
        $executionProfile = $this->resolveExecutionProfile($item, $latestRun);

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

        if ($latestRun && in_array($latestRun->status, ['created', 'running'], true)) {
            $executionTrace = $this->readLiveExecutionTrace($latestRun->run_id, $item->id);
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

        $resultPayload = is_array($latestResult?->result_payload) ? $latestResult->result_payload : [];
        $generatedPlan = is_array($resultPayload['generated_plan'] ?? null)
            ? $resultPayload['generated_plan']
            : (is_array($executionProfile['last_generated_plan'] ?? null) ? $executionProfile['last_generated_plan'] : null);
        $failureSource = is_array($resultPayload['failure_source'] ?? null) ? $resultPayload['failure_source'] : null;
        $testedBaseUrl = $latestRun?->base_url;
        if ((!is_string($testedBaseUrl) || trim($testedBaseUrl) === '') && is_array($latestRun?->request_payload)) {
            $candidateBaseUrl = $latestRun->request_payload['base_url'] ?? null;
            $testedBaseUrl = is_string($candidateBaseUrl) ? $candidateBaseUrl : null;
        }
        $artifactPaths = $this->extractArtifactPaths($artifactPayload);

        return response()->json([
            'id' => $item->id,
            'title' => $item->title,
            'description' => $item->description,
            'status' => $this->displayStatus($item->status),
            'qa_comment' => $item->qa_comment,
            'tested_at' => optional($item->tested_at)->toISOString(),
            'tested_by' => $item->tester()->first(['id', 'name', 'email']),
            'execution_state' => $executionState,
            'execution_profile' => $executionProfile,
            'generated_plan' => $generatedPlan,
            'generated_test' => is_array($resultPayload['generated_test'] ?? null) ? $resultPayload['generated_test'] : null,
            'pipeline' => isset($resultPayload['pipeline']) && is_string($resultPayload['pipeline']) ? $resultPayload['pipeline'] : 'deterministic',
            'failure_source' => $failureSource,
            'last_run_id' => $latestRun?->run_id,
            'last_run_status' => $latestRun ? $this->mapRunStatus($latestRun->status) : null,
            'last_run_started_at' => optional($latestRun?->started_at)->toISOString(),
            'last_run_finished_at' => optional($latestRun?->finished_at)->toISOString(),
            'tested_base_url' => $testedBaseUrl,
            'last_error_message' => $lastErrorMessage,
            'execution_trace' => $executionTrace,
            'artifacts' => [
                'trace' => is_array($artifactPayload['trace'] ?? null) ? $artifactPayload['trace'] : [],
                'screenshot' => is_array($artifactPayload['screenshot'] ?? null) ? $artifactPayload['screenshot'] : [],
                'video' => is_array($artifactPayload['video'] ?? null) ? $artifactPayload['video'] : [],
                'all' => $artifactPaths,
                'raw_paths' => is_array($artifactPayload['raw_paths'] ?? null) ? $artifactPayload['raw_paths'] : [],
            ],
        ]);
    }

    public function run(Request $request, Checklist $checklist, ChecklistItem $item)
    {
        $this->ensureChecklistOwnership($checklist, $item);
        $this->ensureChecklistAccess($checklist);

        $data = $request->validate([
            'base_url' => ['required', 'url', 'max:2048'],
            'use_auth' => ['sometimes', 'boolean'],
            'watch_mode' => ['sometimes', 'boolean'],
            'environment_name' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'provided_inputs' => ['nullable', 'array'],
            'provided_inputs.*' => ['nullable', 'string', 'max:4000'],
        ]);

        $watchMode = array_key_exists('watch_mode', $data) ? (bool) $data['watch_mode'] : true;
        $run = $this->checklistItemRunService->start($checklist, $item, (int) Auth::id(), [
            'base_url' => $data['base_url'],
            'use_auth' => array_key_exists('use_auth', $data) ? (bool) $data['use_auth'] : true,
            'watch_mode' => $watchMode,
            'environment_name' => (string) ($data['environment_name'] ?? ''),
            'notes' => (string) ($data['notes'] ?? ''),
            'provided_inputs' => is_array($data['provided_inputs'] ?? null) ? $data['provided_inputs'] : [],
        ]);

        return response()->json([
            'run_id' => $run->run_id,
            'status' => $watchMode ? 'started' : 'queued',
        ], 202);
    }

    private function ensureChecklistOwnership(Checklist $checklist, ChecklistItem $item): void
    {
        abort_unless((int) $item->checklist_id === (int) $checklist->id, 422, 'Checklist item does not belong to this checklist.');
    }

    private function ensureChecklistAccess(Checklist $checklist): void
    {
        $user = Auth::user();

        if (!$checklist->project_id) {
            abort_unless(
                $user && (int) $checklist->created_by === (int) $user->id,
                403,
                'Only the creator can access this system checklist.'
            );
            return;
        }

        $project = Project::findOrFail($checklist->project_id);

        abort_unless(
            $user && (
                $user->hasRole('admin') ||
                $user->hasRole('chef') ||
                ($user->hasRole('testeur') && $project->testers()->where('users.id', $user->id)->exists())
            ),
            403,
            'Only assigned testers, project managers, or admins can access this execution checklist.'
        );
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

    private function extractArtifactPaths(array $artifactPayload): array
    {
        $paths = [];
        foreach (['trace', 'screenshot', 'video'] as $key) {
            foreach (($artifactPayload[$key] ?? []) as $value) {
                if (is_string($value) && trim($value) !== '') {
                    $paths[] = $value;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveExecutionProfile(ChecklistItem $item, ?TestRun $latestRun): array
    {
        $profile = is_array($item->execution_profile) ? $item->execution_profile : [];
        if ($profile !== []) {
            return $profile;
        }

        $item->loadMissing('checklist.project');
        $baseUrl = $latestRun?->base_url ?: $item->checklist?->project?->app_url;
        if (!is_string($baseUrl) || trim($baseUrl) === '') {
            return [];
        }

        try {
            $generated = $this->executionProfileService->generateForChecklistItem($item, $baseUrl, [
                'run_id' => 'profile-checklist-item-' . $item->id,
                'use_auth' => true,
            ]);

            $item->update([
                'execution_profile' => $generated['execution_profile'],
            ]);

            return $generated['execution_profile'];
        } catch (\Throwable) {
            return [];
        }
    }
}
