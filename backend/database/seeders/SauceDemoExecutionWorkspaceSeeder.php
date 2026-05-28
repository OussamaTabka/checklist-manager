<?php

namespace Database\Seeders;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Project;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class SauceDemoExecutionWorkspaceSeeder extends Seeder
{
    private const PROJECT_NAME = 'Sauce Demo Execution Workspace';
    private const CHECKLIST_NAME = 'Sauce Demo Login Validation Checklist';
    private const CHECKLIST_ITEM_TITLE = 'TC-01 | Successful login page validation';
    private const BASE_URL = 'https://www.saucedemo.com';
    private const RUN_ID = '11111111-1111-4111-8111-111111111111';

    public function run(): void
    {
        $chef = $this->firstOrCreateUser('chef@test.com', 'Chef de Projet', 'chef');
        $tester = $this->firstOrCreateUser('testeur@test.com', 'Testeur', 'testeur');

        $project = Project::updateOrCreate(
            ['name' => self::PROJECT_NAME],
            [
                'description' => 'Dedicated seeded project for validating the checklist execution workspace against Sauce Demo.',
                'test_objectives' => 'Confirm that seeded automated execution results render correctly for an assigned tester.',
                'app_url' => self::BASE_URL,
                'created_by' => $chef->id,
            ]
        );

        $project->testers()->syncWithoutDetaching([$tester->id]);

        $checklist = Checklist::updateOrCreate(
            [
                'project_id' => $project->id,
                'name' => self::CHECKLIST_NAME,
            ],
            [
                'description' => 'Execution-ready checklist seeded to populate the automated run panel with a successful Sauce Demo login validation.',
                'category' => 'UI/UX',
                'project_id' => $project->id,
                'is_active' => true,
                'created_by' => $chef->id,
                'as_a' => 'shopper',
                'i_want_that' => 'log in successfully with valid credentials',
                'so_that' => 'I can access the inventory page',
                'acceptance_criteria' => implode("\n", [
                    '- The Sauce Demo login page is reachable',
                    '- Valid credentials open the inventory page',
                    '- The Products heading is visible after login',
                ]),
                'priority' => 'critical',
                'status' => 'ready_for_test',
                'template_scope' => 'project',
                'lifecycle_status' => 'approved',
                'generated_from' => 'manual',
            ]
        );

        $project->checklists()->syncWithoutDetaching([$checklist->id]);

        $checklist->items()->where('title', '!=', self::CHECKLIST_ITEM_TITLE)->delete();

        $item = ChecklistItem::updateOrCreate(
            [
                'checklist_id' => $checklist->id,
                'title' => self::CHECKLIST_ITEM_TITLE,
            ],
            [
                'description' => 'Verify the Sauce Demo login page loads, accepts valid credentials, and displays the inventory Products heading.',
                'priority' => 'High',
                'criticality' => 'Critical',
                'status' => 'Passed',
                'order' => 1,
                'run_count' => 1,
                'last_run_at' => Carbon::parse('2026-05-24 11:05:00'),
                'tested_by' => $tester->id,
                'tested_at' => Carbon::parse('2026-05-24 11:05:00'),
                'execution_profile' => [
                    'intent_summary' => 'Validate the Sauce Demo login flow with a stable public demo target.',
                    'required_inputs' => [],
                    'preconditions' => [
                        'The Sauce Demo application must be reachable.',
                        'The standard_user test account remains available.',
                    ],
                    'last_generated_plan' => $this->generatedPlan(),
                ],
            ]
        );

        $run = TestRun::updateOrCreate(
            ['run_id' => self::RUN_ID],
            [
                'project_version_id' => null,
                'checklist_id' => $checklist->id,
                'schema_version' => '1.0',
                'base_url' => self::BASE_URL,
                'mode' => 'single-test-case',
                'status' => 'completed',
                'requested_by' => $tester->id,
                'started_at' => Carbon::parse('2026-05-24 11:04:27'),
                'finished_at' => Carbon::parse('2026-05-24 11:05:00'),
                'summary_total' => 1,
                'summary_passed' => 1,
                'summary_failed' => 0,
                'summary_blocked' => 0,
                'summary_skipped' => 0,
                'request_payload' => [
                    'schema_version' => '1.0',
                    'target_type' => 'checklist_item',
                    'test_case_id' => $item->id,
                    'test_case_title' => $item->title,
                    'test_case_description' => $item->description,
                    'test_case_text' => trim($item->title . "\n" . $item->description),
                    'base_url' => self::BASE_URL,
                    'use_auth' => false,
                    'watch_mode' => true,
                    'environment_name' => 'public-demo',
                    'notes' => 'Seeded completed run for execution workspace validation.',
                    'provided_inputs' => [],
                    'priority' => $item->priority,
                    'criticality' => $item->criticality,
                    'current_status' => $item->status,
                    'checklist_id' => $checklist->id,
                ],
            ]
        );

        TestResult::updateOrCreate(
            [
                'test_run_id' => $run->id,
                'checklist_item_id' => $item->id,
            ],
            [
                'version_item_id' => null,
                'status' => 'passed',
                'error_type' => null,
                'error_message' => null,
                'duration_ms' => 33000,
                'artifacts' => [
                    'trace' => ['runs/' . self::RUN_ID . '/artifacts/' . $item->id . '/trace.zip'],
                    'screenshot' => ['runs/' . self::RUN_ID . '/artifacts/' . $item->id . '/generic-ui-baseline.png'],
                    'video' => [],
                    'raw_paths' => [
                        'trace_path' => 'runs/' . self::RUN_ID . '/artifacts/' . $item->id . '/trace.zip',
                        'screenshot_path' => 'runs/' . self::RUN_ID . '/artifacts/' . $item->id . '/generic-ui-baseline.png',
                        'video_path' => null,
                    ],
                ],
                'result_payload' => [
                    'external_id' => $item->id,
                    'status' => 'passed',
                    'generated_plan' => $this->generatedPlan(),
                    'execution_trace' => [
                        '[chromium] Planning: precondition -> Target base URL must be reachable: ' . self::BASE_URL,
                        '[chromium] Prefight 1: initial target page is reachable -> ok',
                        '[chromium] Step 1: open Sauce Demo login page -> ok',
                        '[chromium] Step 2: fill username and password -> ok',
                        '[chromium] Step 3: submit login form -> ok',
                        '[chromium] Assert 1: Products heading is visible -> ok',
                        '[chromium] Run completed successfully',
                    ],
                ],
                'executed_at' => Carbon::parse('2026-05-24 11:05:00'),
            ]
        );

        $this->command?->info('Sauce Demo execution workspace seeded with one successful completed automated run.');
    }

    private function firstOrCreateUser(string $email, string $name, string $role): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('password123'),
                'account_status' => 'active',
                'activated_at' => now(),
            ]
        );

        if (method_exists($user, 'syncRoles')) {
            $user->syncRoles([$role]);
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function generatedPlan(): array
    {
        return [
            'coverage_type' => 'seeded_demo_success',
            'steps' => [
                ['action' => 'goto', 'url' => self::BASE_URL],
                ['action' => 'fill', 'selector' => '#user-name', 'value' => 'standard_user'],
                ['action' => 'fill', 'selector' => '#password', 'value' => 'secret_sauce'],
                ['action' => 'click', 'selector' => '#login-button'],
                ['action' => 'capture_evidence', 'label' => 'generic-ui-baseline'],
            ],
            'asserts' => [
                ['action' => 'assert_visible', 'selector' => '.title', 'text' => 'Products'],
            ],
        ];
    }
}
