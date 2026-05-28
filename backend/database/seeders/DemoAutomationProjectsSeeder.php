<?php

namespace Database\Seeders;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Project;
use App\Models\User;
use App\Models\UserStory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

class DemoAutomationProjectsSeeder extends Seeder
{
    private int $projectCount = 0;
    private int $storyCount = 0;
    private int $checklistCount = 0;
    private int $itemCount = 0;

    public function run(): void
    {
        [$chef, $tester] = $this->ensureUsers();
        $sampleUploadPath = $this->ensureSampleUploadFile();

        foreach ($this->projectDefinitions($sampleUploadPath) as $projectDefinition) {
            $this->seedProject($projectDefinition, $chef, $tester);
        }

        $this->command?->info(sprintf(
            'DemoAutomationProjectsSeeder created/updated %d projects, %d user stories, %d checklists, and %d checklist items.',
            $this->projectCount,
            $this->storyCount,
            $this->checklistCount,
            $this->itemCount,
        ));
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function ensureUsers(): array
    {
        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'chef']);
        Role::firstOrCreate(['name' => 'testeur']);

        $chef = User::firstOrCreate(
            ['email' => 'chef@test.com'],
            [
                'name' => 'Chef de Projet',
                'password' => Hash::make('password123'),
                'account_status' => 'active',
                'activated_at' => now(),
            ],
        );
        $chef->syncRoles(['chef']);

        $tester = User::firstOrCreate(
            ['email' => 'testeur@test.com'],
            [
                'name' => 'Testeur',
                'password' => Hash::make('password123'),
                'account_status' => 'active',
                'activated_at' => now(),
            ],
        );
        $tester->syncRoles(['testeur']);

        return [$chef, $tester];
    }

    private function ensureSampleUploadFile(): string
    {
        $relativePath = 'testing/sample-upload.txt';
        if (!Storage::disk('local')->exists($relativePath)) {
            Storage::disk('local')->put(
                $relativePath,
                implode(PHP_EOL, [
                    'IntelliTest sample upload artifact',
                    'This file is used by the public demo automation seeds.',
                    'Expected file name: sample-upload.txt',
                ]) . PHP_EOL,
            );
        }

        return storage_path('app/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function seedProject(array $definition, User $chef, User $tester): void
    {
        $stories = $definition['user_stories'];
        unset($definition['user_stories']);

        $project = Project::updateOrCreate(
            ['name' => $definition['name']],
            [
                'description' => $definition['description'],
                'test_objectives' => $definition['test_objectives'],
                'app_url' => $definition['app_url'],
                'created_by' => $chef->id,
                'start_date' => $definition['start_date'],
                'status' => $definition['status'],
            ],
        );

        $project->testers()->syncWithoutDetaching([$tester->id]);
        $this->projectCount++;

        foreach ($stories as $storyDefinition) {
            $checklists = $storyDefinition['checklists'];
            unset($storyDefinition['checklists']);

            $story = UserStory::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'story_id' => $storyDefinition['story_id'],
                ],
                [
                    'title' => $storyDefinition['title'],
                    'description' => $storyDefinition['description'],
                    'as_a' => $storyDefinition['as_a'],
                    'i_want_that' => $storyDefinition['i_want_that'],
                    'so_that' => $storyDefinition['so_that'],
                    'acceptance_criteria' => $storyDefinition['acceptance_criteria'],
                    'business_rules' => $storyDefinition['business_rules'],
                    'scenarios' => $storyDefinition['scenarios'],
                    'effort_points' => $storyDefinition['effort_points'],
                    'business_value' => $storyDefinition['business_value'],
                    'start_date' => $storyDefinition['start_date'],
                    'target_completion_date' => $storyDefinition['target_completion_date'],
                    'status' => $storyDefinition['status'],
                    'priority' => $storyDefinition['priority'],
                    'created_by' => $chef->id,
                ],
            );

            $this->storyCount++;

            foreach ($checklists as $checklistDefinition) {
                $this->seedChecklist($project, $story, $chef, $checklistDefinition);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function seedChecklist(Project $project, UserStory $story, User $chef, array $definition): void
    {
        $items = $definition['items'];
        unset($definition['items']);

        $checklist = Checklist::updateOrCreate(
            [
                'project_id' => $project->id,
                'name' => $definition['name'],
            ],
            [
                'description' => $definition['description'],
                'project_id' => $project->id,
                'as_a' => $definition['as_a'] ?? $story->as_a,
                'i_want_that' => $definition['i_want_that'] ?? $story->i_want_that,
                'so_that' => $definition['so_that'] ?? $story->so_that,
                'acceptance_criteria' => $definition['acceptance_criteria'] ?? $story->acceptance_criteria,
                'business_rules' => $definition['business_rules'] ?? $story->business_rules,
                'priority' => $definition['priority'],
                'status' => $definition['status'],
                'category' => $definition['category'],
                'is_active' => true,
                'created_by' => $chef->id,
                'template_scope' => 'project',
                'lifecycle_status' => 'approved',
                'generated_from' => 'manual',
                'source_user_story_id' => $story->id,
            ],
        );

        $project->checklists()->syncWithoutDetaching([$checklist->id]);
        $story->checklists()->syncWithoutDetaching([
            $checklist->id => [
                'is_generated_from_arxis' => false,
                'relevance_score' => 100,
                'link_type' => 'attached',
            ],
        ]);

        $this->checklistCount++;

        foreach (array_values($items) as $index => $itemDefinition) {
            $item = ChecklistItem::updateOrCreate(
                [
                    'checklist_id' => $checklist->id,
                    'title' => $itemDefinition['title'],
                ],
                [
                    'description' => $itemDefinition['description'],
                    'priority' => $itemDefinition['priority'],
                    'criticality' => $itemDefinition['criticality'],
                    'order' => $index + 1,
                    'status' => 'pending',
                    'execution_profile' => $this->buildExecutionProfile(
                        $project->app_url ?? '',
                        $itemDefinition['scenario_type'],
                        $itemDefinition['intent_summary'],
                        $itemDefinition['required_inputs'] ?? [],
                        $itemDefinition['expected_observations'] ?? [],
                        $itemDefinition['preconditions'] ?? [],
                        $itemDefinition['preflight_checks'] ?? [],
                        $itemDefinition['steps'] ?? [],
                        $itemDefinition['asserts'] ?? [],
                        $itemDefinition['provided_inputs'] ?? [],
                        $itemDefinition['expected_result'] ?? [],
                        $itemDefinition['source_app'] ?? '',
                    ),
                ],
            );

            $this->itemCount++;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $requiredInputs
     * @param  array<int, string>  $expectedObservations
     * @param  array<int, string>  $preconditions
     * @param  array<int, array<string, mixed>>  $preflightChecks
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<int, array<string, mixed>>  $asserts
     * @param  array<string, mixed>  $providedInputs
     * @param  array<string, mixed>  $expectedResult
     * @return array<string, mixed>
     */
    private function buildExecutionProfile(
        string $baseUrl,
        string $scenarioType,
        string $intentSummary,
        array $requiredInputs,
        array $expectedObservations,
        array $preconditions,
        array $preflightChecks,
        array $steps,
        array $asserts,
        array $providedInputs,
        array $expectedResult,
        string $sourceApp,
    ): array {
        return [
            'intent_summary' => $intentSummary,
            'coverage_type' => $scenarioType,
            'preconditions' => $preconditions,
            'required_inputs' => $requiredInputs,
            'expected_observations' => $expectedObservations,
            'diagnostics' => [],
            'metadata' => [
                'base_url' => $baseUrl,
                'source_app' => $sourceApp,
                'scenario_type' => $scenarioType,
                'environment_name' => 'public-demo',
                'provided_inputs' => $providedInputs,
                'expected_result' => $expectedResult,
            ],
            'last_generated_plan' => [
                'title' => $intentSummary,
                'intent_summary' => $intentSummary,
                'coverage_type' => $scenarioType,
                'preflight_checks' => $preflightChecks,
                'steps' => $steps,
                'asserts' => $asserts,
                'expected_observations' => $expectedObservations,
                'diagnostics' => [],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function projectDefinitions(string $sampleUploadPath): array
    {
        return [
            $this->sauceDemoProject(),
            $this->practiceLoginProject(),
            $this->automationTestingPracticeProject($sampleUploadPath),
            $this->automationExerciseProject(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sauceDemoProject(): array
    {
        return [
            'name' => 'Sauce Demo E-commerce Automation',
            'description' => 'Public Swag Labs sandbox used to validate login, catalog, cart, sorting, and checkout behaviors with deterministic browser automation.',
            'test_objectives' => 'Exercise critical e-commerce flows on a stable public demo site and compare generated run_spec quality across authentication, catalog, cart, and checkout scenarios.',
            'app_url' => 'https://www.saucedemo.com',
            'start_date' => now()->subDays(14)->toDateString(),
            'status' => 'active',
            'user_stories' => [
                [
                    'story_id' => 'SAUCE-US-001',
                    'title' => 'Authenticate into Swag Labs',
                    'description' => 'As a customer, I want to log in with valid and invalid credentials so that access to the product catalog is enforced correctly.',
                    'as_a' => 'customer',
                    'i_want_that' => 'I can authenticate with supported account states',
                    'so_that' => 'valid users reach inventory pages and invalid users receive clear errors',
                    'acceptance_criteria' => implode("\n", [
                        '- The login page is reachable.',
                        '- Valid credentials redirect to the inventory page.',
                        '- Locked or invalid users see a clear error message.',
                        '- Authenticated users can log out back to the login page.',
                    ]),
                    'business_rules' => [
                        'standard_user must access the inventory page.',
                        'locked_out_user must remain blocked from the catalog.',
                        'Invalid credentials must surface an error banner.',
                    ],
                    'scenarios' => [
                        'Login as standard_user with secret_sauce.',
                        'Login as locked_out_user with secret_sauce.',
                        'Login with an invalid password.',
                        'Log out after a successful login.',
                    ],
                    'effort_points' => 5,
                    'business_value' => 100,
                    'start_date' => now()->subDays(10)->toDateString(),
                    'target_completion_date' => now()->addDays(5)->toDateString(),
                    'status' => 'ready_for_test',
                    'priority' => 'critical',
                    'checklists' => [
                        [
                            'name' => 'Sauce Demo Login Checklist',
                            'description' => 'Authentication checks against the public Sauce Demo login page.',
                            'priority' => 'critical',
                            'status' => 'ready_for_test',
                            'category' => 'Authentication',
                            'items' => [
                                $this->demoItem(
                                    'Verify successful login with standard_user',
                                    'Open https://www.saucedemo.com, enter username standard_user and password secret_sauce, click Login, then verify the URL contains inventory.html and the Products heading is visible.',
                                    'High',
                                    'Critical',
                                    'authentication',
                                    'sauce_demo',
                                    [
                                        'username' => 'standard_user',
                                        'password' => 'secret_sauce',
                                        'expected_url_contains' => 'inventory.html',
                                        'expected_text' => 'Products',
                                    ],
                                    [
                                        'visible_text_contains' => 'Products',
                                        'assertion_keywords' => ['inventory.html', 'Products'],
                                    ],
                                    [
                                        $this->requiredInput('username', 'Username', 'text', 'standard_user', 'Sauce Demo username used for login.'),
                                        $this->requiredInput('password', 'Password', 'password', 'secret_sauce', 'Sauce Demo password used for login.'),
                                    ],
                                    ['The login page accepts valid credentials.', 'The products catalog is displayed after login.'],
                                    ['The Sauce Demo login page must be reachable.', 'The standard_user account must remain active on the public demo site.'],
                                    [
                                        $this->preflight('page-accessible', 'page_accessible', 'Login page is reachable', 'https://www.saucedemo.com must load before the test can continue.'),
                                        $this->preflight('username-visible', 'element_visible', 'Username field is visible', 'The username field is not visible on the Sauce Demo login page.'),
                                    ],
                                    [
                                        ['action' => 'goto', 'url' => 'https://www.saucedemo.com'],
                                        ['action' => 'fill', 'selector' => ['by' => 'role', 'role' => 'textbox', 'name' => 'Username'], 'input_key' => 'username'],
                                        ['action' => 'fill', 'selector' => ['by' => 'role', 'role' => 'textbox', 'name' => 'Password'], 'input_key' => 'password'],
                                        ['action' => 'click', 'selector' => ['by' => 'role', 'role' => 'button', 'name' => 'Login']],
                                        ['action' => 'wait_for_url', 'contains' => 'inventory.html', 'timeout_ms' => 20000],
                                    ],
                                    [
                                        ['type' => 'expect_url_contains', 'value' => 'inventory.html'],
                                        ['type' => 'expect_visible', 'selector' => ['by' => 'text', 'text' => 'Products']],
                                    ],
                                ),
                                $this->demoItem(
                                    'Verify locked_out_user login is rejected',
                                    'Open https://www.saucedemo.com, enter locked_out_user and secret_sauce, submit the login form, and verify that access is blocked with an error mentioning the user is locked out.',
                                    'High',
                                    'Critical',
                                    'authorization_access_control',
                                    'sauce_demo',
                                    [
                                        'username' => 'locked_out_user',
                                        'password' => 'secret_sauce',
                                        'expected_error_contains' => 'locked out',
                                    ],
                                    [
                                        'visible_text_contains' => 'locked out',
                                        'assertion_keywords' => ['locked out'],
                                    ],
                                    [
                                        $this->requiredInput('username', 'Username', 'text', 'locked_out_user', 'Locked Sauce Demo username.'),
                                        $this->requiredInput('password', 'Password', 'password', 'secret_sauce', 'Sauce Demo password used for login.'),
                                    ],
                                    ['The login request is rejected.', 'The error banner explains that the user is locked out.'],
                                    ['The public demo site must still expose the locked_out_user scenario.'],
                                    [
                                        $this->preflight('page-accessible', 'page_accessible', 'Login page is reachable', 'https://www.saucedemo.com must load before the test can continue.'),
                                    ],
                                    [
                                        ['action' => 'goto', 'url' => 'https://www.saucedemo.com'],
                                        ['action' => 'fill', 'selector' => ['by' => 'role', 'role' => 'textbox', 'name' => 'Username'], 'input_key' => 'username'],
                                        ['action' => 'fill', 'selector' => ['by' => 'role', 'role' => 'textbox', 'name' => 'Password'], 'input_key' => 'password'],
                                        ['action' => 'click', 'selector' => ['by' => 'role', 'role' => 'button', 'name' => 'Login']],
                                    ],
                                    [
                                        ['type' => 'expect_visible', 'selector' => ['by' => 'css', 'value' => '[data-test="error"]']],
                                        ['type' => 'expect_text_contains', 'selector' => ['by' => 'css', 'value' => '[data-test="error"]'], 'text' => 'locked out'],
                                    ],
                                ),
                                $this->demoItem(
                                    'Verify invalid credentials display an error message',
                                    'Open https://www.saucedemo.com, enter standard_user with an invalid password, click Login, and verify that the login stays on the same page with an error mentioning username and password do not match.',
                                    'Medium',
                                    'Major',
                                    'form_validation_error',
                                    'sauce_demo',
                                    [
                                        'username' => 'standard_user',
                                        'password' => 'wrong_password',
                                        'expected_error_contains' => 'do not match',
                                    ],
                                    [
                                        'visible_text_contains' => 'Username and password do not match',
                                        'assertion_keywords' => ['error', 'password do not match'],
                                    ],
                                    [
                                        $this->requiredInput('username', 'Username', 'text', 'standard_user', 'Standard Sauce Demo username.'),
                                        $this->requiredInput('password', 'Password', 'password', 'wrong_password', 'Invalid password used to trigger the error state.'),
                                    ],
                                    ['The login is rejected.', 'A visible error explains the credential mismatch.'],
                                    ['The Sauce Demo login page must be reachable.'],
                                    [
                                        $this->preflight('page-accessible', 'page_accessible', 'Login page is reachable', 'https://www.saucedemo.com must load before the test can continue.'),
                                    ],
                                    [
                                        ['action' => 'goto', 'url' => 'https://www.saucedemo.com'],
                                        ['action' => 'fill', 'selector' => ['by' => 'role', 'role' => 'textbox', 'name' => 'Username'], 'input_key' => 'username'],
                                        ['action' => 'fill', 'selector' => ['by' => 'role', 'role' => 'textbox', 'name' => 'Password'], 'input_key' => 'password'],
                                        ['action' => 'click', 'selector' => ['by' => 'role', 'role' => 'button', 'name' => 'Login']],
                                    ],
                                    [
                                        ['type' => 'expect_visible', 'selector' => ['by' => 'css', 'value' => '[data-test="error"]']],
                                        ['type' => 'expect_text_contains', 'selector' => ['by' => 'css', 'value' => '[data-test="error"]'], 'text' => 'do not match'],
                                    ],
                                ),
                                $this->demoItem(
                                    'Verify logout returns the user to the login page',
                                    'Log in as standard_user on https://www.saucedemo.com, open the side menu, click Logout, and verify the browser returns to the login page where the Login button is visible again.',
                                    'Medium',
                                    'Major',
                                    'navigation_routing',
                                    'sauce_demo',
                                    [
                                        'username' => 'standard_user',
                                        'password' => 'secret_sauce',
                                        'expected_text' => 'Login',
                                    ],
                                    [
                                        'visible_text_contains' => 'Login',
                                        'assertion_keywords' => ['logout', 'Login'],
                                    ],
                                    [
                                        $this->requiredInput('username', 'Username', 'text', 'standard_user', 'Standard Sauce Demo username.'),
                                        $this->requiredInput('password', 'Password', 'password', 'secret_sauce', 'Valid Sauce Demo password.'),
                                    ],
                                    ['The side menu exposes a Logout action.', 'After logout, the Login button is visible again.'],
                                    ['The standard_user account must still access the inventory page before logout.'],
                                    [
                                        $this->preflight('page-accessible', 'page_accessible', 'Login page is reachable', 'https://www.saucedemo.com must load before the test can continue.'),
                                    ],
                                    [
                                        ['action' => 'goto', 'url' => 'https://www.saucedemo.com'],
                                        ['action' => 'fill', 'selector' => ['by' => 'role', 'role' => 'textbox', 'name' => 'Username'], 'input_key' => 'username'],
                                        ['action' => 'fill', 'selector' => ['by' => 'role', 'role' => 'textbox', 'name' => 'Password'], 'input_key' => 'password'],
                                        ['action' => 'click', 'selector' => ['by' => 'role', 'role' => 'button', 'name' => 'Login']],
                                        ['action' => 'click', 'selector' => ['by' => 'css', 'value' => '#react-burger-menu-btn']],
                                        ['action' => 'click', 'selector' => ['by' => 'text', 'text' => 'Logout']],
                                    ],
                                    [
                                        ['type' => 'expect_visible', 'selector' => ['by' => 'role', 'role' => 'button', 'name' => 'Login']],
                                    ],
                                ),
                            ],
                        ],
                    ],
                ],
                [
                    'story_id' => 'SAUCE-US-002',
                    'title' => 'Browse products and maintain cart state',
                    'description' => 'As a shopper, I want to sort products and manage my cart so that I can select the correct items before checkout.',
                    'as_a' => 'shopper',
                    'i_want_that' => 'I can sort inventory items and add or remove them from the cart',
                    'so_that' => 'the cart reflects the products I intend to buy',
                    'acceptance_criteria' => implode("\n", [
                        '- Sorting options are visible on the inventory page.',
                        '- Adding a product increments the cart badge.',
                        '- Removing a product updates the cart badge or button state.',
                    ]),
                    'business_rules' => [
                        'Sorting controls must remain available after login.',
                        'The cart badge must reflect the number of added items.',
                        'Removing an item must revert the UI state cleanly.',
                    ],
                    'scenarios' => [
                        'Sort inventory items from Z to A.',
                        'Add Sauce Labs Backpack to the cart.',
                        'Remove a product from the inventory page after it was added.',
                    ],
                    'effort_points' => 8,
                    'business_value' => 90,
                    'start_date' => now()->subDays(8)->toDateString(),
                    'target_completion_date' => now()->addDays(6)->toDateString(),
                    'status' => 'ready_for_test',
                    'priority' => 'high',
                    'checklists' => [
                        [
                            'name' => 'Sauce Demo Product Sorting Checklist',
                            'description' => 'Sorting behavior on the inventory page after authentication.',
                            'priority' => 'high',
                            'status' => 'ready_for_test',
                            'category' => 'Catalog',
                            'items' => [
                                $this->demoItem(
                                    'Verify inventory sorting from Z to A',
                                    'Log in as standard_user on Sauce Demo, change the sort order to Name (Z to A), and verify the sort control shows the selected option while inventory remains visible.',
                                    'Medium',
                                    'Major',
                                    'sorting_functionality',
                                    'sauce_demo',
                                    [
                                        'username' => 'standard_user',
                                        'password' => 'secret_sauce',
                                        'sort' => 'za',
                                        'expected_text' => 'Name (Z to A)',
                                    ],
                                    [
                                        'visible_text_contains' => 'Name (Z to A)',
                                        'assertion_keywords' => ['sort', 'Z to A'],
                                    ],
                                    [
                                        $this->requiredInput('username', 'Username', 'text', 'standard_user', 'Standard Sauce Demo username.'),
                                        $this->requiredInput('password', 'Password', 'password', 'secret_sauce', 'Valid Sauce Demo password.'),
                                        $this->requiredInput('sort', 'Sort value', 'text', 'za', 'Sauce Demo sort query value.'),
                                    ],
                                    ['The sort control shows Name (Z to A).', 'The inventory grid remains visible after sorting.'],
                                    ['The inventory page must be reachable after login.'],
                                    [$this->preflight('inventory-visible', 'element_visible', 'Inventory list is visible', 'The inventory list is not visible after login.')],
                                    [
                                        ['action' => 'goto', 'url' => 'https://www.saucedemo.com'],
                                        ['action' => 'fill', 'selector' => ['by' => 'role', 'role' => 'textbox', 'name' => 'Username'], 'input_key' => 'username'],
                                        ['action' => 'fill', 'selector' => ['by' => 'role', 'role' => 'textbox', 'name' => 'Password'], 'input_key' => 'password'],
                                        ['action' => 'click', 'selector' => ['by' => 'role', 'role' => 'button', 'name' => 'Login']],
                                        ['action' => 'goto', 'url' => '/inventory.html?sort=za'],
                                    ],
                                    [
                                        ['type' => 'expect_url_contains', 'value' => 'sort=za'],
                                        ['type' => 'expect_text_contains', 'selector' => ['by' => 'css', 'value' => '.product_sort_container'], 'text' => 'Name (Z to A)'],
                                    ],
                                ),
                            ],
                        ],
                        [
                            'name' => 'Sauce Demo Add Product To Cart Checklist',
                            'description' => 'Add-to-cart checks on the public inventory page.',
                            'priority' => 'critical',
                            'status' => 'ready_for_test',
                            'category' => 'Cart',
                            'items' => [
                                $this->demoItem(
                                    'Verify Sauce Labs Backpack can be added to cart',
                                    'Log in as standard_user, click Add to cart for Sauce Labs Backpack, and verify that the cart badge displays 1.',
                                    'High',
                                    'Critical',
                                    'form_success_submission',
                                    'sauce_demo',
                                    [
                                        'username' => 'standard_user',
                                        'password' => 'secret_sauce',
                                        'product' => 'Sauce Labs Backpack',
                                        'expected_text' => '1',
                                    ],
                                    [
                                        'visible_text_contains' => '1',
                                        'assertion_keywords' => ['cart badge', 'Sauce Labs Backpack'],
                                    ],
                                    [
                                        $this->requiredInput('username', 'Username', 'text', 'standard_user', 'Standard Sauce Demo username.'),
                                        $this->requiredInput('password', 'Password', 'password', 'secret_sauce', 'Valid Sauce Demo password.'),
                                    ],
                                    ['The selected product is added to the cart.', 'The cart badge shows one item.'],
                                    ['The inventory page must be reachable after login.'],
                                    [$this->preflight('add-cart-button', 'element_visible', 'Add to cart button is visible', 'The Add to cart button for the backpack is not visible.')],
                                    [
                                        ['action' => 'goto', 'url' => 'https://www.saucedemo.com'],
                                        ['action' => 'fill', 'selector' => ['by' => 'role', 'role' => 'textbox', 'name' => 'Username'], 'input_key' => 'username'],
                                        ['action' => 'fill', 'selector' => ['by' => 'role', 'role' => 'textbox', 'name' => 'Password'], 'input_key' => 'password'],
                                        ['action' => 'click', 'selector' => ['by' => 'role', 'role' => 'button', 'name' => 'Login']],
                                        ['action' => 'click', 'selector' => ['by' => 'css', 'value' => '#add-to-cart-sauce-labs-backpack']],
                                    ],
                                    [
                                        ['type' => 'expect_visible', 'selector' => ['by' => 'css', 'value' => '.shopping_cart_badge']],
                                        ['type' => 'expect_text', 'selector' => ['by' => 'css', 'value' => '.shopping_cart_badge'], 'text' => '1'],
                                    ],
                                ),
                            ],
                        ],
                        [
                            'name' => 'Sauce Demo Remove Product From Cart Checklist',
                            'description' => 'Remove a previously added item and verify the cart state updates.',
                            'priority' => 'high',
                            'status' => 'ready_for_test',
                            'category' => 'Cart',
                            'items' => [
                                $this->demoItem(
                                    'Verify removing a cart item resets the cart badge',
                                    'Log in as standard_user, add Sauce Labs Backpack to the cart, click Remove from the inventory page, and verify the cart badge disappears.',
                                    'Medium',
                                    'Major',
                                    'notification_feedback',
                                    'sauce_demo',
                                    [
                                        'username' => 'standard_user',
                                        'password' => 'secret_sauce',
                                        'product' => 'Sauce Labs Backpack',
                                    ],
                                    [
                                        'assertion_keywords' => ['Remove', 'cart badge'],
                                    ],
                                    [
                                        $this->requiredInput('username', 'Username', 'text', 'standard_user', 'Standard Sauce Demo username.'),
                                        $this->requiredInput('password', 'Password', 'password', 'secret_sauce', 'Valid Sauce Demo password.'),
                                    ],
                                    ['The cart badge disappears after the item is removed.', 'The button state switches back to Add to cart.'],
                                    ['The inventory page must be reachable after login.'],
                                    [$this->preflight('inventory-visible', 'element_visible', 'Inventory list is visible', 'The inventory list is not visible after login.')],
                                    [
                                        ['action' => 'goto', 'url' => 'https://www.saucedemo.com'],
                                        ['action' => 'fill', 'selector' => ['by' => 'role', 'role' => 'textbox', 'name' => 'Username'], 'input_key' => 'username'],
                                        ['action' => 'fill', 'selector' => ['by' => 'role', 'role' => 'textbox', 'name' => 'Password'], 'input_key' => 'password'],
                                        ['action' => 'click', 'selector' => ['by' => 'role', 'role' => 'button', 'name' => 'Login']],
                                        ['action' => 'click', 'selector' => ['by' => 'css', 'value' => '#add-to-cart-sauce-labs-backpack']],
                                        ['action' => 'click', 'selector' => ['by' => 'css', 'value' => '#remove-sauce-labs-backpack']],
                                    ],
                                    [
                                        ['type' => 'expect_hidden', 'selector' => ['by' => 'css', 'value' => '.shopping_cart_badge']],
                                    ],
                                ),
                            ],
                        ],
                    ],
                ],
                [
                    'story_id' => 'SAUCE-US-003',
                    'title' => 'Complete checkout safely',
                    'description' => 'As a shopper, I want the checkout form and final confirmation to behave correctly so that I can complete my purchase without data errors.',
                    'as_a' => 'shopper',
                    'i_want_that' => 'I can enter checkout information and complete an order',
                    'so_that' => 'the order confirmation is displayed only after valid input',
                    'acceptance_criteria' => implode("\n", [
                        '- Required checkout fields show validation errors when missing.',
                        '- Valid checkout information advances to the overview page.',
                        '- Finishing checkout shows a thank-you confirmation.',
                    ]),
                    'business_rules' => [
                        'Checkout first name, last name, and postal code are mandatory.',
                        'The overview page is accessible only after valid checkout information.',
                        'The thank-you message appears only when checkout finishes successfully.',
                    ],
                    'scenarios' => [
                        'Submit checkout information without a postal code.',
                        'Complete checkout with valid customer data.',
                    ],
                    'effort_points' => 8,
                    'business_value' => 95,
                    'start_date' => now()->subDays(7)->toDateString(),
                    'target_completion_date' => now()->addDays(7)->toDateString(),
                    'status' => 'ready_for_test',
                    'priority' => 'critical',
                    'checklists' => [
                        [
                            'name' => 'Sauce Demo Checkout Information Validation Checklist',
                            'description' => 'Validate required fields on the checkout information form.',
                            'priority' => 'critical',
                            'status' => 'ready_for_test',
                            'category' => 'Checkout',
                            'items' => [
                                $this->demoItem(
                                    'Verify checkout information requires postal code',
                                    'Log in as standard_user, add Sauce Labs Backpack to the cart, navigate to checkout, leave postal code empty, continue, and verify an error mentions that Postal Code is required.',
                                    'High',
                                    'Critical',
                                    'form_required_fields',
                                    'sauce_demo',
                                    [
                                        'username' => 'standard_user',
                                        'password' => 'secret_sauce',
                                        'first_name' => 'Yahia',
                                        'last_name' => 'Tester',
                                        'postal_code' => '',
                                        'expected_error_contains' => 'Postal Code is required',
                                    ],
                                    [
                                        'visible_text_contains' => 'Postal Code is required',
                                        'assertion_keywords' => ['Postal Code is required'],
                                    ],
                                    [
                                        $this->requiredInput('username', 'Username', 'text', 'standard_user', 'Standard Sauce Demo username.'),
                                        $this->requiredInput('password', 'Password', 'password', 'secret_sauce', 'Valid Sauce Demo password.'),
                                        $this->requiredInput('first_name', 'First name', 'text', 'Yahia', 'Checkout first name.'),
                                        $this->requiredInput('last_name', 'Last name', 'text', 'Tester', 'Checkout last name.'),
                                        $this->requiredInput('postal_code', 'Postal code', 'text', '', 'Leave this field empty to trigger validation.', true),
                                    ],
                                    ['The checkout form blocks the submission.', 'An error explains that Postal Code is required.'],
                                    ['The cart must contain at least one item before checkout starts.'],
                                    [$this->preflight('checkout-form-visible', 'element_visible', 'Checkout form is visible', 'The checkout information form is not visible.')],
                                    [
                                        ['action' => 'goto', 'url' => 'https://www.saucedemo.com'],
                                        ['action' => 'fill', 'selector' => ['by' => 'role', 'role' => 'textbox', 'name' => 'Username'], 'input_key' => 'username'],
                                        ['action' => 'fill', 'selector' => ['by' => 'role', 'role' => 'textbox', 'name' => 'Password'], 'input_key' => 'password'],
                                        ['action' => 'click', 'selector' => ['by' => 'role', 'role' => 'button', 'name' => 'Login']],
                                        ['action' => 'click', 'selector' => ['by' => 'css', 'value' => '#add-to-cart-sauce-labs-backpack']],
                                        ['action' => 'click', 'selector' => ['by' => 'css', 'value' => '.shopping_cart_link']],
                                        ['action' => 'click', 'selector' => ['by' => 'css', 'value' => '#checkout']],
                                    ],
                                    [
                                        ['type' => 'expect_visible', 'selector' => ['by' => 'css', 'value' => '[data-test="error"]']],
                                        ['type' => 'expect_text_contains', 'selector' => ['by' => 'css', 'value' => '[data-test="error"]'], 'text' => 'Postal Code is required'],
                                    ],
                                ),
                            ],
                        ],
                        [
                            'name' => 'Sauce Demo Successful Checkout Flow Checklist',
                            'description' => 'Validate an end-to-end successful checkout on the public demo store.',
                            'priority' => 'critical',
                            'status' => 'ready_for_test',
                            'category' => 'Checkout',
                            'items' => [
                                $this->demoItem(
                                    'Verify successful checkout displays thank-you confirmation',
                                    'Log in as standard_user, add Sauce Labs Backpack to the cart, complete checkout with first name Yahia, last name Tester, postal code 5000, finish the order, and verify a thank-you message is shown.',
                                    'High',
                                    'Critical',
                                    'payment_checkout',
                                    'sauce_demo',
                                    [
                                        'username' => 'standard_user',
                                        'password' => 'secret_sauce',
                                        'first_name' => 'Yahia',
                                        'last_name' => 'Tester',
                                        'postal_code' => '5000',
                                        'expected_text' => 'Thank you for your order',
                                    ],
                                    [
                                        'visible_text_contains' => 'Thank you for your order',
                                        'assertion_keywords' => ['checkout complete', 'Thank you for your order'],
                                    ],
                                    [
                                        $this->requiredInput('username', 'Username', 'text', 'standard_user', 'Standard Sauce Demo username.'),
                                        $this->requiredInput('password', 'Password', 'password', 'secret_sauce', 'Valid Sauce Demo password.'),
                                        $this->requiredInput('first_name', 'First name', 'text', 'Yahia', 'Checkout first name.'),
                                        $this->requiredInput('last_name', 'Last name', 'text', 'Tester', 'Checkout last name.'),
                                        $this->requiredInput('postal_code', 'Postal code', 'text', '5000', 'Checkout postal code.'),
                                    ],
                                    ['The checkout overview is reached.', 'Finishing checkout displays a thank-you confirmation.'],
                                    ['The cart must contain at least one item before checkout starts.'],
                                    [$this->preflight('cart-link-visible', 'element_visible', 'Cart link is visible', 'The cart link is not visible after login.')],
                                    [
                                        ['action' => 'goto', 'url' => 'https://www.saucedemo.com'],
                                        ['action' => 'fill', 'selector' => ['by' => 'role', 'role' => 'textbox', 'name' => 'Username'], 'input_key' => 'username'],
                                        ['action' => 'fill', 'selector' => ['by' => 'role', 'role' => 'textbox', 'name' => 'Password'], 'input_key' => 'password'],
                                        ['action' => 'click', 'selector' => ['by' => 'role', 'role' => 'button', 'name' => 'Login']],
                                        ['action' => 'click', 'selector' => ['by' => 'css', 'value' => '#add-to-cart-sauce-labs-backpack']],
                                        ['action' => 'click', 'selector' => ['by' => 'css', 'value' => '.shopping_cart_link']],
                                        ['action' => 'click', 'selector' => ['by' => 'css', 'value' => '#checkout']],
                                    ],
                                    [
                                        ['type' => 'expect_visible', 'selector' => ['by' => 'text', 'text' => 'Thank you for your order!']],
                                    ],
                                ),
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function practiceLoginProject(): array
    {
        return [
            'name' => 'Practice Login Automation',
            'description' => 'Public Practice Test Automation login page used for crisp positive and negative login coverage.',
            'test_objectives' => 'Validate simple authentication behaviors on a stable public login form with clean expected outcomes for success, invalid credentials, and logout.',
            'app_url' => 'https://practicetestautomation.com/practice-test-login/',
            'start_date' => now()->subDays(12)->toDateString(),
            'status' => 'active',
            'user_stories' => [
                [
                    'story_id' => 'PTA-US-001',
                    'title' => 'Authenticate on the practice login page',
                    'description' => 'As a learner, I want to validate positive and negative login behavior on the public practice login page so that authentication rules remain obvious.',
                    'as_a' => 'learner',
                    'i_want_that' => 'I can validate success and failure states on the practice login page',
                    'so_that' => 'login behavior remains predictable and testable',
                    'acceptance_criteria' => implode("\n", [
                        '- The login form is reachable.',
                        '- Valid credentials reach the logged-in page.',
                        '- Invalid credentials display a clear error.',
                    ]),
                    'business_rules' => [
                        'student / Password123 must succeed.',
                        'An invalid username or password must show a visible error message.',
                    ],
                    'scenarios' => [
                        'Valid login with student / Password123.',
                        'Invalid username with valid password.',
                        'Valid username with invalid password.',
                    ],
                    'effort_points' => 3,
                    'business_value' => 80,
                    'start_date' => now()->subDays(9)->toDateString(),
                    'target_completion_date' => now()->addDays(4)->toDateString(),
                    'status' => 'ready_for_test',
                    'priority' => 'high',
                    'checklists' => [
                        [
                            'name' => 'Practice Login Success And Failure Checklist',
                            'description' => 'Positive and negative login checks for practicetestautomation.com.',
                            'priority' => 'high',
                            'status' => 'ready_for_test',
                            'category' => 'Authentication',
                            'items' => [
                                $this->demoItem(
                                    'Verify valid login reaches the logged-in page',
                                    'Open https://practicetestautomation.com/practice-test-login/, enter username student and password Password123, submit the form, and verify the URL contains logged-in-successfully and a success message is visible.',
                                    'High',
                                    'Critical',
                                    'authentication',
                                    'practice_test_automation',
                                    [
                                        'username' => 'student',
                                        'password' => 'Password123',
                                        'expected_url_contains' => 'logged-in-successfully',
                                        'expected_text' => 'Congratulations',
                                    ],
                                    [
                                        'visible_text_contains' => 'Congratulations',
                                        'assertion_keywords' => ['logged-in-successfully', 'Congratulations'],
                                    ],
                                    [
                                        $this->requiredInput('username', 'Username', 'text', 'student', 'Practice Test Automation username.'),
                                        $this->requiredInput('password', 'Password', 'password', 'Password123', 'Practice Test Automation password.'),
                                    ],
                                    ['The browser reaches the logged-in page.', 'A success message confirms the login.'],
                                    ['The practice login page must be reachable.'],
                                    [$this->preflight('form-visible', 'element_visible', 'Login form is visible', 'The practice login form is not visible.')],
                                    [
                                        ['action' => 'goto', 'url' => 'https://practicetestautomation.com/practice-test-login/'],
                                        ['action' => 'fill', 'selector' => ['by' => 'label', 'text' => 'Username'], 'input_key' => 'username'],
                                        ['action' => 'fill', 'selector' => ['by' => 'label', 'text' => 'Password'], 'input_key' => 'password'],
                                        ['action' => 'click', 'selector' => ['by' => 'role', 'role' => 'button', 'name' => 'Submit']],
                                    ],
                                    [
                                        ['type' => 'expect_url_contains', 'value' => 'logged-in-successfully'],
                                        ['type' => 'expect_text_contains', 'selector' => ['by' => 'text', 'text' => 'Congratulations'], 'text' => 'Congratulations'],
                                    ],
                                ),
                                $this->demoItem(
                                    'Verify invalid username shows an error',
                                    'Open the practice login page, enter username invalid_user and password Password123, submit, and verify the page shows an error containing Your username is invalid.',
                                    'Medium',
                                    'Major',
                                    'form_validation_error',
                                    'practice_test_automation',
                                    [
                                        'username' => 'invalid_user',
                                        'password' => 'Password123',
                                        'expected_error_contains' => 'Your username is invalid',
                                    ],
                                    [
                                        'visible_text_contains' => 'Your username is invalid',
                                        'assertion_keywords' => ['username is invalid'],
                                    ],
                                    [
                                        $this->requiredInput('username', 'Username', 'text', 'invalid_user', 'Invalid username used to trigger the error.'),
                                        $this->requiredInput('password', 'Password', 'password', 'Password123', 'Valid password paired with an invalid username.'),
                                    ],
                                    ['The login is rejected.', 'The page shows an invalid username error.'],
                                    ['The practice login page must be reachable.'],
                                    [$this->preflight('form-visible', 'element_visible', 'Login form is visible', 'The practice login form is not visible.')],
                                    [
                                        ['action' => 'goto', 'url' => 'https://practicetestautomation.com/practice-test-login/'],
                                        ['action' => 'fill', 'selector' => ['by' => 'label', 'text' => 'Username'], 'input_key' => 'username'],
                                        ['action' => 'fill', 'selector' => ['by' => 'label', 'text' => 'Password'], 'input_key' => 'password'],
                                        ['action' => 'click', 'selector' => ['by' => 'role', 'role' => 'button', 'name' => 'Submit']],
                                    ],
                                    [
                                        ['type' => 'expect_text_contains', 'selector' => ['by' => 'css', 'value' => '#error'], 'text' => 'Your username is invalid'],
                                    ],
                                ),
                                $this->demoItem(
                                    'Verify invalid password shows an error',
                                    'Open the practice login page, enter username student and password invalid_password, submit, and verify the page shows an error containing Your password is invalid.',
                                    'Medium',
                                    'Major',
                                    'form_validation_error',
                                    'practice_test_automation',
                                    [
                                        'username' => 'student',
                                        'password' => 'invalid_password',
                                        'expected_error_contains' => 'Your password is invalid',
                                    ],
                                    [
                                        'visible_text_contains' => 'Your password is invalid',
                                        'assertion_keywords' => ['password is invalid'],
                                    ],
                                    [
                                        $this->requiredInput('username', 'Username', 'text', 'student', 'Valid username used with an invalid password.'),
                                        $this->requiredInput('password', 'Password', 'password', 'invalid_password', 'Invalid password used to trigger the error.'),
                                    ],
                                    ['The login is rejected.', 'The page shows an invalid password error.'],
                                    ['The practice login page must be reachable.'],
                                    [$this->preflight('form-visible', 'element_visible', 'Login form is visible', 'The practice login form is not visible.')],
                                    [
                                        ['action' => 'goto', 'url' => 'https://practicetestautomation.com/practice-test-login/'],
                                        ['action' => 'fill', 'selector' => ['by' => 'label', 'text' => 'Username'], 'input_key' => 'username'],
                                        ['action' => 'fill', 'selector' => ['by' => 'label', 'text' => 'Password'], 'input_key' => 'password'],
                                        ['action' => 'click', 'selector' => ['by' => 'role', 'role' => 'button', 'name' => 'Submit']],
                                    ],
                                    [
                                        ['type' => 'expect_text_contains', 'selector' => ['by' => 'css', 'value' => '#error'], 'text' => 'Your password is invalid'],
                                    ],
                                ),
                            ],
                        ],
                    ],
                ],
                [
                    'story_id' => 'PTA-US-002',
                    'title' => 'Exit the logged-in page cleanly',
                    'description' => 'As a learner, I want to verify logout on the practice site so that the authentication cycle is complete.',
                    'as_a' => 'learner',
                    'i_want_that' => 'I can log out after a successful login',
                    'so_that' => 'the app returns to a safe anonymous state',
                    'acceptance_criteria' => implode("\n", [
                        '- The Log out button is visible after a successful login.',
                        '- Clicking Log out returns the browser to the login page.',
                    ]),
                    'business_rules' => [
                        'Logout is available only after authentication.',
                        'The post-logout page exposes the login form again.',
                    ],
                    'scenarios' => [
                        'Log in successfully and use the Log out action.',
                    ],
                    'effort_points' => 2,
                    'business_value' => 60,
                    'start_date' => now()->subDays(6)->toDateString(),
                    'target_completion_date' => now()->addDays(3)->toDateString(),
                    'status' => 'ready_for_test',
                    'priority' => 'medium',
                    'checklists' => [
                        [
                            'name' => 'Practice Login Logout Checklist',
                            'description' => 'Logout validation for the practice login demo site.',
                            'priority' => 'medium',
                            'status' => 'ready_for_test',
                            'category' => 'Authentication',
                            'items' => [
                                $this->demoItem(
                                    'Verify logout returns to the practice login page',
                                    'Log in on the practice login page with student and Password123, click the Log out button, and verify the login form becomes visible again.',
                                    'Medium',
                                    'Major',
                                    'navigation_routing',
                                    'practice_test_automation',
                                    [
                                        'username' => 'student',
                                        'password' => 'Password123',
                                        'expected_text' => 'Username',
                                    ],
                                    [
                                        'visible_text_contains' => 'Username',
                                        'assertion_keywords' => ['Log out', 'Username'],
                                    ],
                                    [
                                        $this->requiredInput('username', 'Username', 'text', 'student', 'Practice Test Automation username.'),
                                        $this->requiredInput('password', 'Password', 'password', 'Password123', 'Practice Test Automation password.'),
                                    ],
                                    ['The logout action is visible after login.', 'After logout, the login form is visible again.'],
                                    ['The practice login page must be reachable.'],
                                    [$this->preflight('form-visible', 'element_visible', 'Login form is visible', 'The practice login form is not visible.')],
                                    [
                                        ['action' => 'goto', 'url' => 'https://practicetestautomation.com/practice-test-login/'],
                                        ['action' => 'fill', 'selector' => ['by' => 'label', 'text' => 'Username'], 'input_key' => 'username'],
                                        ['action' => 'fill', 'selector' => ['by' => 'label', 'text' => 'Password'], 'input_key' => 'password'],
                                        ['action' => 'click', 'selector' => ['by' => 'role', 'role' => 'button', 'name' => 'Submit']],
                                        ['action' => 'click', 'selector' => ['by' => 'text', 'text' => 'Log out']],
                                    ],
                                    [
                                        ['type' => 'expect_visible', 'selector' => ['by' => 'label', 'text' => 'Username']],
                                    ],
                                ),
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function automationTestingPracticeProject(string $sampleUploadPath): array
    {
        return [
            'name' => 'Automation Testing Practice Playground',
            'description' => 'Public Blogspot automation playground used for forms, upload, tables, pagination, alerts, and dynamic button behavior.',
            'test_objectives' => 'Exercise high-signal UI widgets on a public sandbox page so that IntelliTest can be evaluated against varied selectors, validation rules, tables, and alert behaviors.',
            'app_url' => 'https://testautomationpractice.blogspot.com/',
            'start_date' => now()->subDays(11)->toDateString(),
            'status' => 'active',
            'user_stories' => [
                [
                    'story_id' => 'ATP-US-001',
                    'title' => 'Fill and validate playground forms',
                    'description' => 'As a QA engineer, I want to validate the main form and file upload widgets so that basic data entry coverage remains stable on the playground page.',
                    'as_a' => 'QA engineer',
                    'i_want_that' => 'I can validate required fields, dates, and uploads on the public playground',
                    'so_that' => 'common form behaviors remain observable and repeatable',
                    'acceptance_criteria' => implode("\n", [
                        '- Required form fields are visible before interaction.',
                        '- Date input accepts a valid date.',
                        '- File upload accepts the prepared sample file.',
                    ]),
                    'business_rules' => [
                        'The user must reach the form section before entering data.',
                        'The upload widget must accept a valid local file path.',
                    ],
                    'scenarios' => [
                        'Validate required fields are visible and ready for input.',
                        'Populate the date picker with a valid date.',
                        'Upload the prepared sample file.',
                    ],
                    'effort_points' => 5,
                    'business_value' => 75,
                    'start_date' => now()->subDays(8)->toDateString(),
                    'target_completion_date' => now()->addDays(5)->toDateString(),
                    'status' => 'ready_for_test',
                    'priority' => 'high',
                    'checklists' => [
                        [
                            'name' => 'Automation Testing Practice Form Required Fields Checklist',
                            'description' => 'Presence and readiness checks for the form section.',
                            'priority' => 'high',
                            'status' => 'ready_for_test',
                            'category' => 'Forms',
                            'items' => [
                                $this->demoItem(
                                    'Verify main form required fields are visible',
                                    'Open https://testautomationpractice.blogspot.com/, scroll to the main form area, and verify that Name, Email, Phone, and Address inputs are visible before entering any data.',
                                    'Medium',
                                    'Major',
                                    'form_required_fields',
                                    'automation_testing_practice',
                                    [
                                        'expected_text' => 'Name',
                                    ],
                                    [
                                        'assertion_keywords' => ['Name', 'Email', 'Phone', 'Address'],
                                    ],
                                    [],
                                    ['The main form fields are visible and ready for interaction.'],
                                    ['The public playground page must be reachable.'],
                                    [$this->preflight('form-visible', 'element_visible', 'Main form is visible', 'The main form is not visible on the page.')],
                                    [
                                        ['action' => 'goto', 'url' => 'https://testautomationpractice.blogspot.com/'],
                                        ['action' => 'wait_for_selector', 'selector' => ['by' => 'css', 'value' => 'input#name'], 'state' => 'visible', 'timeout_ms' => 20000],
                                    ],
                                    [
                                        ['type' => 'expect_visible', 'selector' => ['by' => 'css', 'value' => 'input#name']],
                                        ['type' => 'expect_visible', 'selector' => ['by' => 'css', 'value' => 'input#email']],
                                        ['type' => 'expect_visible', 'selector' => ['by' => 'css', 'value' => 'input#phone']],
                                    ],
                                ),
                            ],
                        ],
                        [
                            'name' => 'Automation Testing Practice Date Picker Checklist',
                            'description' => 'Date picker behavior on the public playground.',
                            'priority' => 'medium',
                            'status' => 'ready_for_test',
                            'category' => 'Forms',
                            'items' => [
                                $this->demoItem(
                                    'Verify date input accepts a valid date',
                                    'Open the automation testing practice page, enter 2026-06-15 into the date input, and verify the field keeps the selected date value.',
                                    'Medium',
                                    'Minor',
                                    'form_success_submission',
                                    'automation_testing_practice',
                                    [
                                        'date_value' => '2026-06-15',
                                    ],
                                    [
                                        'assertion_keywords' => ['2026-06-15', 'date input'],
                                    ],
                                    [
                                        $this->requiredInput('date_value', 'Date value', 'text', '2026-06-15', 'Valid date used for the date input.'),
                                    ],
                                    ['The date field retains the entered value.'],
                                    ['The public playground page must be reachable.'],
                                    [$this->preflight('date-input-visible', 'element_visible', 'Date input is visible', 'The date input is not visible on the page.')],
                                    [
                                        ['action' => 'goto', 'url' => 'https://testautomationpractice.blogspot.com/'],
                                        ['action' => 'fill', 'selector' => ['by' => 'css', 'value' => 'input#datepicker'], 'input_key' => 'date_value'],
                                    ],
                                    [
                                        ['type' => 'expect_visible', 'selector' => ['by' => 'css', 'value' => 'input#datepicker']],
                                    ],
                                ),
                            ],
                        ],
                        [
                            'name' => 'Automation Testing Practice File Upload Checklist',
                            'description' => 'Upload validation using a sample file stored by the backend.',
                            'priority' => 'high',
                            'status' => 'ready_for_test',
                            'category' => 'Forms',
                            'items' => [
                                $this->demoItem(
                                    'Verify sample upload file can be selected',
                                    'Open the automation testing practice page, use the upload control to select the prepared sample-upload.txt file, and verify the file name is accepted by the input control.',
                                    'High',
                                    'Major',
                                    'file_upload_validation',
                                    'automation_testing_practice',
                                    [
                                        'upload_file' => $sampleUploadPath,
                                        'file_name' => 'sample-upload.txt',
                                        'expected_text' => 'sample-upload.txt',
                                    ],
                                    [
                                        'assertion_keywords' => ['sample-upload.txt', 'upload'],
                                    ],
                                    [
                                        $this->requiredInput('upload_file', 'Upload file path', 'file', $sampleUploadPath, 'Absolute path to the prepared sample upload file.'),
                                    ],
                                    ['The upload input accepts the file path.', 'The selected file name is visible.'],
                                    ['The sample upload file must exist on disk.', 'The public playground page must be reachable.'],
                                    [$this->preflight('upload-visible', 'element_visible', 'Upload input is visible', 'The upload input is not visible on the page.')],
                                    [
                                        ['action' => 'goto', 'url' => 'https://testautomationpractice.blogspot.com/'],
                                        ['action' => 'set_file', 'selector' => ['by' => 'css', 'value' => 'input[type="file"]'], 'input_key' => 'upload_file'],
                                    ],
                                    [
                                        ['type' => 'expect_visible', 'selector' => ['by' => 'css', 'value' => 'input[type="file"]']],
                                    ],
                                ),
                            ],
                        ],
                    ],
                ],
                [
                    'story_id' => 'ATP-US-002',
                    'title' => 'Review tables and pagination',
                    'description' => 'As a QA engineer, I want to inspect static and paginated tables so that data-heavy widgets remain testable on the playground page.',
                    'as_a' => 'QA engineer',
                    'i_want_that' => 'I can validate table rendering and page controls',
                    'so_that' => 'data-oriented widgets remain stable for automation exercises',
                    'acceptance_criteria' => implode("\n", [
                        '- The static web table is visible.',
                        '- The pagination control exposes multiple page links.',
                    ]),
                    'business_rules' => [
                        'Visible tables must render at least one data row.',
                        'Pagination links must remain visible before item selection.',
                    ],
                    'scenarios' => [
                        'Validate the static web table is displayed.',
                        'Validate the pagination table exposes page links and a first page row.',
                    ],
                    'effort_points' => 5,
                    'business_value' => 70,
                    'start_date' => now()->subDays(6)->toDateString(),
                    'target_completion_date' => now()->addDays(5)->toDateString(),
                    'status' => 'ready_for_test',
                    'priority' => 'medium',
                    'checklists' => [
                        [
                            'name' => 'Automation Testing Practice Static Table Checklist',
                            'description' => 'Static table presence and readability checks.',
                            'priority' => 'medium',
                            'status' => 'ready_for_test',
                            'category' => 'Tables',
                            'items' => [
                                $this->demoItem(
                                    'Verify the static web table is visible',
                                    'Open the automation testing practice page, navigate to the web table section, and verify that the static table is visible with at least one row of data.',
                                    'Medium',
                                    'Minor',
                                    'table_data_display',
                                    'automation_testing_practice',
                                    [],
                                    [
                                        'assertion_keywords' => ['table', 'data row'],
                                    ],
                                    [],
                                    ['The static table is visible on the page.', 'At least one data row is rendered.'],
                                    ['The public playground page must be reachable.'],
                                    [$this->preflight('table-visible', 'element_visible', 'Static table is visible', 'The static web table is not visible.')],
                                    [
                                        ['action' => 'goto', 'url' => 'https://testautomationpractice.blogspot.com/'],
                                        ['action' => 'wait_for_selector', 'selector' => ['by' => 'css', 'value' => 'table[name="BookTable"], #HTML1 table'], 'state' => 'visible', 'timeout_ms' => 20000],
                                    ],
                                    [
                                        ['type' => 'expect_visible', 'selector' => ['by' => 'css', 'value' => 'table[name="BookTable"], #HTML1 table']],
                                    ],
                                ),
                            ],
                        ],
                        [
                            'name' => 'Automation Testing Practice Pagination Table Checklist',
                            'description' => 'Pagination controls on the product table section.',
                            'priority' => 'medium',
                            'status' => 'ready_for_test',
                            'category' => 'Tables',
                            'items' => [
                                $this->demoItem(
                                    'Verify pagination table exposes page links',
                                    'Open the automation testing practice page, scroll to the pagination table section, and verify that pagination links are visible and the first page of rows is rendered.',
                                    'Medium',
                                    'Major',
                                    'pagination_functionality',
                                    'automation_testing_practice',
                                    [],
                                    [
                                        'assertion_keywords' => ['pagination', 'page link'],
                                    ],
                                    [],
                                    ['Pagination links are visible.', 'The table displays rows for the current page.'],
                                    ['The public playground page must be reachable.'],
                                    [$this->preflight('pagination-visible', 'element_visible', 'Pagination links are visible', 'The pagination links are not visible on the page.')],
                                    [
                                        ['action' => 'goto', 'url' => 'https://testautomationpractice.blogspot.com/'],
                                        ['action' => 'wait_for_selector', 'selector' => ['by' => 'css', 'value' => '#pagination li, .pagination li'], 'state' => 'visible', 'timeout_ms' => 20000],
                                    ],
                                    [
                                        ['type' => 'expect_visible', 'selector' => ['by' => 'css', 'value' => '#pagination li, .pagination li']],
                                    ],
                                ),
                            ],
                        ],
                    ],
                ],
                [
                    'story_id' => 'ATP-US-003',
                    'title' => 'Exercise alerts and dynamic controls',
                    'description' => 'As a QA engineer, I want to validate popups and delayed button behaviors so that dynamic UI timing remains observable on the playground.',
                    'as_a' => 'QA engineer',
                    'i_want_that' => 'I can trigger alerts and wait for dynamic buttons to appear',
                    'so_that' => 'dynamic feedback behaviors remain testable',
                    'acceptance_criteria' => implode("\n", [
                        '- Alert buttons are visible before interaction.',
                        '- Dynamic buttons appear or become enabled after their delay.',
                    ]),
                    'business_rules' => [
                        'Popup actions must only be triggered when their buttons are visible.',
                        'Dynamic buttons must not be assumed visible before their delay completes.',
                    ],
                    'scenarios' => [
                        'Trigger a simple alert button.',
                        'Wait for the delayed button to become visible.',
                    ],
                    'effort_points' => 5,
                    'business_value' => 68,
                    'start_date' => now()->subDays(5)->toDateString(),
                    'target_completion_date' => now()->addDays(6)->toDateString(),
                    'status' => 'ready_for_test',
                    'priority' => 'medium',
                    'checklists' => [
                        [
                            'name' => 'Automation Testing Practice Alerts And Popups Checklist',
                            'description' => 'Alert and popup entry point validation.',
                            'priority' => 'medium',
                            'status' => 'ready_for_test',
                            'category' => 'Alerts',
                            'items' => [
                                $this->demoItem(
                                    'Verify alert button is visible before popup interaction',
                                    'Open the automation testing practice page and verify the alert button is visible before attempting any popup interaction.',
                                    'Low',
                                    'Minor',
                                    'modal_dialog_behavior',
                                    'automation_testing_practice',
                                    [],
                                    [
                                        'assertion_keywords' => ['alert button'],
                                    ],
                                    [],
                                    ['The alert button is visible and can be targeted safely.'],
                                    ['The public playground page must be reachable.'],
                                    [$this->preflight('alert-button-visible', 'element_visible', 'Alert button is visible', 'The alert button is not visible on the page.')],
                                    [
                                        ['action' => 'goto', 'url' => 'https://testautomationpractice.blogspot.com/'],
                                        ['action' => 'wait_for_selector', 'selector' => ['by' => 'css', 'value' => 'button[onclick*="alert"], #alertBtn'], 'state' => 'visible', 'timeout_ms' => 20000],
                                    ],
                                    [
                                        ['type' => 'expect_visible', 'selector' => ['by' => 'css', 'value' => 'button[onclick*="alert"], #alertBtn']],
                                    ],
                                ),
                            ],
                        ],
                        [
                            'name' => 'Automation Testing Practice Dynamic Button Checklist',
                            'description' => 'Delayed button visibility and dynamic interaction readiness.',
                            'priority' => 'medium',
                            'status' => 'ready_for_test',
                            'category' => 'Dynamic UI',
                            'items' => [
                                $this->demoItem(
                                    'Verify delayed button becomes visible',
                                    'Open the automation testing practice page, wait for the delayed button section, and verify that the dynamic button becomes visible after its delay instead of assuming it is immediately present.',
                                    'Medium',
                                    'Major',
                                    'loading_state_behavior',
                                    'automation_testing_practice',
                                    [],
                                    [
                                        'assertion_keywords' => ['dynamic button', 'delay'],
                                    ],
                                    [],
                                    ['The dynamic button becomes visible after waiting.'],
                                    ['The public playground page must be reachable.', 'The test must wait instead of clicking immediately.'],
                                    [$this->preflight('dynamic-button-section', 'element_visible', 'Dynamic button section is visible', 'The dynamic button section is not visible on the page.')],
                                    [
                                        ['action' => 'goto', 'url' => 'https://testautomationpractice.blogspot.com/'],
                                        ['action' => 'wait_for_selector', 'selector' => ['by' => 'css', 'value' => '#visibleAfter, button[id*="visibleAfter"]'], 'state' => 'visible', 'timeout_ms' => 20000],
                                    ],
                                    [
                                        ['type' => 'expect_visible', 'selector' => ['by' => 'css', 'value' => '#visibleAfter, button[id*="visibleAfter"]']],
                                    ],
                                ),
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function automationExerciseProject(): array
    {
        return [
            'name' => 'Automation Exercise E-commerce QA',
            'description' => 'Public Automation Exercise storefront used for home page, search, product details, cart, contact form, and navigation testing.',
            'test_objectives' => 'Validate broad e-commerce flows on a public practice application with enough variety to analyze generator quality, locators, and preflight coverage.',
            'app_url' => 'https://automationexercise.com/',
            'start_date' => now()->subDays(13)->toDateString(),
            'status' => 'active',
            'user_stories' => [
                [
                    'story_id' => 'AE-US-001',
                    'title' => 'Navigate key storefront entry points',
                    'description' => 'As a shopper, I want to land on the home page and navigate to important entry points so that I can explore the storefront safely.',
                    'as_a' => 'shopper',
                    'i_want_that' => 'I can reach the home page and key navigation links',
                    'so_that' => 'I can begin browsing without broken routes',
                    'acceptance_criteria' => implode("\n", [
                        '- The home page loads and shows the site header.',
                        '- The Signup / Login entry point is reachable.',
                        '- Category navigation is visible.',
                    ]),
                    'business_rules' => [
                        'The home page must show the storefront shell before deeper navigation begins.',
                        'Navigation links must remain visible without authentication.',
                    ],
                    'scenarios' => [
                        'Load the home page.',
                        'Open the Signup / Login page.',
                        'Navigate through category links.',
                    ],
                    'effort_points' => 5,
                    'business_value' => 75,
                    'start_date' => now()->subDays(9)->toDateString(),
                    'target_completion_date' => now()->addDays(5)->toDateString(),
                    'status' => 'ready_for_test',
                    'priority' => 'high',
                    'checklists' => [
                        [
                            'name' => 'Automation Exercise Home Page Checklist',
                            'description' => 'Home page and shell validation on automationexercise.com.',
                            'priority' => 'high',
                            'status' => 'ready_for_test',
                            'category' => 'Navigation',
                            'items' => [
                                $this->demoItem(
                                    'Verify the Automation Exercise home page loads',
                                    'Open https://automationexercise.com/ and verify the home page loads with the Automation Exercise header visible.',
                                    'High',
                                    'Major',
                                    'navigation_routing',
                                    'automation_exercise',
                                    [
                                        'expected_text' => 'Automation Exercise',
                                    ],
                                    [
                                        'visible_text_contains' => 'Automation Exercise',
                                        'assertion_keywords' => ['Automation Exercise', 'home'],
                                    ],
                                    [],
                                    ['The home page loads successfully.', 'The site header is visible.'],
                                    ['The public storefront must be reachable.'],
                                    [$this->preflight('home-reachable', 'page_accessible', 'Home page is reachable', 'The Automation Exercise home page could not be reached.')],
                                    [
                                        ['action' => 'goto', 'url' => 'https://automationexercise.com/'],
                                    ],
                                    [
                                        ['type' => 'expect_visible', 'selector' => ['by' => 'text', 'text' => 'Automation Exercise']],
                                    ],
                                ),
                            ],
                        ],
                        [
                            'name' => 'Automation Exercise Signup Login Navigation Checklist',
                            'description' => 'Verify the Signup / Login page is reachable from the public header.',
                            'priority' => 'medium',
                            'status' => 'ready_for_test',
                            'category' => 'Navigation',
                            'items' => [
                                $this->demoItem(
                                    'Verify Signup / Login page navigation',
                                    'Open the Automation Exercise home page, click Signup / Login, and verify the browser reaches a page showing Login to your account.',
                                    'Medium',
                                    'Major',
                                    'navigation_routing',
                                    'automation_exercise',
                                    [
                                        'expected_text' => 'Login to your account',
                                    ],
                                    [
                                        'visible_text_contains' => 'Login to your account',
                                        'assertion_keywords' => ['Signup / Login'],
                                    ],
                                    [],
                                    ['The Signup / Login page is reachable from the home page header.'],
                                    ['The home page must be reachable.'],
                                    [$this->preflight('login-link-visible', 'element_visible', 'Signup / Login link is visible', 'The Signup / Login link is not visible in the header.')],
                                    [
                                        ['action' => 'goto', 'url' => 'https://automationexercise.com/'],
                                        ['action' => 'click', 'selector' => ['by' => 'text', 'text' => 'Signup / Login']],
                                    ],
                                    [
                                        ['type' => 'expect_text_contains', 'selector' => ['by' => 'text', 'text' => 'Login to your account'], 'text' => 'Login to your account'],
                                    ],
                                ),
                            ],
                        ],
                        [
                            'name' => 'Automation Exercise Category Navigation Checklist',
                            'description' => 'Category navigation checks on the public storefront.',
                            'priority' => 'medium',
                            'status' => 'ready_for_test',
                            'category' => 'Navigation',
                            'items' => [
                                $this->demoItem(
                                    'Verify Women category navigation is visible',
                                    'Open the Automation Exercise home page and verify that the Women category navigation is visible before attempting category exploration.',
                                    'Low',
                                    'Minor',
                                    'navigation_routing',
                                    'automation_exercise',
                                    [
                                        'expected_text' => 'Women',
                                    ],
                                    [
                                        'assertion_keywords' => ['Women', 'Category'],
                                    ],
                                    [],
                                    ['Category navigation is visible on the home page.'],
                                    ['The home page must be reachable.'],
                                    [$this->preflight('category-visible', 'element_visible', 'Women category link is visible', 'The Women category link is not visible on the home page.')],
                                    [
                                        ['action' => 'goto', 'url' => 'https://automationexercise.com/'],
                                    ],
                                    [
                                        ['type' => 'expect_visible', 'selector' => ['by' => 'text', 'text' => 'Women']],
                                    ],
                                ),
                            ],
                        ],
                    ],
                ],
                [
                    'story_id' => 'AE-US-002',
                    'title' => 'Discover products and add them to the cart',
                    'description' => 'As a shopper, I want to search for products, view their details, and add them to the cart so that I can evaluate a buying flow.',
                    'as_a' => 'shopper',
                    'i_want_that' => 'I can discover products through search and detail pages',
                    'so_that' => 'I can add relevant items to the cart',
                    'acceptance_criteria' => implode("\n", [
                        '- The products page exposes a search field.',
                        '- Product detail pages are reachable.',
                        '- Adding a product to cart shows a cart confirmation.',
                    ]),
                    'business_rules' => [
                        'The search field must be visible before typing a query.',
                        'Product cards must expose a path to a detail or cart action.',
                    ],
                    'scenarios' => [
                        'Search for dress.',
                        'Open a product detail page.',
                        'Add a visible product to the cart.',
                    ],
                    'effort_points' => 8,
                    'business_value' => 85,
                    'start_date' => now()->subDays(8)->toDateString(),
                    'target_completion_date' => now()->addDays(6)->toDateString(),
                    'status' => 'ready_for_test',
                    'priority' => 'high',
                    'checklists' => [
                        [
                            'name' => 'Automation Exercise Product Search Checklist',
                            'description' => 'Search behavior on the public products catalog.',
                            'priority' => 'high',
                            'status' => 'ready_for_test',
                            'category' => 'Catalog',
                            'items' => [
                                $this->demoItem(
                                    'Verify searching for dress returns visible results',
                                    'Open https://automationexercise.com/products, enter dress in the search field, submit the search, and verify that visible content still includes dress-related results.',
                                    'High',
                                    'Major',
                                    'search_functionality',
                                    'automation_exercise',
                                    [
                                        'search_query' => 'dress',
                                        'expected_text' => 'dress',
                                    ],
                                    [
                                        'visible_text_contains' => 'dress',
                                        'assertion_keywords' => ['search', 'dress'],
                                    ],
                                    [
                                        $this->requiredInput('search_query', 'Search query', 'search', 'dress', 'Query used in the products search box.'),
                                    ],
                                    ['The search field accepts the query.', 'Search results still reference dress-related content.'],
                                    ['The products page must be reachable.'],
                                    [$this->preflight('search-visible', 'element_visible', 'Products search field is visible', 'The products search field is not visible.')],
                                    [
                                        ['action' => 'goto', 'url' => 'https://automationexercise.com/products'],
                                        ['action' => 'fill', 'selector' => ['by' => 'css', 'value' => '#search_product'], 'input_key' => 'search_query'],
                                        ['action' => 'click', 'selector' => ['by' => 'css', 'value' => '#submit_search']],
                                    ],
                                    [
                                        ['type' => 'expect_text_contains', 'selector' => ['by' => 'css', 'value' => 'body'], 'text' => 'dress'],
                                    ],
                                ),
                            ],
                        ],
                        [
                            'name' => 'Automation Exercise Product Details Checklist',
                            'description' => 'Product details navigation from the public catalog.',
                            'priority' => 'medium',
                            'status' => 'ready_for_test',
                            'category' => 'Catalog',
                            'items' => [
                                $this->demoItem(
                                    'Verify a product detail page can be opened',
                                    'Open the Automation Exercise products page, click View Product on the first visible product card, and verify that a product detail page is displayed.',
                                    'Medium',
                                    'Major',
                                    'navigation_routing',
                                    'automation_exercise',
                                    [
                                        'expected_text' => 'View Product',
                                    ],
                                    [
                                        'assertion_keywords' => ['View Product', 'product detail'],
                                    ],
                                    [],
                                    ['A product detail page is displayed after clicking View Product.'],
                                    ['The products page must be reachable.'],
                                    [$this->preflight('product-card-visible', 'element_visible', 'Product cards are visible', 'No product cards are visible on the products page.')],
                                    [
                                        ['action' => 'goto', 'url' => 'https://automationexercise.com/products'],
                                        ['action' => 'click', 'selector' => ['by' => 'text', 'text' => 'View Product']],
                                    ],
                                    [
                                        ['type' => 'expect_visible', 'selector' => ['by' => 'css', 'value' => '.product-information, .product-details']],
                                    ],
                                ),
                            ],
                        ],
                        [
                            'name' => 'Automation Exercise Add Product To Cart Checklist',
                            'description' => 'Basic add-to-cart confirmation on the public storefront.',
                            'priority' => 'high',
                            'status' => 'ready_for_test',
                            'category' => 'Cart',
                            'items' => [
                                $this->demoItem(
                                    'Verify adding a visible product shows cart confirmation',
                                    'Open the Automation Exercise products page, add the first visible product to the cart, and verify that a cart confirmation modal or message becomes visible.',
                                    'High',
                                    'Major',
                                    'form_success_submission',
                                    'automation_exercise',
                                    [
                                        'expected_text' => 'Added!',
                                    ],
                                    [
                                        'assertion_keywords' => ['Added!', 'cart'],
                                    ],
                                    [],
                                    ['A confirmation is shown after the product is added to the cart.'],
                                    ['The products page must be reachable.', 'At least one visible product card must expose an Add to cart action.'],
                                    [$this->preflight('add-cart-visible', 'element_visible', 'Add to cart button is visible', 'No Add to cart action is visible on the products page.')],
                                    [
                                        ['action' => 'goto', 'url' => 'https://automationexercise.com/products'],
                                        ['action' => 'click', 'selector' => ['by' => 'text', 'text' => 'Add to cart']],
                                    ],
                                    [
                                        ['type' => 'expect_text_contains', 'selector' => ['by' => 'css', 'value' => 'body'], 'text' => 'Added!'],
                                    ],
                                ),
                            ],
                        ],
                    ],
                ],
                [
                    'story_id' => 'AE-US-003',
                    'title' => 'Reach contact and support-style workflows',
                    'description' => 'As a shopper, I want to open the contact form so that support-oriented routes remain accessible.',
                    'as_a' => 'shopper',
                    'i_want_that' => 'I can reach and validate the contact workflow entry point',
                    'so_that' => 'support interactions stay reachable from the public site',
                    'acceptance_criteria' => implode("\n", [
                        '- The Contact us page is reachable.',
                        '- The contact form fields are visible before submission.',
                    ]),
                    'business_rules' => [
                        'The contact workflow must expose name, email, subject, and message fields.',
                    ],
                    'scenarios' => [
                        'Open the contact page and confirm the form is visible.',
                    ],
                    'effort_points' => 3,
                    'business_value' => 55,
                    'start_date' => now()->subDays(5)->toDateString(),
                    'target_completion_date' => now()->addDays(4)->toDateString(),
                    'status' => 'ready_for_test',
                    'priority' => 'medium',
                    'checklists' => [
                        [
                            'name' => 'Automation Exercise Contact Form Validation Checklist',
                            'description' => 'Contact page reachability and form visibility checks.',
                            'priority' => 'medium',
                            'status' => 'ready_for_test',
                            'category' => 'Support',
                            'items' => [
                                $this->demoItem(
                                    'Verify the contact form page is reachable and fields are visible',
                                    'Open the Automation Exercise home page, click Contact us, and verify that the contact form fields Name, Email, Subject, and Message are visible before any submission.',
                                    'Medium',
                                    'Major',
                                    'form_required_fields',
                                    'automation_exercise',
                                    [
                                        'expected_text' => 'Get In Touch',
                                    ],
                                    [
                                        'assertion_keywords' => ['Get In Touch', 'Name', 'Email', 'Subject', 'Message'],
                                    ],
                                    [],
                                    ['The contact page is reachable.', 'The contact form fields are visible before submission.'],
                                    ['The home page must be reachable.'],
                                    [$this->preflight('contact-link-visible', 'element_visible', 'Contact us link is visible', 'The Contact us link is not visible in the header.')],
                                    [
                                        ['action' => 'goto', 'url' => 'https://automationexercise.com/'],
                                        ['action' => 'click', 'selector' => ['by' => 'text', 'text' => 'Contact us']],
                                    ],
                                    [
                                        ['type' => 'expect_text_contains', 'selector' => ['by' => 'css', 'value' => 'body'], 'text' => 'Get In Touch'],
                                    ],
                                ),
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $providedInputs
     * @param  array<string, mixed>  $expectedResult
     * @param  array<int, array<string, mixed>>  $requiredInputs
     * @param  array<int, string>  $expectedObservations
     * @param  array<int, string>  $preconditions
     * @param  array<int, array<string, mixed>>  $preflightChecks
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<int, array<string, mixed>>  $asserts
     * @return array<string, mixed>
     */
    private function demoItem(
        string $title,
        string $description,
        string $priority,
        string $criticality,
        string $scenarioType,
        string $sourceApp,
        array $providedInputs,
        array $expectedResult,
        array $requiredInputs,
        array $expectedObservations,
        array $preconditions,
        array $preflightChecks,
        array $steps,
        array $asserts,
    ): array {
        return [
            'title' => $title,
            'description' => $description,
            'priority' => $priority,
            'criticality' => $criticality,
            'scenario_type' => $scenarioType,
            'source_app' => $sourceApp,
            'provided_inputs' => $providedInputs,
            'expected_result' => $expectedResult,
            'intent_summary' => $title,
            'required_inputs' => $requiredInputs,
            'expected_observations' => $expectedObservations,
            'preconditions' => $preconditions,
            'preflight_checks' => $preflightChecks,
            'steps' => $steps,
            'asserts' => $asserts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requiredInput(
        string $key,
        string $label,
        string $kind,
        string $value,
        string $description,
        bool $allowEmpty = false,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'kind' => $kind,
            'required' => true,
            'allow_empty' => $allowEmpty,
            'description' => $description,
            'value' => $value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function preflight(string $id, string $kind, string $label, string $failureMessage): array
    {
        return [
            'id' => $id,
            'kind' => $kind,
            'label' => $label,
            'required' => true,
            'failure_message' => $failureMessage,
        ];
    }
}
