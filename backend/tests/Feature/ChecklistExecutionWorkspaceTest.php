<?php

namespace Tests\Feature;

use App\Jobs\ExecuteSingleTestCaseRun;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\ChecklistItemHistory;
use App\Models\Project;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChecklistExecutionWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_tester_can_add_comment_to_checklist_item_and_history_is_logged(): void
    {
        $this->ensureRoles();

        $tester = User::factory()->create();
        $tester->assignRole('testeur');

        [$checklist, $item] = $this->createChecklistItem($tester);

        Sanctum::actingAs($tester);

        $response = $this->patchJson("/api/checklists/{$checklist->id}/items/{$item->id}/comment", [
            'comment' => 'Le message de confirmation est affiche correctement.',
        ]);

        $response->assertOk()
            ->assertJsonPath('comment', 'Le message de confirmation est affiche correctement.')
            ->assertJsonPath('history.0.action_type', 'comment_added');

        $this->assertDatabaseHas('checklist_items', [
            'id' => $item->id,
            'qa_comment' => 'Le message de confirmation est affiche correctement.',
        ]);

        $this->assertDatabaseHas('checklist_item_histories', [
            'checklist_item_id' => $item->id,
            'change_type' => 'comment_added',
            'field_name' => 'qa_comment',
            'new_value' => 'Le message de confirmation est affiche correctement.',
        ]);
    }

    public function test_tester_can_update_comment_and_fetch_current_comment_payload(): void
    {
        $this->ensureRoles();

        $tester = User::factory()->create();
        $tester->assignRole('testeur');

        [$checklist, $item] = $this->createChecklistItem($tester, [
            'qa_comment' => 'Ancien commentaire',
        ]);

        ChecklistItemHistory::create([
            'checklist_item_id' => $item->id,
            'changed_by' => $tester->id,
            'field_name' => 'qa_comment',
            'old_value' => null,
            'new_value' => 'Ancien commentaire',
            'change_type' => 'comment_added',
            'notes' => null,
        ]);

        Sanctum::actingAs($tester);

        $updateResponse = $this->patchJson("/api/checklists/{$checklist->id}/items/{$item->id}/comment", [
            'comment' => 'Commentaire mis a jour',
        ]);

        $updateResponse->assertOk()
            ->assertJsonPath('comment', 'Commentaire mis a jour');

        $getResponse = $this->getJson("/api/checklists/{$checklist->id}/items/{$item->id}/comment");

        $getResponse->assertOk()
            ->assertJsonPath('comment', 'Commentaire mis a jour')
            ->assertJsonPath('updated_by.id', $tester->id);

        $this->assertDatabaseHas('checklist_item_histories', [
            'checklist_item_id' => $item->id,
            'change_type' => 'comment_updated',
            'old_value' => 'Ancien commentaire',
            'new_value' => 'Commentaire mis a jour',
        ]);
    }

    public function test_run_endpoint_logs_automated_test_started_and_history_endpoint_returns_action_type(): void
    {
        $this->ensureRoles();

        $tester = User::factory()->create();
        $tester->assignRole('testeur');

        [$checklist, $item] = $this->createChecklistItem($tester);

        Sanctum::actingAs($tester);
        Queue::fake();

        $runResponse = $this->postJson("/api/checklists/{$checklist->id}/items/{$item->id}/runs", [
            'base_url' => 'http://example.test',
            'use_auth' => true,
            'watch_mode' => false,
        ]);

        $runResponse->assertStatus(202)
            ->assertJsonPath('status', 'queued');

        Queue::assertPushed(ExecuteSingleTestCaseRun::class, 1);

        $this->assertDatabaseHas('checklist_item_histories', [
            'checklist_item_id' => $item->id,
            'change_type' => 'automated_test_started',
            'field_name' => 'execution',
        ]);

        $historyResponse = $this->getJson("/api/checklists/{$checklist->id}/items/{$item->id}/history");

        $historyResponse->assertOk()
            ->assertJsonPath('0.action_type', 'automated_test_started');
    }

    public function test_project_manager_can_view_project_checklist_execution_workspace(): void
    {
        $this->ensureRoles();

        $chef = User::factory()->create();
        $chef->assignRole('chef');

        $project = Project::create([
            'name' => 'Execution project',
            'description' => 'Project for execution workspace access',
            'created_by' => $chef->id,
        ]);

        $checklist = Checklist::create([
            'name' => 'Execution checklist',
            'description' => 'Project checklist',
            'project_id' => $project->id,
            'created_by' => $chef->id,
            'is_active' => true,
        ]);

        ChecklistItem::create([
            'checklist_id' => $checklist->id,
            'title' => 'Scenario 1',
            'description' => 'Validate workspace visibility',
            'priority' => 'High',
            'criticality' => 'Major',
            'status' => 'pending',
            'order' => 0,
        ]);

        Sanctum::actingAs($chef);

        $response = $this->getJson("/api/checklists/{$checklist->id}");

        $response->assertOk()
            ->assertJsonPath('id', $checklist->id);
    }

    public function test_execution_endpoint_returns_live_trace_for_running_run(): void
    {
        $this->ensureRoles();

        $tester = User::factory()->create();
        $tester->assignRole('testeur');

        [$checklist, $item] = $this->createChecklistItem($tester);

        $run = TestRun::create([
            'run_id' => '33333333-3333-4333-8333-333333333333',
            'project_version_id' => null,
            'checklist_id' => $checklist->id,
            'schema_version' => '1.0',
            'base_url' => 'http://example.test',
            'mode' => 'single-test-case',
            'status' => 'running',
            'requested_by' => $tester->id,
            'summary_total' => 1,
            'request_payload' => [
                'target_type' => 'checklist_item',
                'test_case_id' => $item->id,
                'base_url' => 'http://example.test',
            ],
            'started_at' => now(),
        ]);

        $workspaceRoot = realpath(base_path('..'));
        $this->assertNotFalse($workspaceRoot);

        $runDirectory = $workspaceRoot . DIRECTORY_SEPARATOR . 'runs' . DIRECTORY_SEPARATOR . $run->run_id;
        if (!is_dir($runDirectory)) {
            mkdir($runDirectory, 0777, true);
        }

        file_put_contents($runDirectory . DIRECTORY_SEPARATOR . 'live-trace.json', json_encode([
            'schema_version' => '1.0',
            'run_id' => $run->run_id,
            'status' => 'running',
            'cases' => [
                [
                    'external_id' => $item->id,
                    'title' => $item->title,
                    'status' => 'running',
                    'execution_trace' => [
                        'Planning: browser context starting',
                        'Step 1: goto /login -> ok',
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        Sanctum::actingAs($tester);

        $response = $this->getJson("/api/checklists/{$checklist->id}/items/{$item->id}/execution");

        $response->assertOk()
            ->assertJsonPath('execution_state', 'running')
            ->assertJsonPath('tested_base_url', 'http://example.test')
            ->assertJsonPath('execution_trace.0', 'Planning: browser context starting')
            ->assertJsonPath('execution_trace.1', 'Step 1: goto /login -> ok');
    }

    public function test_execution_endpoint_returns_artifact_lists_for_completed_run(): void
    {
        $this->ensureRoles();

        $tester = User::factory()->create();
        $tester->assignRole('testeur');

        [$checklist, $item] = $this->createChecklistItem($tester);

        $run = TestRun::create([
            'run_id' => '44444444-4444-4444-8444-444444444444',
            'project_version_id' => null,
            'checklist_id' => $checklist->id,
            'schema_version' => '1.0',
            'base_url' => 'http://example.test',
            'mode' => 'single-test-case',
            'status' => 'completed',
            'requested_by' => $tester->id,
            'summary_total' => 1,
            'request_payload' => [
                'target_type' => 'checklist_item',
                'test_case_id' => $item->id,
                'base_url' => 'http://example.test',
            ],
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        TestResult::create([
            'test_run_id' => $run->id,
            'checklist_item_id' => $item->id,
            'status' => 'failed',
            'error_type' => 'selector_not_found',
            'error_message' => 'A required button, field, or selector was not found on the page.',
            'duration_ms' => 2400,
            'artifacts' => [
                'trace' => ['runs/' . $run->run_id . '/artifacts/' . $item->id . '/trace.zip'],
                'screenshot' => ['runs/' . $run->run_id . '/artifacts/' . $item->id . '/fail.png'],
                'video' => [],
                'raw_paths' => [
                    'trace_path' => 'runs/' . $run->run_id . '/artifacts/' . $item->id . '/trace.zip',
                    'screenshot_path' => 'runs/' . $run->run_id . '/artifacts/' . $item->id . '/fail.png',
                    'video_path' => null,
                ],
            ],
            'result_payload' => [
                'external_id' => $item->id,
                'status' => 'failed',
                'generated_plan' => [
                    'steps' => [
                        ['action' => 'goto', 'url' => 'http://example.test'],
                    ],
                    'asserts' => [],
                ],
                'failure_source' => [
                    'phase' => 'step',
                    'reference' => 'click button[type="submit"]',
                    'message' => 'selector missing',
                ],
            ],
            'executed_at' => now(),
        ]);

        Sanctum::actingAs($tester);

        $response = $this->getJson("/api/checklists/{$checklist->id}/items/{$item->id}/execution");

        $response->assertOk()
            ->assertJsonPath('tested_base_url', 'http://example.test')
            ->assertJsonPath('artifacts.trace.0', 'runs/' . $run->run_id . '/artifacts/' . $item->id . '/trace.zip')
            ->assertJsonPath('artifacts.all.1', 'runs/' . $run->run_id . '/artifacts/' . $item->id . '/fail.png')
            ->assertJsonPath('failure_source.phase', 'step');
    }

    public function test_unassigned_tester_cannot_update_project_checklist_comment(): void
    {
        $this->ensureRoles();

        $chef = User::factory()->create();
        $chef->assignRole('chef');

        $assignedTester = User::factory()->create();
        $assignedTester->assignRole('testeur');

        $otherTester = User::factory()->create();
        $otherTester->assignRole('testeur');

        $project = Project::create([
            'name' => 'Restricted checklist project',
            'description' => 'Only assigned testers should access checklist execution endpoints',
            'created_by' => $chef->id,
        ]);
        $project->testers()->attach($assignedTester->id);

        $checklist = Checklist::create([
            'name' => 'Restricted execution checklist',
            'description' => 'Project checklist',
            'project_id' => $project->id,
            'created_by' => $assignedTester->id,
            'is_active' => true,
        ]);

        $item = ChecklistItem::create([
            'checklist_id' => $checklist->id,
            'title' => 'Scenario 1',
            'description' => 'Validate access control',
            'priority' => 'High',
            'criticality' => 'Major',
            'status' => 'pending',
            'order' => 0,
        ]);

        Sanctum::actingAs($otherTester);

        $response = $this->patchJson("/api/checklists/{$checklist->id}/items/{$item->id}/comment", [
            'comment' => 'Je ne devrais pas pouvoir modifier ce commentaire.',
        ]);

        $response->assertForbidden();
    }

    private function ensureRoles(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('chef', 'web');
        Role::findOrCreate('testeur', 'web');
    }

    private function createChecklistItem(User $owner, array $itemOverrides = []): array
    {
        $checklist = Checklist::create([
            'name' => 'Execution checklist',
            'description' => 'Checklist for execution workspace tests',
            'created_by' => $owner->id,
            'is_active' => true,
        ]);

        $item = ChecklistItem::create(array_merge([
            'checklist_id' => $checklist->id,
            'title' => 'TC checkout',
            'description' => 'Validate checkout flow',
            'priority' => 'High',
            'criticality' => 'Major',
            'status' => 'pending',
            'order' => 0,
        ], $itemOverrides));

        return [$checklist, $item];
    }
}
