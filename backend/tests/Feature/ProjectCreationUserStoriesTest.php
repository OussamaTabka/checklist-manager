<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProjectCreationUserStoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_chef_can_create_project_with_imported_user_stories_only(): void
    {
        $this->ensureRoles();

        $chef = User::factory()->create();
        $chef->assignRole('chef');

        $tester = User::factory()->create();
        $tester->assignRole('testeur');

        Sanctum::actingAs($chef);

        $file = UploadedFile::fake()->createWithContent(
            'stories.csv',
            implode("\n", [
                'title,description,acceptance_criteria,reference,priority,status',
                '"Imported Story","Imported description","Given valid data, When submitted, Then saved","US-IMP-01","high","backlog"',
                '"Broken Story","","","US-IMP-02","medium","backlog"',
            ])
        );

        $response = $this->post('/api/projects', [
            'name' => 'Bulk Project',
            'description' => 'Project with mixed story sources',
            'test_objectives' => 'Validate combined creation',
            'app_url' => 'https://example.test',
            'tester_ids' => [$tester->id],
            'user_stories_file' => $file,
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('project.name', 'Bulk Project');
        $response->assertJsonPath('user_stories_summary.manual_created', 0);
        $response->assertJsonPath('user_stories_summary.imported_created', 1);
        $response->assertJsonPath('user_stories_summary.total_created', 1);
        $response->assertJsonPath('user_stories_summary.failed_imports', 1);

        $project = Project::firstOrFail();

        $this->assertDatabaseHas('user_stories', [
            'project_id' => $project->id,
            'title' => 'Imported Story',
            'story_id' => 'US-IMP-01',
            'priority' => 'high',
            'status' => 'backlog',
        ]);
    }

    public function test_project_creation_is_rejected_when_no_valid_user_story_exists(): void
    {
        $this->ensureRoles();

        $chef = User::factory()->create();
        $chef->assignRole('chef');

        $tester = User::factory()->create();
        $tester->assignRole('testeur');

        Sanctum::actingAs($chef);

        $file = UploadedFile::fake()->createWithContent(
            'stories.csv',
            implode("\n", [
                'title,description,acceptance_criteria',
                '"","",""',
            ])
        );

        $response = $this->post('/api/projects', [
            'name' => 'Rejected Project',
            'description' => 'Invalid stories only',
            'test_objectives' => 'Should fail',
            'app_url' => 'https://example.test',
            'tester_ids' => [$tester->id],
            'user_stories_file' => $file,
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath(
            'message',
            'Vous devez importer un fichier contenant au moins une User Story valide.'
        );

        $this->assertDatabaseMissing('projects', [
            'name' => 'Rejected Project',
        ]);
    }

    public function test_project_creation_requires_user_story_file(): void
    {
        $this->ensureRoles();

        $chef = User::factory()->create();
        $chef->assignRole('chef');

        $tester = User::factory()->create();
        $tester->assignRole('testeur');

        Sanctum::actingAs($chef);

        $response = $this->post('/api/projects', [
            'name' => 'Missing File Project',
            'description' => 'No file attached',
            'test_objectives' => 'Should fail',
            'app_url' => 'https://example.test',
            'tester_ids' => [$tester->id],
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['user_stories_file']);
    }

    private function ensureRoles(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('chef', 'web');
        Role::findOrCreate('testeur', 'web');
    }
}
