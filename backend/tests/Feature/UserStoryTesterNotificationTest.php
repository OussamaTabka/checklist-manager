<?php

namespace Tests\Feature;

use App\Models\Checklist;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use App\Models\UserStory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserStoryTesterNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_testers_receive_user_story_created_notification_only_after_success(): void
    {
        $this->ensureRoles();

        [$chef, $project, $assignedTester, $otherTester, $admin] = $this->createProjectContext();

        Sanctum::actingAs($chef);

        $response = $this->postJson("/api/projects/{$project->id}/user-stories", [
            'title' => 'Connexion par code',
            'description' => 'En tant que client, je veux me connecter avec un code à usage unique.',
        ]);

        $response->assertCreated();

        $storyId = $response->json('id');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $assignedTester->id,
            'type' => 'user_story_created',
            'title' => 'Nouvelle user story disponible',
            'project_id' => $project->id,
            'target_type' => 'user_story',
            'target_id' => $storyId,
            'link' => "/stories/{$storyId}?projectId={$project->id}",
            'is_read' => false,
        ]);

        $notification = Notification::query()
            ->where('user_id', $assignedTester->id)
            ->where('type', 'user_story_created')
            ->firstOrFail();

        $this->assertSame($project->id, $notification->data['project_id']);
        $this->assertSame($storyId, $notification->data['user_story_id']);
        $this->assertFalse($notification->data['impact']['has_impacted_checklists']);
        $this->assertSame(0, $notification->data['impact']['impacted_checklists_count']);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $chef->id,
            'type' => 'user_story_created',
        ]);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $otherTester->id,
            'type' => 'user_story_created',
        ]);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $admin->id,
            'type' => 'user_story_created',
        ]);

        $invalidResponse = $this->postJson("/api/projects/{$project->id}/user-stories", [
            'description' => 'Missing title',
        ]);

        $invalidResponse->assertStatus(422);

        $this->assertSame(
            1,
            Notification::query()->where('type', 'user_story_created')->count()
        );
    }

    public function test_update_notification_includes_impacted_checklist_metadata_for_assigned_testers_only(): void
    {
        $this->ensureRoles();

        [$chef, $project, $assignedTester, $otherTester] = $this->createProjectContext(includeAdmin: false);

        $story = UserStory::create([
            'project_id' => $project->id,
            'title' => 'Paiement CB',
            'description' => 'Story initiale',
            'created_by' => $chef->id,
        ]);

        $attachedChecklist = Checklist::create([
            'name' => 'Checklist paiement',
            'project_id' => $project->id,
            'created_by' => $assignedTester->id,
        ]);

        $generatedChecklist = Checklist::create([
            'name' => 'Checklist générée',
            'project_id' => $project->id,
            'created_by' => $assignedTester->id,
            'source_user_story_id' => $story->id,
        ]);

        $story->checklists()->attach($attachedChecklist->id, [
            'is_generated_from_arxis' => false,
            'relevance_score' => null,
            'link_type' => 'attached',
        ]);

        Sanctum::actingAs($chef);

        $response = $this->putJson("/api/projects/{$project->id}/user-stories/{$story->id}", [
            'title' => 'Paiement CB 3DS',
        ]);

        $response->assertOk();

        $notification = Notification::query()
            ->where('user_id', $assignedTester->id)
            ->where('type', 'user_story_updated')
            ->firstOrFail();

        $this->assertSame('User story modifiée', $notification->title);
        $this->assertStringContainsString('Certaines checklists associées peuvent être impactées', $notification->message);
        $this->assertSame($story->id, $notification->data['user_story_id']);
        $this->assertTrue($notification->data['impact']['has_impacted_checklists']);
        $this->assertSame(2, $notification->data['impact']['impacted_checklists_count']);
        $this->assertSame("/stories/{$story->id}?projectId={$project->id}", $notification->link);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $otherTester->id,
            'type' => 'user_story_updated',
        ]);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $chef->id,
            'type' => 'user_story_updated',
        ]);

        $this->assertNotNull($generatedChecklist->fresh());
    }

    public function test_delete_notification_redirects_to_story_list_and_nulls_story_id(): void
    {
        $this->ensureRoles();

        [$chef, $project, $assignedTester] = $this->createProjectContext(includeAdmin: false, includeOtherTester: false);

        $story = UserStory::create([
            'project_id' => $project->id,
            'title' => 'Suppression compte',
            'description' => 'Story à supprimer',
            'created_by' => $chef->id,
        ]);

        Checklist::create([
            'name' => 'Checklist suppression',
            'project_id' => $project->id,
            'created_by' => $assignedTester->id,
            'source_user_story_id' => $story->id,
        ]);

        Sanctum::actingAs($chef);

        $response = $this->deleteJson("/api/projects/{$project->id}/user-stories/{$story->id}");

        $response->assertNoContent();

        $notification = Notification::query()
            ->where('user_id', $assignedTester->id)
            ->where('type', 'user_story_deleted')
            ->firstOrFail();

        $this->assertSame('User story supprimée', $notification->title);
        $this->assertSame("/stories?projectId={$project->id}", $notification->link);
        $this->assertNull($notification->target_id);
        $this->assertNull($notification->data['user_story_id']);
        $this->assertSame('Suppression compte', $notification->data['deleted_story_title']);
        $this->assertTrue($notification->data['impact']['has_impacted_checklists']);
        $this->assertSame(1, $notification->data['impact']['impacted_checklists_count']);

        $this->assertSoftDeleted('user_stories', [
            'id' => $story->id,
        ]);
    }

    private function createProjectContext(bool $includeAdmin = true, bool $includeOtherTester = true): array
    {
        $chef = User::factory()->create();
        $chef->assignRole('chef');

        $assignedTester = User::factory()->create();
        $assignedTester->assignRole('testeur');

        $project = Project::create([
            'name' => 'Projet notifications',
            'description' => 'Contexte projet',
            'test_objectives' => 'Informer les testeurs',
            'app_url' => 'https://example.test',
            'created_by' => $chef->id,
        ]);
        $project->testers()->attach([$assignedTester->id]);

        $result = [$chef, $project, $assignedTester];

        if ($includeOtherTester) {
            $otherTester = User::factory()->create();
            $otherTester->assignRole('testeur');
            $result[] = $otherTester;
        }

        if ($includeAdmin) {
            $admin = User::factory()->create();
            $admin->assignRole('admin');
            $result[] = $admin;
        }

        return $result;
    }

    private function ensureRoles(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('chef', 'web');
        Role::findOrCreate('testeur', 'web');
    }
}
