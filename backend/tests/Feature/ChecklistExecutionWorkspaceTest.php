<?php

namespace Tests\Feature;

use App\Jobs\ExecuteSingleTestCaseRun;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\ChecklistItemHistory;
use App\Models\Project;
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

        $this->assertDatabaseHas('checklist_item_history', [
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

        $this->assertDatabaseHas('checklist_item_history', [
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

        $this->assertDatabaseHas('checklist_item_history', [
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
