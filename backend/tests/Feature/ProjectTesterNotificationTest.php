<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProjectTesterNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tester_receives_database_notification_when_project_is_created(): void
    {
        $this->ensureRoles();

        $chef = User::factory()->create();
        $chef->assignRole('chef');

        $tester = User::factory()->create();
        $tester->assignRole('testeur');

        Sanctum::actingAs($chef);

        $response = $this->postJson('/api/projects', [
            'name' => 'Portal QA',
            'description' => 'Project notification check',
            'test_objectives' => 'Validate assignment notification',
            'app_url' => 'https://example.test',
            'tester_ids' => [$tester->id],
            'user_stories_file' => \Illuminate\Http\UploadedFile::fake()->createWithContent(
                'stories.csv',
                implode("\n", [
                    'title,description,acceptance_criteria',
                    '"Story one","Desc","Given x, When y, Then z"',
                ])
            ),
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $tester->id,
            'type' => 'App\\Notifications\\TesterAssignedToProjectNotification',
        ]);
    }

    public function test_only_newly_attached_tester_receives_notification_on_update(): void
    {
        $this->ensureRoles();

        $chef = User::factory()->create();
        $chef->assignRole('chef');

        $existingTester = User::factory()->create();
        $existingTester->assignRole('testeur');

        $newTester = User::factory()->create();
        $newTester->assignRole('testeur');

        $project = Project::create([
            'name' => 'Assigned project',
            'description' => 'Testing notifications',
            'test_objectives' => 'Keep testers informed',
            'created_by' => $chef->id,
            'app_url' => 'https://example.test',
        ]);
        $project->testers()->attach([$existingTester->id]);

        Sanctum::actingAs($chef);

        $response = $this->putJson("/api/projects/{$project->id}", [
            'tester_ids' => [$existingTester->id, $newTester->id],
        ]);

        $response->assertOk();

        $this->assertDatabaseMissing('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $existingTester->id,
            'type' => 'App\\Notifications\\TesterAssignedToProjectNotification',
        ]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $newTester->id,
            'type' => 'App\\Notifications\\TesterAssignedToProjectNotification',
        ]);
    }

    private function ensureRoles(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('chef', 'web');
        Role::findOrCreate('testeur', 'web');
    }
}
