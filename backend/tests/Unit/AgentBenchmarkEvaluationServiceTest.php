<?php

namespace Tests\Unit;

use App\Models\AgentBenchmarkCase;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Project;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\User;
use App\Services\AgentBenchmarkEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AgentBenchmarkEvaluationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_evaluation_service_detects_matching_result(): void
    {
        [$case] = $this->createBenchmarkScenario('passed');
        $this->createResult($case->checklist_item_id, 'passed', [
            'predicted_scenario_type' => 'authentication',
            'execution_trace' => ['login step completed'],
            'generated_plan' => [
                'asserts' => [
                    ['text' => 'secure area'],
                ],
            ],
        ]);

        $evaluation = app(AgentBenchmarkEvaluationService::class)->evaluate();
        $row = collect($evaluation['cases'])->firstWhere('item_title', 'Benchmark login case');

        $this->assertSame('matched', $row['final_evaluation']);
        $this->assertTrue($row['status_match']);
    }

    public function test_evaluation_service_detects_false_pass(): void
    {
        [$case] = $this->createBenchmarkScenario('unsupported');
        $this->createResult($case->checklist_item_id, 'passed', [
            'predicted_scenario_type' => 'unsupported_feature',
            'execution_trace' => ['feature missing'],
            'generated_plan' => [
                'asserts' => [
                    ['text' => 'search'],
                ],
            ],
        ]);

        $evaluation = app(AgentBenchmarkEvaluationService::class)->evaluate();

        $this->assertSame(1, $evaluation['metrics']['false_pass_count']);
    }

    public function test_evaluation_service_detects_false_fail(): void
    {
        [$case] = $this->createBenchmarkScenario('passed');
        $this->createResult($case->checklist_item_id, 'failed', [
            'predicted_scenario_type' => 'authentication',
            'execution_trace' => ['login failed'],
            'generated_plan' => [
                'asserts' => [
                    ['text' => 'secure area'],
                ],
            ],
        ]);

        $evaluation = app(AgentBenchmarkEvaluationService::class)->evaluate();

        $this->assertSame(1, $evaluation['metrics']['false_fail_count']);
    }

    public function test_evaluation_command_handles_missing_real_results_without_crashing(): void
    {
        $this->createBenchmarkScenario('passed');

        $this->artisan('agent:evaluate-benchmark')
            ->assertExitCode(0)
            ->expectsOutputToContain('Global metrics');
    }

    public function test_evaluation_works_when_failure_source_exists_only_inside_result_payload(): void
    {
        [$case] = $this->createBenchmarkScenario('failed');
        $this->createResult($case->checklist_item_id, 'blocked', [
            'predicted_scenario_type' => 'authentication',
            'error' => [
                'failure_source' => [
                    'phase' => 'assert',
                    'reference' => 'secure area banner',
                ],
            ],
            'error_message' => 'Secure area banner was not visible.',
            'execution_trace' => ['login attempted'],
        ]);

        $evaluation = app(AgentBenchmarkEvaluationService::class)->evaluate();
        $row = collect($evaluation['cases'])->firstWhere('item_title', 'Benchmark login case');

        $this->assertSame('assert', data_get($row, 'failure_source.phase'));
        $this->assertSame('Secure area banner was not visible.', $row['error_message']);
    }

    public function test_evaluation_does_not_select_missing_failure_source_column(): void
    {
        [$case] = $this->createBenchmarkScenario('passed');
        $this->createResult($case->checklist_item_id, 'passed', [
            'predicted_scenario_type' => 'authentication',
            'execution_trace' => ['login completed'],
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(AgentBenchmarkEvaluationService::class)->evaluate();

        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");

        $this->assertStringNotContainsString('failure_source', $queries);
    }

    public function test_blocked_result_can_expose_failure_source_from_result_payload(): void
    {
        [$case] = $this->createBenchmarkScenario('failed');
        $this->createResult($case->checklist_item_id, 'blocked', [
            'predicted_scenario_type' => 'authentication',
            'metadata' => [
                'failure_source' => [
                    'phase' => 'step',
                    'reference' => 'click submit button',
                ],
            ],
            'execution_trace' => ['click failed'],
        ]);

        $evaluation = app(AgentBenchmarkEvaluationService::class)->evaluate();
        $row = collect($evaluation['cases'])->firstWhere('item_title', 'Benchmark login case');

        $this->assertSame('step', data_get($row, 'failure_source.phase'));
        $this->assertSame('click submit button', data_get($row, 'failure_source.reference'));
    }

    public function test_environment_blocked_results_are_counted_separately_and_not_as_false_fail(): void
    {
        [$case] = $this->createBenchmarkScenario('passed');
        $this->createResult($case->checklist_item_id, 'blocked', [
            'predicted_scenario_type' => 'authentication',
            'failure_source' => [
                'phase' => 'initial_navigation',
                'reference' => 'url_unreachable',
                'message' => 'The target URL could not be reached by Playwright.',
            ],
            'execution_trace' => ['initial navigation failed'],
        ], 'url_unreachable');

        $evaluation = app(AgentBenchmarkEvaluationService::class)->evaluate();
        $row = collect($evaluation['cases'])->firstWhere('item_title', 'Benchmark login case');

        $this->assertSame(1, $evaluation['metrics']['environment_blocked_count']);
        $this->assertSame(0, $evaluation['metrics']['agent_evaluated_cases']);
        $this->assertSame(0, $evaluation['metrics']['false_fail_count']);
        $this->assertSame('environment_blocked', $row['final_evaluation']);
    }

    public function test_evaluation_exposes_pipeline_name_from_result_payload(): void
    {
        [$case] = $this->createBenchmarkScenario('passed');
        $this->createResult($case->checklist_item_id, 'passed', [
            'pipeline' => 'api_execution_artifact_collection',
            'execution_trace' => ['login step completed'],
        ]);

        $evaluation = app(AgentBenchmarkEvaluationService::class)->evaluate();

        $this->assertSame('api_execution_artifact_collection', $evaluation['metrics']['pipeline_name']);
    }

    /**
     * @return array{0: AgentBenchmarkCase, 1: ChecklistItem}
     */
    private function createBenchmarkScenario(string $expectedStatus): array
    {
        $owner = User::factory()->create();

        $project = Project::create([
            'name' => 'Benchmark project',
            'description' => 'Benchmark project',
            'app_url' => 'https://example.test',
            'created_by' => $owner->id,
        ]);

        $checklist = Checklist::create([
            'name' => 'Benchmark checklist',
            'description' => 'Benchmark checklist',
            'project_id' => $project->id,
            'category' => 'Agent Benchmark',
            'is_active' => true,
            'created_by' => $owner->id,
            'priority' => 'critical',
            'status' => 'ready_for_test',
            'template_scope' => 'project',
            'lifecycle_status' => 'approved',
            'generated_from' => 'manual',
        ]);

        $item = ChecklistItem::create([
            'checklist_id' => $checklist->id,
            'title' => 'Benchmark login case',
            'description' => 'Benchmark description',
            'priority' => 'High',
            'criticality' => 'Critical',
            'order' => 1,
        ]);

        $case = AgentBenchmarkCase::create([
            'project_id' => $project->id,
            'checklist_id' => $checklist->id,
            'checklist_item_id' => $item->id,
            'source_app' => 'test_app',
            'project_url' => 'https://example.test',
            'expected_scenario_type' => $expectedStatus === 'unsupported' ? 'unsupported_feature' : 'authentication',
            'provided_inputs' => ['username' => 'demo'],
            'expected_result' => [
                'expected_status' => $expectedStatus,
                'assertion_keywords' => ['secure area'],
                'trace_keywords' => ['login'],
            ],
            'test_kind' => $expectedStatus === 'unsupported' ? 'unsupported' : 'positive',
            'enabled' => true,
        ]);

        return [$case, $item];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createResult(int $checklistItemId, string $status, array $payload, ?string $errorType = null): void
    {
        $owner = User::factory()->create();
        $checklist = Checklist::firstOrFail();

        $run = TestRun::create([
            'run_id' => (string) fake()->uuid(),
            'project_version_id' => null,
            'checklist_id' => $checklist->id,
            'schema_version' => '1.0',
            'base_url' => 'https://example.test',
            'mode' => 'single-test-case',
            'status' => 'completed',
            'requested_by' => $owner->id,
            'summary_total' => 1,
            'summary_passed' => $status === 'passed' ? 1 : 0,
            'summary_failed' => $status === 'failed' ? 1 : 0,
            'summary_blocked' => $status === 'blocked' ? 1 : 0,
            'summary_skipped' => $status === 'skipped' ? 1 : 0,
            'request_payload' => [
                'target_type' => 'checklist_item',
                'test_case_id' => $checklistItemId,
            ],
        ]);

        TestResult::create([
            'test_run_id' => $run->id,
            'version_item_id' => null,
            'checklist_item_id' => $checklistItemId,
            'status' => $status,
            'error_type' => $errorType,
            'artifacts' => [],
            'result_payload' => $payload,
            'executed_at' => now(),
        ]);
    }
}
