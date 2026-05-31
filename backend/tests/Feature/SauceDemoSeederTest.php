<?php

namespace Tests\Feature;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Project;
use App\Models\UserStory;
use Database\Seeders\RolesAndAdminSeeder;
use Database\Seeders\SauceDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SauceDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_the_expected_demo_dataset_and_is_idempotent(): void
    {
        $this->seed(RolesAndAdminSeeder::class);
        $this->seed(SauceDemoSeeder::class);

        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('user_stories', 6);
        $this->assertDatabaseCount('checklists', 6);
        $this->assertDatabaseCount('user_story_checklists', 6);
        $this->assertDatabaseCount('project_checklists', 6);
        $this->assertDatabaseCount('checklist_items', 34);

        $project = Project::where('name', 'SauceDemo — Swag Labs')->firstOrFail();
        $this->assertSame('https://www.saucedemo.com/', $project->app_url);
        $this->assertSame(6, $project->checklists()->count());
        $this->assertSame(6, $project->userStories()->count());

        $this->seed(SauceDemoSeeder::class);

        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('user_stories', 6);
        $this->assertDatabaseCount('checklists', 6);
        $this->assertDatabaseCount('user_story_checklists', 6);
        $this->assertDatabaseCount('project_checklists', 6);
        $this->assertDatabaseCount('checklist_items', 34);

        $this->assertSame(
            34,
            ChecklistItem::query()->count()
        );
        $this->assertSame(
            6,
            Checklist::query()->count()
        );
        $this->assertSame(
            6,
            UserStory::query()->count()
        );
    }
}