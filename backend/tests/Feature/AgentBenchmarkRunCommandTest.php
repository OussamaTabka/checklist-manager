<?php

namespace Tests\Feature;

use App\Jobs\ExecuteSingleTestCaseRun;
use App\Models\AgentBenchmarkCase;
use App\Models\TestResult;
use App\Models\TestRun;
use Database\Seeders\AgentBenchmarkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AgentBenchmarkRunCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_benchmark_dry_run_lists_cases_without_creating_test_runs(): void
    {
        $this->seed(AgentBenchmarkSeeder::class);
        Queue::fake();

        $this->artisan('agent:run-benchmark --dry-run --limit=3')
            ->assertExitCode(0)
            ->expectsOutputToContain('queued_count: 3');

        $this->assertDatabaseCount('test_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_run_benchmark_source_limit_dispatches_exactly_two_executions(): void
    {
        $this->seed(AgentBenchmarkSeeder::class);
        Queue::fake();

        $this->artisan('agent:run-benchmark --source=sauce_demo --limit=2')
            ->assertExitCode(0)
            ->expectsOutputToContain('queued_count: 2');

        $this->assertSame(2, TestRun::count());
        Queue::assertPushed(ExecuteSingleTestCaseRun::class, 2);
    }

    public function test_benchmark_provided_inputs_are_included_in_created_request_payload(): void
    {
        $this->seed(AgentBenchmarkSeeder::class);
        Queue::fake();

        $case = AgentBenchmarkCase::query()
            ->with('checklistItem')
            ->where('source_app', 'sauce_demo')
            ->get()
            ->first(fn (AgentBenchmarkCase $benchmarkCase): bool => $benchmarkCase->checklistItem?->title === 'Valid user can login');

        $this->assertNotNull($case);

        $this->artisan('agent:run-benchmark --case-id=' . $case->id)
            ->assertExitCode(0);

        $run = TestRun::query()->latest('id')->firstOrFail();

        $this->assertSame($case->checklist_item_id, (int) data_get($run->request_payload, 'test_case_id'));
        $this->assertSame('standard_user', data_get($run->request_payload, 'provided_inputs.username'));
        $this->assertSame('standard_user', data_get($run->request_payload, 'provided_inputs.email'));
        $this->assertSame('standard_user', data_get($run->request_payload, 'provided_inputs.user'));
        $this->assertSame('standard_user', data_get($run->request_payload, 'provided_inputs.login'));
        $this->assertSame('standard_user', data_get($run->request_payload, 'provided_inputs.identifier'));
        $this->assertSame('secret_sauce', data_get($run->request_payload, 'provided_inputs.password'));
        $this->assertSame('sauce_demo', data_get($run->request_payload, 'source_app'));
    }

    public function test_only_missing_skips_cases_that_already_have_results(): void
    {
        $this->seed(AgentBenchmarkSeeder::class);
        Queue::fake();

        $case = AgentBenchmarkCase::firstOrFail();
        $run = TestRun::create([
            'run_id' => (string) fake()->uuid(),
            'project_version_id' => null,
            'checklist_id' => $case->checklist_id,
            'schema_version' => '1.0',
            'base_url' => $case->project_url,
            'mode' => 'single-test-case',
            'status' => 'completed',
            'requested_by' => 1,
            'summary_total' => 1,
            'summary_passed' => 1,
            'summary_failed' => 0,
            'summary_blocked' => 0,
            'summary_skipped' => 0,
            'request_payload' => [
                'target_type' => 'checklist_item',
                'test_case_id' => $case->checklist_item_id,
            ],
        ]);

        TestResult::create([
            'test_run_id' => $run->id,
            'version_item_id' => null,
            'checklist_item_id' => $case->checklist_item_id,
            'status' => 'passed',
            'artifacts' => [],
            'result_payload' => ['status' => 'passed'],
            'executed_at' => now(),
        ]);

        $this->artisan('agent:run-benchmark --case-id=' . $case->id)
            ->assertExitCode(0)
            ->expectsOutputToContain('skipped_count: 1');

        $this->assertSame(1, TestRun::count());
        Queue::assertNothingPushed();
    }

    public function test_case_id_runs_only_selected_case(): void
    {
        $this->seed(AgentBenchmarkSeeder::class);
        Queue::fake();

        $case = AgentBenchmarkCase::query()->where('source_app', 'the_internet')->skip(5)->firstOrFail();

        $this->artisan('agent:run-benchmark --case-id=' . $case->id)
            ->assertExitCode(0)
            ->expectsOutputToContain('queued_count: 1');

        $this->assertSame(1, TestRun::count());
        $run = TestRun::firstOrFail();
        $this->assertSame($case->checklist_item_id, (int) data_get($run->request_payload, 'test_case_id'));
    }

    public function test_command_handles_missing_related_item_safely_without_crashing(): void
    {
        $this->seed(AgentBenchmarkSeeder::class);
        Queue::fake();

        $case = AgentBenchmarkCase::firstOrFail();
        $case->project()->delete();

        $this->artisan('agent:run-benchmark --case-id=' . $case->id . ' --only-missing=0')
            ->assertExitCode(0)
            ->expectsOutputToContain('failed_to_start_count: 1');

        Queue::assertNothingPushed();
    }
}
