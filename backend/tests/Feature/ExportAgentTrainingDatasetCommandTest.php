<?php

namespace Tests\Feature;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Project;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ExportAgentTrainingDatasetCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exports_only_successful_runs_and_redacts_sensitive_values(): void
    {
        $outputPath = storage_path('app/agent-training/training_dataset.jsonl');
        File::delete($outputPath);

        $owner = User::factory()->create();

        $project = Project::create([
            'name' => 'Training project',
            'description' => 'Project context',
            'test_objectives' => 'Validate sign in journeys',
            'app_url' => 'https://example.test/login?token=abc123',
            'created_by' => $owner->id,
        ]);

        $checklist = Checklist::create([
            'name' => 'Authentication checklist',
            'description' => 'Checklist context',
            'project_id' => $project->id,
            'acceptance_criteria' => 'User can authenticate',
            'business_rules' => ['No shared passwords'],
            'priority' => 'high',
            'status' => 'active',
            'is_active' => true,
            'created_by' => $owner->id,
        ]);

        $item = ChecklistItem::create([
            'checklist_id' => $checklist->id,
            'title' => 'Login with valid account',
            'description' => 'Use qa@example.com and password=SuperSecret123',
            'priority' => 'High',
            'criticality' => 'Critical',
            'order' => 1,
            'status' => 'passed',
            'execution_profile' => [
                'intent_summary' => 'Authenticate a user',
                'required_inputs' => [
                    ['key' => 'email', 'value' => 'qa@example.com'],
                    ['key' => 'password', 'value' => 'SuperSecret123'],
                ],
                'expected_observations' => ['Dashboard is visible'],
                'diagnostics' => ['Capture screenshot on failure'],
                'last_generated_plan' => [
                    'title' => 'Login plan',
                    'steps' => [
                        ['action' => 'goto', 'url' => 'https://example.test/login?token=abc123'],
                        ['action' => 'fill', 'field' => 'email'],
                    ],
                    'asserts' => [
                        ['type' => 'url_contains', 'value' => '/dashboard'],
                    ],
                ],
            ],
        ]);

        $successfulRun = TestRun::create([
            'run_id' => '44444444-4444-4444-8444-444444444444',
            'project_version_id' => null,
            'checklist_id' => $checklist->id,
            'schema_version' => '1.0',
            'base_url' => 'https://example.test/login?token=abc123',
            'mode' => 'single-test-case',
            'status' => 'completed',
            'requested_by' => $owner->id,
            'summary_total' => 1,
            'summary_passed' => 1,
            'summary_failed' => 0,
            'summary_blocked' => 0,
            'summary_skipped' => 0,
            'request_payload' => [
                'target_type' => 'checklist_item',
                'test_case_id' => $item->id,
                'test_case_title' => 'Login with valid account',
                'test_case_description' => 'Use qa@example.com and password=SuperSecret123',
                'base_url' => 'https://example.test/login?token=abc123',
                'provided_inputs' => [
                    'email' => 'qa@example.com',
                    'password' => 'SuperSecret123',
                    'api_token' => 'sk_secret_12345678',
                ],
                'priority' => 'High',
                'criticality' => 'Critical',
            ],
        ]);

        TestResult::create([
            'test_run_id' => $successfulRun->id,
            'version_item_id' => null,
            'checklist_item_id' => $item->id,
            'status' => 'passed',
            'error_type' => null,
            'error_message' => null,
            'duration_ms' => 1530,
            'artifacts' => ['trace' => [], 'screenshot' => [], 'video' => []],
            'result_payload' => [
                'status' => 'passed',
                'generated_plan' => [
                    'title' => 'Login plan',
                    'steps' => [
                        ['action' => 'goto', 'url' => 'https://example.test/login?token=abc123'],
                        ['action' => 'fill', 'field' => 'email', 'value' => 'qa@example.com'],
                    ],
                    'asserts' => [
                        ['type' => 'text_visible', 'value' => 'Welcome'],
                    ],
                ],
            ],
            'executed_at' => now(),
        ]);

        $failedRun = TestRun::create([
            'run_id' => '55555555-5555-4555-8555-555555555555',
            'project_version_id' => null,
            'checklist_id' => $checklist->id,
            'schema_version' => '1.0',
            'base_url' => 'https://example.test/login',
            'mode' => 'single-test-case',
            'status' => 'failed',
            'requested_by' => $owner->id,
            'summary_total' => 1,
            'summary_passed' => 0,
            'summary_failed' => 0,
            'summary_blocked' => 1,
            'summary_skipped' => 0,
            'request_payload' => [
                'target_type' => 'checklist_item',
                'test_case_id' => $item->id,
                'test_case_title' => 'Should be ignored',
            ],
        ]);

        TestResult::create([
            'test_run_id' => $failedRun->id,
            'version_item_id' => null,
            'checklist_item_id' => $item->id,
            'status' => 'blocked',
            'error_type' => 'infra_error',
            'error_message' => 'ignored',
            'duration_ms' => null,
            'artifacts' => ['trace' => [], 'screenshot' => [], 'video' => []],
            'result_payload' => ['status' => 'blocked'],
            'executed_at' => now(),
        ]);

        $this->artisan('agent-training:export-dataset')
            ->assertExitCode(0);

        $this->assertFileExists($outputPath);

        $lines = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', (string) file_get_contents($outputPath)) ?: [],
            static fn ($line) => trim($line) !== '',
        ));

        $this->assertCount(1, $lines);

        $record = json_decode($lines[0], true);

        $this->assertSame('44444444-4444-4444-8444-444444444444', data_get($record, 'metadata.run_id'));
        $this->assertSame('passed', data_get($record, 'metadata.status'));
        $this->assertSame('[REDACTED_PASSWORD]', data_get($record, 'input.provided_inputs.password'));
        $this->assertSame('[REDACTED]', data_get($record, 'input.provided_inputs.api_token'));
        $this->assertSame('[REDACTED_EMAIL]', data_get($record, 'input.provided_inputs.email'));
        $this->assertSame('https://example.test/login?token=[REDACTED_TOKEN]', data_get($record, 'input.base_url'));
        $this->assertSame('[REDACTED_PASSWORD]', data_get($record, 'output.execution_profile.required_inputs.1.value'));
        $this->assertSame('[REDACTED_EMAIL]', data_get($record, 'output.execution_profile.required_inputs.0.value'));
    }

    public function test_it_rejects_generic_or_trivial_examples_and_reports_quality_stats(): void
    {
        $outputPath = storage_path('app/agent-training/training_dataset.jsonl');
        File::delete($outputPath);

        $owner = User::factory()->create();

        $project = Project::create([
            'name' => 'Training project',
            'description' => 'Project context',
            'test_objectives' => 'Validate user flows',
            'app_url' => 'https://example.test',
            'created_by' => $owner->id,
        ]);

        $checklist = Checklist::create([
            'name' => 'Quality checklist',
            'description' => 'Checklist context',
            'project_id' => $project->id,
            'acceptance_criteria' => 'Useful flows only',
            'business_rules' => ['Avoid generic UI coverage'],
            'priority' => 'high',
            'status' => 'active',
            'is_active' => true,
            'created_by' => $owner->id,
        ]);

        $usefulItem = $this->createChecklistItem($checklist->id, 'Useful login', [
            'coverage_type' => 'business_flow',
        ]);
        $genericCoverageItem = $this->createChecklistItem($checklist->id, 'Generic coverage', [
            'coverage_type' => 'generic_ui',
        ]);
        $fallbackDiagnosticItem = $this->createChecklistItem($checklist->id, 'Fallback diagnostic', [
            'coverage_type' => 'business_flow',
            'diagnostics' => ['GENERIC_UI_FALLBACK'],
        ]);
        $genericPlanItem = $this->createChecklistItem($checklist->id, 'Generic plan only', [
            'coverage_type' => 'business_flow',
        ]);
        $bodyAssertItem = $this->createChecklistItem($checklist->id, 'Body assert only', [
            'coverage_type' => 'business_flow',
        ]);
        $noBusinessStepItem = $this->createChecklistItem($checklist->id, 'No business steps', [
            'coverage_type' => 'business_flow',
        ]);

        $this->createPassedRunWithResult($owner->id, $checklist->id, $usefulItem, [
            'generated_plan' => [
                'coverage_type' => 'business_flow',
                'steps' => [
                    ['action' => 'goto', 'url' => 'https://example.test/login'],
                    ['action' => 'fill', 'field' => 'email', 'value' => 'qa@example.com'],
                    ['action' => 'click', 'selector' => 'button[type="submit"]'],
                ],
                'asserts' => [
                    ['type' => 'text_visible', 'value' => 'Welcome back'],
                ],
            ],
        ]);

        $this->createPassedRunWithResult($owner->id, $checklist->id, $genericCoverageItem, [
            'generated_plan' => [
                'coverage_type' => 'generic_ui',
                'steps' => [
                    ['action' => 'goto', 'url' => 'https://example.test'],
                    ['action' => 'fill', 'field' => 'search', 'value' => 'demo'],
                ],
                'asserts' => [
                    ['type' => 'text_visible', 'value' => 'demo'],
                ],
            ],
        ]);

        $this->createPassedRunWithResult($owner->id, $checklist->id, $fallbackDiagnosticItem, [
            'generated_plan' => [
                'coverage_type' => 'business_flow',
                'diagnostics' => ['GENERIC_UI_FALLBACK'],
                'steps' => [
                    ['action' => 'goto', 'url' => 'https://example.test'],
                    ['action' => 'click', 'selector' => 'a[href="/profile"]'],
                ],
                'asserts' => [
                    ['type' => 'text_visible', 'value' => 'Profile'],
                ],
            ],
        ]);

        $this->createPassedRunWithResult($owner->id, $checklist->id, $genericPlanItem, [
            'generated_plan' => [
                'coverage_type' => 'business_flow',
                'steps' => [
                    ['action' => 'goto', 'url' => 'https://example.test'],
                    ['action' => 'wait_for_selector', 'selector' => 'body'],
                    ['action' => 'screenshot'],
                ],
                'asserts' => [],
            ],
        ]);

        $this->createPassedRunWithResult($owner->id, $checklist->id, $bodyAssertItem, [
            'generated_plan' => [
                'coverage_type' => 'business_flow',
                'steps' => [
                    ['action' => 'goto', 'url' => 'https://example.test'],
                    ['action' => 'click', 'selector' => 'button[type="submit"]'],
                ],
                'asserts' => [
                    ['assert' => 'expect_visible', 'value' => 'body'],
                ],
            ],
        ]);

        $this->createPassedRunWithResult($owner->id, $checklist->id, $noBusinessStepItem, [
            'generated_plan' => [
                'coverage_type' => 'business_flow',
                'steps' => [
                    ['action' => 'goto', 'url' => 'https://example.test'],
                    ['action' => 'wait_for_selector', 'selector' => '#app'],
                ],
                'asserts' => [],
            ],
        ]);

        $this->assertSame(0, Artisan::call('agent-training:export-dataset'));

        $output = Artisan::output();

        $this->assertStringContainsString('Agent training dataset export completed.', $output);
        $this->assertStringContainsString('Runs found: 6', $output);
        $this->assertStringContainsString('Examples exported: 1', $output);
        $this->assertStringContainsString('Examples rejected: 5', $output);
        $this->assertStringContainsString('Plans with fill: 1', $output);
        $this->assertStringContainsString('Plans with click: 1', $output);
        $this->assertStringContainsString('Plans with useful asserts: 1', $output);
        $this->assertStringContainsString('Rejection reasons:', $output);
        $this->assertStringContainsString('- coverage_type_generic_ui: 1', $output);
        $this->assertStringContainsString('- diagnostics_generic_ui_fallback: 1', $output);
        $this->assertStringContainsString('- generic_ui_fallback_plan: 1', $output);
        $this->assertStringContainsString('- asserts_only_expect_visible_body: 1', $output);
        $this->assertStringContainsString('- no_business_steps: 2', $output);

        $lines = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', (string) file_get_contents($outputPath)) ?: [],
            static fn ($line) => trim($line) !== '',
        ));

        $this->assertCount(1, $lines);
    }

    private function createChecklistItem(int $checklistId, string $title, array $profileOverrides = []): ChecklistItem
    {
        return ChecklistItem::create([
            'checklist_id' => $checklistId,
            'title' => $title,
            'description' => 'Description for ' . $title,
            'priority' => 'High',
            'criticality' => 'Critical',
            'order' => 1,
            'status' => 'passed',
            'execution_profile' => array_merge([
                'coverage_type' => 'business_flow',
                'required_inputs' => [],
                'expected_observations' => [],
                'diagnostics' => [],
            ], $profileOverrides),
        ]);
    }

    /**
     * @param  array<string, mixed>  $resultPayload
     */
    private function createPassedRunWithResult(int $ownerId, int $checklistId, ChecklistItem $item, array $resultPayload): void
    {
        $run = TestRun::create([
            'run_id' => (string) fake()->uuid(),
            'project_version_id' => null,
            'checklist_id' => $checklistId,
            'schema_version' => '1.0',
            'base_url' => 'https://example.test',
            'mode' => 'single-test-case',
            'status' => 'completed',
            'requested_by' => $ownerId,
            'summary_total' => 1,
            'summary_passed' => 1,
            'summary_failed' => 0,
            'summary_blocked' => 0,
            'summary_skipped' => 0,
            'request_payload' => [
                'target_type' => 'checklist_item',
                'test_case_id' => $item->id,
                'test_case_title' => $item->title,
            ],
        ]);

        TestResult::create([
            'test_run_id' => $run->id,
            'version_item_id' => null,
            'checklist_item_id' => $item->id,
            'status' => 'passed',
            'error_type' => null,
            'error_message' => null,
            'duration_ms' => 1000,
            'artifacts' => ['trace' => [], 'screenshot' => [], 'video' => []],
            'result_payload' => array_merge(['status' => 'passed'], $resultPayload),
            'executed_at' => now(),
        ]);
    }
}
