<?php

namespace Tests\Feature;

use App\Jobs\ExecuteSingleTestCaseRun;
use App\Models\Checklist;
use App\Models\Project;
use App\Models\TestResult;
use App\Models\ProjectVersion;
use App\Models\TestRun;
use App\Models\User;
use App\Models\VersionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TestCaseRunControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_endpoint_creates_queued_run_and_dispatches_job(): void
    {
        $this->ensureRoles();

        $tester = User::factory()->create();
        $tester->assignRole('testeur');

        $item = $this->createVersionItemForUser($tester);

        Sanctum::actingAs($tester);
        Queue::fake();

        $response = $this->postJson("/api/test-cases/{$item->id}/runs", [
            'base_url' => 'http://example.test',
            'use_auth' => true,
            'watch_mode' => false,
        ]);

        $response->assertStatus(202)
            ->assertJson([
                'status' => 'queued',
            ])
            ->assertJsonStructure(['run_id', 'status']);

        $runId = (string) $response->json('run_id');

        $this->assertDatabaseHas('test_runs', [
            'run_id' => $runId,
            'project_version_id' => $item->project_version_id,
            'status' => 'created',
        ]);

        $run = TestRun::where('run_id', $runId)->first();
        $this->assertNotNull($run);
        $this->assertSame($item->id, (int) data_get($run->request_payload, 'test_case_id'));

        Queue::assertPushed(ExecuteSingleTestCaseRun::class, 1);
    }

    public function test_run_endpoint_rejects_invalid_base_url(): void
    {
        $this->ensureRoles();

        $tester = User::factory()->create();
        $tester->assignRole('testeur');

        $item = $this->createVersionItemForUser($tester);

        Sanctum::actingAs($tester);
        Queue::fake();

        $response = $this->postJson("/api/test-cases/{$item->id}/runs", [
            'base_url' => 'not-a-url',
            'use_auth' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['base_url']);

        $this->assertDatabaseCount('test_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_show_endpoint_hides_stale_error_while_latest_run_is_queued(): void
    {
        $this->ensureRoles();

        $tester = User::factory()->create();
        $tester->assignRole('testeur');

        $item = $this->createVersionItemForUser($tester);

        $failedRun = TestRun::create([
            'run_id' => '11111111-1111-4111-8111-111111111111',
            'project_version_id' => $item->project_version_id,
            'schema_version' => '1.0',
            'base_url' => 'http://example.test',
            'mode' => 'single-test-case',
            'status' => 'failed',
            'requested_by' => $tester->id,
            'summary_total' => 1,
            'summary_passed' => 0,
            'summary_failed' => 0,
            'summary_blocked' => 1,
            'summary_skipped' => 0,
            'request_payload' => [
                'test_case_id' => $item->id,
                'last_error' => 'Runner result did not include the requested test case.',
            ],
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        TestResult::create([
            'test_run_id' => $failedRun->id,
            'version_item_id' => $item->id,
            'status' => 'blocked',
            'error_type' => 'missing_case_result',
            'error_message' => 'Runner result did not include the requested test case.',
            'duration_ms' => null,
            'artifacts' => [
                'trace' => [],
                'screenshot' => [],
                'video' => [],
            ],
            'result_payload' => [
                'external_id' => $item->id,
                'status' => 'blocked',
            ],
            'executed_at' => now()->subMinute(),
        ]);

        $queuedRun = TestRun::create([
            'run_id' => '22222222-2222-4222-8222-222222222222',
            'project_version_id' => $item->project_version_id,
            'schema_version' => '1.0',
            'base_url' => 'http://example.test',
            'mode' => 'single-test-case',
            'status' => 'created',
            'requested_by' => $tester->id,
            'summary_total' => 1,
            'request_payload' => [
                'test_case_id' => $item->id,
            ],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($tester);

        $response = $this->getJson("/api/test-cases/{$item->id}");

        $response->assertOk()
            ->assertJson([
                'execution_state' => 'queued',
                'last_run_id' => $queuedRun->run_id,
                'last_error_message' => null,
            ]);
    }

    private function ensureRoles(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('chef', 'web');
        Role::findOrCreate('testeur', 'web');
    }

    private function createVersionItemForUser(User $owner): VersionItem
    {
        $checklist = Checklist::create([
            'name' => 'Run checklist',
            'description' => 'Seeded for run endpoint tests',
            'is_active' => true,
            'created_by' => $owner->id,
        ]);

        $project = Project::create([
            'name' => 'Run project',
            'description' => 'Project for single run tests',
            'created_by' => $owner->id,
        ]);

        $version = ProjectVersion::create([
            'project_id' => $project->id,
            'checklist_id' => $checklist->id,
            'version_number' => 1,
        ]);

        return VersionItem::create([
            'project_version_id' => $version->id,
            'title' => 'Single run case',
            'description' => 'Should queue and execute',
            'priority' => 'High',
            'criticality' => 'Major',
            'order' => 0,
            'status' => 'Not Tested',
        ]);
    }
}
