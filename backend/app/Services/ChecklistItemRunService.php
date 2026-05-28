<?php

namespace App\Services;

use App\Jobs\ExecuteSingleTestCaseRun;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\ChecklistItemHistory;
use App\Models\TestRun;
use Illuminate\Support\Str;

class ChecklistItemRunService
{
    public function __construct(
        private readonly ExecutionInputNormalizer $executionInputNormalizer,
    ) {
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function start(Checklist $checklist, ChecklistItem $item, int $requestedBy, array $options): TestRun
    {
        $baseUrl = rtrim(trim((string) ($options['base_url'] ?? '')), '/');
        $useAuth = array_key_exists('use_auth', $options) ? (bool) $options['use_auth'] : true;
        $watchMode = array_key_exists('watch_mode', $options) ? (bool) $options['watch_mode'] : true;
        $providedInputs = $this->executionInputNormalizer->normalizeProvidedInputs(
            is_array($options['provided_inputs'] ?? null) ? $options['provided_inputs'] : []
        );

        $run = TestRun::create([
            'run_id' => (string) Str::uuid(),
            'project_version_id' => null,
            'checklist_id' => $checklist->id,
            'schema_version' => '1.0',
            'base_url' => $baseUrl,
            'mode' => 'single-test-case',
            'status' => 'created',
            'requested_by' => $requestedBy,
            'summary_total' => 1,
            'request_payload' => [
                'schema_version' => '1.0',
                'target_type' => 'checklist_item',
                'test_case_id' => $item->id,
                'test_case_title' => (string) $item->title,
                'test_case_description' => (string) ($item->description ?? ''),
                'test_case_text' => trim((string) $item->title . "\n" . (string) ($item->description ?? '')),
                'base_url' => $baseUrl,
                'use_auth' => $useAuth,
                'watch_mode' => $watchMode,
                'environment_name' => (string) ($options['environment_name'] ?? ''),
                'notes' => (string) ($options['notes'] ?? ''),
                'debug' => (bool) ($options['debug'] ?? false),
                'source_app' => isset($options['source_app']) ? (string) $options['source_app'] : null,
                'provided_inputs' => $providedInputs,
                'expected_result' => is_array($options['expected_result'] ?? null) ? $options['expected_result'] : null,
                'priority' => (string) ($item->priority ?? ''),
                'criticality' => (string) ($item->criticality ?? ''),
                'current_status' => (string) ($item->status ?? ''),
                'checklist_id' => (int) $checklist->id,
            ],
        ]);

        ChecklistItemHistory::create([
            'checklist_item_id' => $item->id,
            'changed_by' => $requestedBy,
            'field_name' => 'execution',
            'old_value' => $this->displayStatus($item->status),
            'new_value' => 'queued',
            'change_type' => 'automated_test_started',
            'notes' => 'Automated test requested for run ' . $run->run_id,
        ]);

        if ($watchMode && !app()->runningInConsole()) {
            ExecuteSingleTestCaseRun::dispatch($run->run_id)->afterResponse();
        } else {
            ExecuteSingleTestCaseRun::dispatch($run->run_id);
        }

        return $run;
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
