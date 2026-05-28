<?php

namespace Tests\Feature;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Project;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\User;
use Database\Seeders\RolesAndAdminSeeder;
use Database\Seeders\SauceDemoExecutionWorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SauceDemoExecutionWorkspaceSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_project_assignment_checklist_run_and_result(): void
    {
        $this->seedScenario();

        $project = Project::where('name', 'Sauce Demo Execution Workspace')->firstOrFail();
        $tester = User::where('email', 'testeur@test.com')->firstOrFail();
        $checklist = Checklist::where('project_id', $project->id)
            ->where('name', 'Sauce Demo Login Validation Checklist')
            ->firstOrFail();
        $item = ChecklistItem::where('checklist_id', $checklist->id)
            ->where('title', 'TC-01 | Successful login page validation')
            ->firstOrFail();
        $run = TestRun::where('run_id', '11111111-1111-4111-8111-111111111111')->firstOrFail();
        $result = TestResult::where('test_run_id', $run->id)
            ->where('checklist_item_id', $item->id)
            ->firstOrFail();

        $this->assertSame('https://www.saucedemo.com', $project->app_url);
        $this->assertTrue($project->testers()->where('users.id', $tester->id)->exists());
        $this->assertSame('completed', $run->status);
        $this->assertSame('https://www.saucedemo.com', $run->base_url);
        $this->assertSame('checklist_item', data_get($run->request_payload, 'target_type'));
        $this->assertSame($item->id, data_get($run->request_payload, 'test_case_id'));
        $this->assertSame('passed', $result->status);
        $this->assertSame('https://www.saucedemo.com', $project->fresh()->app_url);
        $this->assertNotEmpty(data_get($result->result_payload, 'generated_plan.steps'));
        $this->assertNotEmpty(data_get($result->result_payload, 'generated_plan.asserts'));
        $this->assertNotEmpty(data_get($result->result_payload, 'execution_trace'));
    }

    public function test_assigned_tester_can_fetch_seeded_execution_payload_for_frontend_panel(): void
    {
        $this->seedScenario();

        $tester = User::where('email', 'testeur@test.com')->firstOrFail();
        $checklist = Checklist::where('name', 'Sauce Demo Login Validation Checklist')->firstOrFail();
        $item = ChecklistItem::where('checklist_id', $checklist->id)
            ->where('title', 'TC-01 | Successful login page validation')
            ->firstOrFail();

        Sanctum::actingAs($tester);

        $response = $this->getJson("/api/checklists/{$checklist->id}/items/{$item->id}/execution");

        $response->assertOk()
            ->assertJsonPath('tested_base_url', 'https://www.saucedemo.com')
            ->assertJsonPath('last_run_id', '11111111-1111-4111-8111-111111111111')
            ->assertJsonPath('last_run_status', 'completed')
            ->assertJsonPath('execution_state', 'passed')
            ->assertJsonPath('generated_plan.steps.0.action', 'goto')
            ->assertJsonPath('generated_plan.asserts.0.selector', '.title')
            ->assertJsonPath('execution_trace.0', '[chromium] Planning: precondition -> Target base URL must be reachable: https://www.saucedemo.com');
    }

    public function test_unassigned_tester_cannot_access_seeded_execution_workspace(): void
    {
        $this->seedScenario();

        $otherTester = User::factory()->create([
            'account_status' => 'active',
        ]);
        $otherTester->assignRole('testeur');

        $checklist = Checklist::where('name', 'Sauce Demo Login Validation Checklist')->firstOrFail();
        $item = ChecklistItem::where('checklist_id', $checklist->id)
            ->where('title', 'TC-01 | Successful login page validation')
            ->firstOrFail();

        Sanctum::actingAs($otherTester);

        $response = $this->getJson("/api/checklists/{$checklist->id}/items/{$item->id}/execution");

        $response->assertForbidden();
    }

    private function seedScenario(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('chef', 'web');
        Role::findOrCreate('testeur', 'web');

        $this->seed(RolesAndAdminSeeder::class);
        $this->seed(SauceDemoExecutionWorkspaceSeeder::class);
    }
}
