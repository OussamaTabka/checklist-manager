<?php

namespace Tests\Feature;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\User;
use App\Models\VersionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SecurityFlowsTest extends TestCase
{
    use RefreshDatabase;

    public function test_chef_cannot_create_version_for_other_chef_project(): void
    {
        $this->ensureRoles();

        $ownerChef = User::factory()->create();
        $ownerChef->assignRole('chef');

        $otherChef = User::factory()->create();
        $otherChef->assignRole('chef');

        $checklist = Checklist::create([
            'name' => 'QA Checklist',
            'description' => 'Base checklist',
            'is_active' => true,
            'created_by' => $ownerChef->id,
        ]);

        ChecklistItem::create([
            'checklist_id' => $checklist->id,
            'title' => 'Smoke login',
            'description' => 'Validate login page',
            'priority' => 'High',
            'criticality' => 'Major',
            'order' => 0,
        ]);

        $project = Project::create([
            'name' => 'Owner Project',
            'description' => 'Private project',
            'created_by' => $ownerChef->id,
        ]);

        Sanctum::actingAs($otherChef);

        $response = $this->postJson("/api/projects/{$project->id}/versions", [
            'checklist_id' => $checklist->id,
        ]);

        $response->assertForbidden();
    }

    public function test_login_is_throttled_after_repeated_failures(): void
    {
        User::factory()->create([
            'email' => 'throttle@test.local',
            'password' => bcrypt('valid-password'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'email' => 'throttle@test.local',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->postJson('/api/login', [
            'email' => 'throttle@test.local',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_testeur_can_export_project_version_csv(): void
    {
        $this->ensureRoles();

        $chef = User::factory()->create();
        $chef->assignRole('chef');

        $tester = User::factory()->create();
        $tester->assignRole('testeur');

        $checklist = Checklist::create([
            'name' => 'Release Checklist',
            'description' => 'Release flow',
            'is_active' => true,
            'created_by' => $chef->id,
        ]);

        $project = Project::create([
            'name' => 'API Platform',
            'description' => 'Service backend',
            'created_by' => $chef->id,
        ]);
        $project->testers()->attach($tester->id);

        $version = ProjectVersion::create([
            'project_id' => $project->id,
            'checklist_id' => $checklist->id,
            'version_number' => 1,
        ]);

        VersionItem::create([
            'project_version_id' => $version->id,
            'title' => 'Validate auth',
            'description' => 'Token flow must pass',
            'priority' => 'High',
            'criticality' => 'Critical',
            'order' => 0,
            'status' => 'Passed',
            'tested_by' => $tester->id,
            'tested_at' => now(),
        ]);

        Sanctum::actingAs($tester);

        $response = $this->get("/api/project-versions/{$version->id}/export/csv");

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_chef_cannot_export_project_version_of_another_chef_project(): void
    {
        $this->ensureRoles();

        $ownerChef = User::factory()->create();
        $ownerChef->assignRole('chef');

        $otherChef = User::factory()->create();
        $otherChef->assignRole('chef');

        $assignedTester = User::factory()->create();
        $assignedTester->assignRole('testeur');

        $checklist = Checklist::create([
            'name' => 'Restricted export checklist',
            'description' => 'Export should be limited to project members',
            'is_active' => true,
            'created_by' => $ownerChef->id,
        ]);

        $project = Project::create([
            'name' => 'Restricted export project',
            'description' => 'Project owned by another chef',
            'created_by' => $ownerChef->id,
        ]);
        $project->testers()->attach($assignedTester->id);

        $version = ProjectVersion::create([
            'project_id' => $project->id,
            'checklist_id' => $checklist->id,
            'version_number' => 1,
        ]);

        Sanctum::actingAs($otherChef);

        $response = $this->get("/api/project-versions/{$version->id}/export/csv");

        $response->assertForbidden();
    }

    private function ensureRoles(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('chef', 'web');
        Role::findOrCreate('testeur', 'web');
    }
}
