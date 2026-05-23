<?php

namespace Tests\Feature;

use App\Models\Checklist;
use App\Models\Comment;
use App\Models\ItemChange;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\User;
use App\Models\UserStory;
use App\Models\VersionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProjectReportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_responsible_chef_can_export_summary_pdf(): void
    {
        [$chef, $project] = $this->seedProjectReportFixture();

        Sanctum::actingAs($chef);

        $response = $this->get("/api/projects/{$project->id}/reports/export?format=pdf&type=summary");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('content-disposition'));
    }

    public function test_responsible_chef_can_export_detailed_json_with_expected_kpis(): void
    {
        [$chef, $project] = $this->seedProjectReportFixture();

        Sanctum::actingAs($chef);

        $response = $this->get("/api/projects/{$project->id}/reports/export?format=json&type=detailed");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/json; charset=UTF-8');

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('json', $payload['meta']['format']);
        $this->assertSame('detailed', $payload['meta']['type']);
        $this->assertSame(4, $payload['summary']['total_test_cases']);
        $this->assertSame(3, $payload['summary']['executed_tests']);
        $this->assertSame(1, $payload['summary']['not_tested']);
        $this->assertSame(1, $payload['summary']['passed']);
        $this->assertSame(1, $payload['summary']['failed']);
        $this->assertSame(1, $payload['summary']['blocked']);
        $this->assertSame(75.0, $payload['summary']['execution_rate']);
        $this->assertSame(33.33, $payload['summary']['pass_rate']);
        $this->assertSame(2, $payload['summary']['risk_count']);
        $this->assertCount(2, $payload['failed_and_blocked']);
        $this->assertNotEmpty($payload['comments']);
        $this->assertNotEmpty($payload['execution_history']);
        $this->assertNotEmpty($payload['automation_results']);
    }

    public function test_filters_can_exclude_optional_sections_from_json(): void
    {
        [$chef, $project] = $this->seedProjectReportFixture();

        Sanctum::actingAs($chef);

        $response = $this->get(
            "/api/projects/{$project->id}/reports/export?format=json&type=detailed&include_comments=0&include_history=0&include_automation_traces=0"
        );

        $response->assertOk();

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([], $payload['comments']);
        $this->assertSame([], $payload['execution_history']);
        $this->assertSame([], $payload['automation_results']);
    }

    public function test_tester_cannot_export_project_report(): void
    {
        [$chef, $project, $tester] = $this->seedProjectReportFixture();

        Sanctum::actingAs($tester);

        $this->get("/api/projects/{$project->id}/reports/export?format=json&type=detailed")
            ->assertForbidden();
    }

    public function test_other_chef_cannot_export_project_report(): void
    {
        [, $project] = $this->seedProjectReportFixture();

        $otherChef = User::factory()->create();
        $otherChef->assignRole('chef');

        Sanctum::actingAs($otherChef);

        $this->get("/api/projects/{$project->id}/reports/export?format=json&type=detailed")
            ->assertForbidden();
    }

    public function test_admin_even_with_chef_role_cannot_export_project_report(): void
    {
        [, $project] = $this->seedProjectReportFixture();

        $adminChef = User::factory()->create();
        $adminChef->assignRole('admin');
        $adminChef->assignRole('chef');

        Sanctum::actingAs($adminChef);

        $this->get("/api/projects/{$project->id}/reports/export?format=json&type=detailed")
            ->assertForbidden();
    }

    public function test_excel_export_returns_not_implemented(): void
    {
        [$chef, $project] = $this->seedProjectReportFixture();

        Sanctum::actingAs($chef);

        $this->get("/api/projects/{$project->id}/reports/export?format=xlsx&type=detailed")
            ->assertStatus(501)
            ->assertJsonPath('message', 'Le format Excel n\'est pas encore disponible.');
    }

    public function test_detailed_pdf_request_is_rejected(): void
    {
        [$chef, $project] = $this->seedProjectReportFixture();

        Sanctum::actingAs($chef);

        $this->get("/api/projects/{$project->id}/reports/export?format=pdf&type=detailed")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Le PDF detaille n\'est pas disponible pour le moment.');
    }

    private function seedProjectReportFixture(): array
    {
        $this->ensureRoles();

        $chef = User::factory()->create();
        $chef->assignRole('chef');

        $tester = User::factory()->create();
        $tester->assignRole('testeur');

        $project = Project::create([
            'name' => 'IntelliTest Core',
            'description' => 'Project reporting validation target',
            'test_objectives' => 'Validate synthetic and detailed exports',
            'app_url' => 'https://example.test',
            'created_by' => $chef->id,
        ]);

        $project->testers()->attach($tester->id);

        $story = UserStory::create([
            'project_id' => $project->id,
            'title' => 'Secure report export',
            'description' => 'Only the responsible project manager can export reports.',
            'acceptance_criteria' => '403 for unauthorized users',
            'status' => 'ready_for_test',
            'priority' => 'high',
            'created_by' => $chef->id,
        ]);

        $checklist = Checklist::create([
            'name' => 'Reporting checklist',
            'description' => 'Execution checklist for report validation',
            'project_id' => $project->id,
            'created_by' => $tester->id,
            'is_active' => true,
            'template_scope' => 'project',
            'lifecycle_status' => 'approved',
            'generated_from' => 'manual',
        ]);

        $story->checklists()->attach($checklist->id, [
            'is_generated_from_arxis' => false,
            'relevance_score' => 100,
            'link_type' => 'primary',
        ]);

        $version = ProjectVersion::create([
            'project_id' => $project->id,
            'checklist_id' => $checklist->id,
            'version_number' => 1,
        ]);

        $passed = VersionItem::create([
            'project_version_id' => $version->id,
            'title' => 'Export PDF summary',
            'description' => 'PDF export should succeed for owner',
            'priority' => 'High',
            'criticality' => 'Major',
            'order' => 0,
            'status' => 'Passed',
            'tested_by' => $tester->id,
            'tested_at' => now()->subMinutes(40),
        ]);

        $failed = VersionItem::create([
            'project_version_id' => $version->id,
            'title' => 'Block tester export',
            'description' => 'Tester must receive 403',
            'priority' => 'High',
            'criticality' => 'Critical',
            'order' => 1,
            'status' => 'Failed',
            'tested_by' => $tester->id,
            'tested_at' => now()->subMinutes(30),
        ]);

        $blocked = VersionItem::create([
            'project_version_id' => $version->id,
            'title' => 'Handle missing traces',
            'description' => 'Blocked by missing environment',
            'priority' => 'Medium',
            'criticality' => 'Major',
            'order' => 2,
            'status' => 'Blocked',
            'tested_by' => $tester->id,
            'tested_at' => now()->subMinutes(20),
        ]);

        $notTested = VersionItem::create([
            'project_version_id' => $version->id,
            'title' => 'Future XLSX export',
            'description' => 'Pending real xlsx implementation',
            'priority' => 'Low',
            'criticality' => 'Minor',
            'order' => 3,
            'status' => 'Not Tested',
        ]);

        Comment::create([
            'version_item_id' => $failed->id,
            'user_id' => $tester->id,
            'content' => 'Authorization still needs verification.',
            'file_name' => 'failure-log.txt',
            'file_path' => '/tmp/failure-log.txt',
            'file_size' => 512,
        ]);

        ItemChange::create([
            'version_item_id' => $failed->id,
            'changed_by' => $tester->id,
            'field_name' => 'status',
            'old_value' => 'Not Tested',
            'new_value' => 'Failed',
            'change_type' => 'status_changed',
            'notes' => 'Unauthorized tester export simulated.',
        ]);

        $run = TestRun::create([
            'run_id' => (string) Str::uuid(),
            'project_version_id' => $version->id,
            'checklist_id' => $checklist->id,
            'schema_version' => '1.0',
            'base_url' => 'https://example.test',
            'mode' => 'agent',
            'status' => 'completed',
            'requested_by' => $chef->id,
            'started_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(10),
            'summary_total' => 3,
            'summary_passed' => 1,
            'summary_failed' => 1,
            'summary_blocked' => 1,
            'summary_skipped' => 0,
        ]);

        TestResult::create([
            'test_run_id' => $run->id,
            'version_item_id' => $passed->id,
            'status' => 'passed',
            'duration_ms' => 1200,
            'artifacts' => ['trace' => [['path' => '/artifacts/passed-trace.zip']]],
            'executed_at' => now()->subMinutes(39),
        ]);

        TestResult::create([
            'test_run_id' => $run->id,
            'version_item_id' => $failed->id,
            'status' => 'failed',
            'error_type' => 'authorization',
            'error_message' => 'Expected 403 but export was reachable.',
            'duration_ms' => 1800,
            'artifacts' => [
                'trace' => [['path' => '/artifacts/failed-trace.zip']],
                'screenshot' => [['path' => '/artifacts/failed-screen.png']],
            ],
            'executed_at' => now()->subMinutes(29),
        ]);

        TestResult::create([
            'test_run_id' => $run->id,
            'version_item_id' => $blocked->id,
            'status' => 'blocked',
            'error_type' => 'environment',
            'error_message' => 'Required environment was unavailable.',
            'duration_ms' => 900,
            'artifacts' => ['video' => [['path' => '/artifacts/blocked-video.mp4']]],
            'executed_at' => now()->subMinutes(19),
        ]);

        return [$chef, $project, $tester, $notTested];
    }

    private function ensureRoles(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('chef', 'web');
        Role::findOrCreate('testeur', 'web');
    }
}
