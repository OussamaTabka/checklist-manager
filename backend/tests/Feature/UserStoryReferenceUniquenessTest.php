<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\UserStory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserStoryReferenceUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_story_can_be_created_without_reference(): void
    {
        [$chef, $project] = $this->createProjectContext();

        Sanctum::actingAs($chef);

        $response = $this->postJson("/api/projects/{$project->id}/user-stories", [
            'title' => 'Story sans reference',
            'description' => 'Description valide',
            'acceptance_criteria' => 'Given valid input, When saved, Then the story is created',
            'story_id' => '',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('user_stories', [
            'project_id' => $project->id,
            'title' => 'Story sans reference',
        ]);
    }

    public function test_story_creation_rejects_duplicate_reference_in_same_project(): void
    {
        [$chef, $project] = $this->createProjectContext();

        UserStory::create([
            'project_id' => $project->id,
            'title' => 'Story existante',
            'description' => 'Description existante',
            'acceptance_criteria' => 'Given A, When B, Then C',
            'story_id' => 'US-1',
            'created_by' => $chef->id,
        ]);

        Sanctum::actingAs($chef);

        $response = $this->postJson("/api/projects/{$project->id}/user-stories", [
            'title' => 'Story duplicate',
            'description' => 'Description valide',
            'acceptance_criteria' => 'Given valid input, When saved, Then the story is rejected',
            'story_id' => ' us-1 ',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Cette référence existe déjà dans ce projet.');
        $response->assertJsonPath('errors.story_id.0', 'Cette référence existe déjà dans ce projet.');
    }

    public function test_story_creation_allows_same_reference_in_another_project(): void
    {
        [$chef, $project] = $this->createProjectContext('Projet 1');
        [, $otherProject] = $this->createProjectContext('Projet 2', $chef);

        UserStory::create([
            'project_id' => $project->id,
            'title' => 'Story projet 1',
            'description' => 'Description existante',
            'acceptance_criteria' => 'Given A, When B, Then C',
            'story_id' => 'US-1',
            'created_by' => $chef->id,
        ]);

        Sanctum::actingAs($chef);

        $response = $this->postJson("/api/projects/{$otherProject->id}/user-stories", [
            'title' => 'Story projet 2',
            'description' => 'Description valide',
            'acceptance_criteria' => 'Given valid input, When saved, Then the story is created',
            'story_id' => 'us-1',
        ]);

        $response->assertCreated();
    }

    public function test_story_update_rejects_reference_used_by_another_story_in_same_project(): void
    {
        [$chef, $project] = $this->createProjectContext();

        $firstStory = UserStory::create([
            'project_id' => $project->id,
            'title' => 'Story 1',
            'description' => 'Description 1',
            'acceptance_criteria' => 'Given A, When B, Then C',
            'story_id' => 'US-1',
            'created_by' => $chef->id,
        ]);

        $secondStory = UserStory::create([
            'project_id' => $project->id,
            'title' => 'Story 2',
            'description' => 'Description 2',
            'acceptance_criteria' => 'Given D, When E, Then F',
            'story_id' => 'US-2',
            'created_by' => $chef->id,
        ]);

        Sanctum::actingAs($chef);

        $response = $this->putJson("/api/projects/{$project->id}/user-stories/{$secondStory->id}", [
            'story_id' => ' us-1 ',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Cette référence existe déjà dans ce projet.');
        $response->assertJsonPath('errors.story_id.0', 'Cette référence existe déjà dans ce projet.');

        $this->assertSame('US-2', $secondStory->fresh()->story_id);
        $this->assertSame('US-1', $firstStory->fresh()->story_id);
    }

    public function test_story_update_keeps_its_own_reference(): void
    {
        [$chef, $project] = $this->createProjectContext();

        $story = UserStory::create([
            'project_id' => $project->id,
            'title' => 'Story 1',
            'description' => 'Description 1',
            'acceptance_criteria' => 'Given A, When B, Then C',
            'story_id' => 'US-1',
            'created_by' => $chef->id,
        ]);

        Sanctum::actingAs($chef);

        $response = $this->putJson("/api/projects/{$project->id}/user-stories/{$story->id}", [
            'title' => 'Story 1 mise a jour',
            'story_id' => ' us-1 ',
        ]);

        $response->assertOk();
        $this->assertSame(' us-1 ', $response->json('story_id'));
    }

    private function createProjectContext(string $projectName = 'Projet references', ?User $chef = null): array
    {
        $this->ensureRoles();

        $chef ??= User::factory()->create();
        if (!$chef->hasRole('chef')) {
            $chef->assignRole('chef');
        }

        $project = Project::create([
            'name' => $projectName,
            'description' => 'Contexte projet',
            'test_objectives' => 'Verifier les references',
            'app_url' => 'https://example.test',
            'created_by' => $chef->id,
        ]);

        return [$chef, $project];
    }

    private function ensureRoles(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('chef', 'web');
        Role::findOrCreate('testeur', 'web');
    }
}
