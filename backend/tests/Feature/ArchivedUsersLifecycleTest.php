<?php

namespace Tests\Feature;

use App\Models\Checklist;
use App\Models\Project;
use App\Models\User;
use App\Models\UserStory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ArchivedUsersLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_archived_users_are_excluded_from_active_list_and_visible_in_archived_list(): void
    {
        $this->ensureRoles();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $activeUser = User::factory()->create(['account_status' => 'active']);
        $activeUser->assignRole('testeur');

        $archivedUser = User::factory()->create([
            'account_status' => 'disabled',
            'archived_previous_status' => 'active',
            'archived_at' => now(),
        ]);
        $archivedUser->assignRole('testeur');

        Sanctum::actingAs($admin);

        $this->getJson('/api/users?status=active')
            ->assertOk()
            ->assertJsonMissing(['id' => $archivedUser->id])
            ->assertJsonFragment(['id' => $activeUser->id]);

        $this->getJson('/api/users?status=archived')
            ->assertOk()
            ->assertJsonFragment(['id' => $archivedUser->id])
            ->assertJsonMissing(['id' => $activeUser->id]);
    }

    public function test_restoring_an_archived_user_uses_the_previous_status_or_defaults_to_active(): void
    {
        $this->ensureRoles();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $pendingUser = User::factory()->create([
            'account_status' => 'disabled',
            'archived_previous_status' => 'pending',
            'archived_at' => now(),
        ]);
        $pendingUser->assignRole('testeur');

        $legacyUser = User::factory()->create([
            'account_status' => 'disabled',
            'archived_previous_status' => null,
            'archived_at' => now(),
        ]);
        $legacyUser->assignRole('testeur');

        Sanctum::actingAs($admin);

        $this->postJson("/api/users/{$pendingUser->id}/restore")
            ->assertOk()
            ->assertJsonPath('user.account_status', 'pending');

        $this->postJson("/api/users/{$legacyUser->id}/restore")
            ->assertOk()
            ->assertJsonPath('user.account_status', 'active');
    }

    public function test_permanent_delete_is_blocked_when_user_owns_protected_business_data(): void
    {
        $this->ensureRoles();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $owner = User::factory()->create([
            'account_status' => 'disabled',
            'archived_previous_status' => 'active',
            'archived_at' => now(),
        ]);
        $owner->assignRole('chef');

        $project = Project::create([
            'name' => 'Protected Project',
            'description' => 'Owned by archived user',
            'app_url' => 'https://example.test',
            'test_objectives' => 'Protect ownership',
            'created_by' => $owner->id,
        ]);

        Checklist::create([
            'name' => 'Protected Checklist',
            'description' => 'Owned by archived user',
            'is_active' => true,
            'created_by' => $owner->id,
        ]);

        UserStory::create([
            'project_id' => $project->id,
            'title' => 'Protected Story',
            'description' => 'Owned by archived user',
            'acceptance_criteria' => 'Should block deletion',
            'created_by' => $owner->id,
        ]);

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/users/{$owner->id}/permanent")
            ->assertStatus(422)
            ->assertJsonPath('ownership_counts.projects', 1)
            ->assertJsonPath('ownership_counts.checklists', 1)
            ->assertJsonPath('ownership_counts.user_stories', 1);
    }

    private function ensureRoles(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('chef', 'web');
        Role::findOrCreate('testeur', 'web');
    }
}
