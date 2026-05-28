<?php

namespace Database\Seeders;

use App\Models\AgentBenchmarkCase;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AgentBenchmarkSeeder extends Seeder
{
    private const TESTER_EMAIL = 'benchmark.tester@example.com';
    private const OWNER_EMAIL = 'benchmark.owner@example.com';

    public function run(): void
    {
        $owner = $this->firstOrCreateUser(self::OWNER_EMAIL, 'Benchmark Owner');
        $tester = $this->firstOrCreateUser(self::TESTER_EMAIL, 'Benchmark Tester');

        $targets = [
            [
                'project' => [
                    'name' => 'Agent Benchmark - Sauce Demo',
                    'description' => 'Seeded benchmark project for evaluating Playwright agent understanding and execution on Sauce Demo.',
                    'app_url' => 'https://www.saucedemo.com',
                ],
                'checklist' => [
                    'name' => 'Agent Benchmark - Sauce Demo Checklist',
                    'description' => 'Benchmark checklist with known expected outcomes for Sauce Demo.',
                    'category' => 'Agent Benchmark',
                ],
                'source_app' => 'sauce_demo',
                'cases' => $this->sauceDemoCases(),
            ],
            [
                'project' => [
                    'name' => 'Agent Benchmark - The Internet',
                    'description' => 'Seeded benchmark project for evaluating Playwright agent understanding and execution on The Internet Herokuapp.',
                    'app_url' => 'https://the-internet.herokuapp.com',
                ],
                'checklist' => [
                    'name' => 'Agent Benchmark - The Internet Checklist',
                    'description' => 'Benchmark checklist with known expected outcomes for The Internet Herokuapp.',
                    'category' => 'Agent Benchmark',
                ],
                'source_app' => 'the_internet',
                'cases' => $this->internetCases(),
            ],
        ];

        foreach ($targets as $target) {
            $project = Project::updateOrCreate(
                ['name' => $target['project']['name']],
                [
                    'description' => $target['project']['description'],
                    'test_objectives' => 'Benchmark whether the automatic testing agent classifies scenarios correctly, executes the right strategy, and reports reliable results.',
                    'app_url' => $target['project']['app_url'],
                    'created_by' => $owner->id,
                    'status' => 'active',
                ]
            );

            $project->testers()->syncWithoutDetaching([$tester->id]);

            $checklist = Checklist::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'name' => $target['checklist']['name'],
                ],
                [
                    'description' => $target['checklist']['description'],
                    'category' => $target['checklist']['category'],
                    'project_id' => $project->id,
                    'is_active' => true,
                    'created_by' => $owner->id,
                    'priority' => 'critical',
                    'status' => 'ready_for_test',
                    'template_scope' => 'project',
                    'lifecycle_status' => 'approved',
                    'generated_from' => 'manual',
                    'acceptance_criteria' => 'Each benchmark item has a known expected classification and expected outcome for agent evaluation.',
                ]
            );

            $project->checklists()->syncWithoutDetaching([$checklist->id]);

            $seededItemIds = [];

            foreach ($target['cases'] as $index => $case) {
                $item = ChecklistItem::updateOrCreate(
                    [
                        'checklist_id' => $checklist->id,
                        'title' => $case['title'],
                    ],
                    [
                        'description' => $case['description'],
                        'priority' => $case['priority'],
                        'criticality' => $case['criticality'],
                        'order' => $index + 1,
                        'status' => 'pending',
                        'run_count' => 0,
                        'execution_profile' => [
                            'benchmark' => true,
                            'source_app' => $target['source_app'],
                            'expected_scenario_type' => $case['expected_scenario_type'],
                            'test_kind' => $case['test_kind'],
                        ],
                    ]
                );

                $seededItemIds[] = $item->id;

                AgentBenchmarkCase::updateOrCreate(
                    ['checklist_item_id' => $item->id],
                    [
                        'project_id' => $project->id,
                        'checklist_id' => $checklist->id,
                        'source_app' => $target['source_app'],
                        'project_url' => $project->app_url,
                        'expected_scenario_type' => $case['expected_scenario_type'],
                        'provided_inputs' => $case['provided_inputs'],
                        'expected_result' => $case['expected_result'],
                        'test_kind' => $case['test_kind'],
                        'enabled' => $case['enabled'],
                    ]
                );
            }

            AgentBenchmarkCase::query()
                ->where('checklist_id', $checklist->id)
                ->whereNotIn('checklist_item_id', $seededItemIds)
                ->delete();

            $checklist->items()
                ->whereNotIn('id', $seededItemIds)
                ->delete();
        }

        $this->command?->info('Agent benchmark data seeded with 100 benchmark cases across Sauce Demo and The Internet.');
    }

    private function firstOrCreateUser(string $email, string $name): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('password123'),
                'account_status' => 'active',
                'activated_at' => now(),
            ]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sauceDemoCases(): array
    {
        return [
            $this->case('Valid user can login', 'Verify the standard user can login successfully and reach the inventory page.', 'High', 'Critical', 'authentication', ['username' => 'standard_user', 'password' => 'secret_sauce'], 'passed', 'positive', ['products'], ['login', 'inventory']),
            $this->case('Locked out user cannot login', 'Verify the locked_out_user account is rejected with the expected locked out error.', 'High', 'Critical', 'authentication', ['username' => 'locked_out_user', 'password' => 'secret_sauce'], 'passed', 'negative', ['locked out'], ['error']),
            $this->case('Missing username prevents login', 'Verify leaving the username blank shows the required username validation message.', 'High', 'Major', 'form_required_fields', ['username' => '', 'password' => 'secret_sauce'], 'passed', 'negative', ['username is required'], ['error']),
            $this->case('Missing password prevents login', 'Verify leaving the password blank shows the required password validation message.', 'High', 'Major', 'form_required_fields', ['username' => 'standard_user', 'password' => ''], 'passed', 'negative', ['password is required'], ['error']),
            $this->case('Missing username and password prevents login', 'Verify submitting the login form with both credentials empty shows the username required validation message.', 'High', 'Major', 'form_required_fields', ['username' => '', 'password' => ''], 'passed', 'negative', ['username is required'], ['error']),
            $this->case('Invalid password shows login error', 'Verify an invalid password does not authenticate the user and an error message is shown.', 'High', 'Critical', 'form_validation_error', ['username' => 'standard_user', 'password' => 'wrong_password'], 'passed', 'negative', ['username and password do not match'], ['error']),
            $this->case('Invalid username shows login error', 'Verify an unknown username does not authenticate the user and an error message is shown.', 'High', 'Critical', 'form_validation_error', ['username' => 'unknown_user', 'password' => 'secret_sauce'], 'passed', 'negative', ['username and password do not match'], ['error']),
            $this->case('Inventory page requires login', 'Verify opening the inventory page without an authenticated session redirects to login.', 'High', 'Critical', 'authorization_access_control', [], 'passed', 'negative', ['swag labs'], ['redirect']),
            $this->case('Cart page requires login', 'Verify opening the cart page without an authenticated session redirects to login.', 'High', 'Critical', 'authorization_access_control', [], 'passed', 'negative', ['swag labs'], ['redirect']),
            $this->case('Add backpack to cart', 'Verify adding the Sauce Labs Backpack from inventory updates the cart badge.', 'High', 'Major', 'notification_feedback', ['username' => 'standard_user', 'password' => 'secret_sauce', 'product' => 'Sauce Labs Backpack'], 'passed', 'positive', ['remove', '1'], ['cart']),
            $this->case('Add bike light to cart', 'Verify adding the Sauce Labs Bike Light from inventory updates the cart badge.', 'Medium', 'Major', 'notification_feedback', ['username' => 'standard_user', 'password' => 'secret_sauce', 'product' => 'Sauce Labs Bike Light'], 'passed', 'positive', ['remove', '1'], ['cart']),
            $this->case('Cart badge updates after adding two items', 'Verify adding two inventory items updates the cart badge count to 2.', 'High', 'Major', 'notification_feedback', ['username' => 'standard_user', 'password' => 'secret_sauce', 'products' => ['Sauce Labs Backpack', 'Sauce Labs Bike Light']], 'passed', 'positive', ['2'], ['cart']),
            $this->case('Remove backpack from inventory page', 'Verify removing the Sauce Labs Backpack from inventory resets the button to Add to cart.', 'Medium', 'Major', 'notification_feedback', ['username' => 'standard_user', 'password' => 'secret_sauce', 'product' => 'Sauce Labs Backpack'], 'passed', 'positive', ['add to cart'], ['remove']),
            $this->case('Remove backpack from cart page', 'Verify removing an item from the cart page removes it from the cart list.', 'Medium', 'Major', 'notification_feedback', ['username' => 'standard_user', 'password' => 'secret_sauce', 'product' => 'Sauce Labs Backpack'], 'passed', 'positive', ['your cart'], ['remove']),
            $this->case('Cart retains added item after opening cart', 'Verify an item added from inventory is still present after navigating to the cart page.', 'Medium', 'Major', 'navigation_routing', ['username' => 'standard_user', 'password' => 'secret_sauce', 'product' => 'Sauce Labs Backpack'], 'passed', 'positive', ['sauce labs backpack'], ['cart']),
            $this->case('Product detail page opens from inventory', 'Verify clicking an inventory item opens the product detail page.', 'Medium', 'Major', 'navigation_routing', ['username' => 'standard_user', 'password' => 'secret_sauce', 'product' => 'Sauce Labs Backpack'], 'passed', 'positive', ['back to products'], ['detail']),
            $this->case('Back to products returns inventory list', 'Verify the Back to products button returns the user from a product detail page to inventory.', 'Medium', 'Major', 'navigation_routing', ['username' => 'standard_user', 'password' => 'secret_sauce', 'product' => 'Sauce Labs Backpack'], 'passed', 'positive', ['products'], ['back to products']),
            $this->case('Sort product names A to Z', 'Verify selecting Name (A to Z) sorts products alphabetically ascending.', 'Medium', 'Major', 'sorting_functionality', ['username' => 'standard_user', 'password' => 'secret_sauce', 'sort' => 'az'], 'passed', 'positive', ['name (a to z)'], ['sort']),
            $this->case('Sort product names Z to A', 'Verify selecting Name (Z to A) sorts products alphabetically descending.', 'Medium', 'Major', 'sorting_functionality', ['username' => 'standard_user', 'password' => 'secret_sauce', 'sort' => 'za'], 'passed', 'positive', ['name (z to a)'], ['sort']),
            $this->case('Sort price low to high', 'Verify selecting Price (low to high) sorts inventory by ascending price.', 'Medium', 'Major', 'sorting_functionality', ['username' => 'standard_user', 'password' => 'secret_sauce', 'sort' => 'lohi'], 'passed', 'positive', ['price (low to high)'], ['sort']),
            $this->case('Sort price high to low', 'Verify selecting Price (high to low) sorts inventory by descending price.', 'Medium', 'Major', 'sorting_functionality', ['username' => 'standard_user', 'password' => 'secret_sauce', 'sort' => 'hilo'], 'passed', 'positive', ['price (high to low)'], ['sort']),
            $this->case('Checkout valid flow completes order', 'Verify checkout succeeds with valid customer information and shows the completion page.', 'High', 'Critical', 'form_success_submission', ['username' => 'standard_user', 'password' => 'secret_sauce', 'product' => 'Sauce Labs Backpack', 'first_name' => 'Yahia', 'last_name' => 'Ghoufa', 'postal_code' => '1001'], 'passed', 'positive', ['thank you for your order'], ['finish']),
            $this->case('Checkout accepts alphanumeric postal code', 'Verify checkout accepts an alphanumeric postal code and completes successfully.', 'Medium', 'Major', 'form_success_submission', ['username' => 'standard_user', 'password' => 'secret_sauce', 'product' => 'Sauce Labs Backpack', 'first_name' => 'Yahia', 'last_name' => 'Ghoufa', 'postal_code' => 'H2B 3L1'], 'passed', 'edge_case', ['thank you for your order'], ['finish']),
            $this->case('Checkout missing first name shows validation', 'Verify checkout cannot continue when the first name is missing.', 'High', 'Major', 'form_required_fields', ['username' => 'standard_user', 'password' => 'secret_sauce', 'product' => 'Sauce Labs Backpack', 'first_name' => '', 'last_name' => 'Ghoufa', 'postal_code' => '1001'], 'passed', 'negative', ['first name is required'], ['error']),
            $this->case('Checkout missing last name shows validation', 'Verify checkout cannot continue when the last name is missing.', 'High', 'Major', 'form_required_fields', ['username' => 'standard_user', 'password' => 'secret_sauce', 'product' => 'Sauce Labs Backpack', 'first_name' => 'Yahia', 'last_name' => '', 'postal_code' => '1001'], 'passed', 'negative', ['last name is required'], ['error']),
            $this->case('Checkout missing postal code shows validation', 'Verify checkout cannot continue when the postal code is missing.', 'High', 'Major', 'form_required_fields', ['username' => 'standard_user', 'password' => 'secret_sauce', 'product' => 'Sauce Labs Backpack', 'first_name' => 'Yahia', 'last_name' => 'Ghoufa', 'postal_code' => ''], 'passed', 'negative', ['postal code is required'], ['error']),
            $this->case('Cancel checkout information step returns cart', 'Verify canceling checkout from the customer information step returns to the cart page.', 'Medium', 'Minor', 'navigation_routing', ['username' => 'standard_user', 'password' => 'secret_sauce', 'product' => 'Sauce Labs Backpack'], 'passed', 'positive', ['your cart'], ['cancel']),
            $this->case('Cancel checkout overview returns inventory', 'Verify canceling checkout from the overview step returns to the inventory page.', 'Medium', 'Minor', 'navigation_routing', ['username' => 'standard_user', 'password' => 'secret_sauce', 'product' => 'Sauce Labs Backpack'], 'passed', 'positive', ['products'], ['cancel']),
            $this->case('Finish checkout shows completion message', 'Verify completing checkout displays the thank-you confirmation content.', 'High', 'Major', 'notification_feedback', ['username' => 'standard_user', 'password' => 'secret_sauce', 'product' => 'Sauce Labs Backpack', 'first_name' => 'Yahia', 'last_name' => 'Ghoufa', 'postal_code' => '1001'], 'passed', 'positive', ['thank you for your order'], ['complete']),
            $this->case('Burger menu logout ends session', 'Verify logout from the side menu returns the user to the login page.', 'High', 'Critical', 'session_management', ['username' => 'standard_user', 'password' => 'secret_sauce'], 'passed', 'positive', ['swag labs'], ['logout']),
            $this->case('Burger menu About link opens external page', 'Verify the About menu item opens the Sauce Labs information page.', 'Low', 'Minor', 'navigation_routing', ['username' => 'standard_user', 'password' => 'secret_sauce'], 'passed', 'positive', ['sauce labs'], ['about']),
            $this->case('Reset app state clears cart badge', 'Verify Reset App State from the side menu clears added cart items and badge count.', 'Medium', 'Major', 'session_management', ['username' => 'standard_user', 'password' => 'secret_sauce', 'product' => 'Sauce Labs Backpack'], 'passed', 'positive', ['add to cart'], ['reset app state']),
            $this->case('Shopping cart link opens cart page', 'Verify clicking the cart icon opens the Your Cart page.', 'Low', 'Minor', 'navigation_routing', ['username' => 'standard_user', 'password' => 'secret_sauce'], 'passed', 'positive', ['your cart'], ['cart']),
            $this->unsupportedCase('Search product by name on Sauce Demo', 'Verify the agent recognizes Sauce Demo does not provide an inventory search field.', 'Medium', 'Minor', 'search_functionality', ['query' => 'backpack'], ['search'], ['search']),
            $this->unsupportedCase('Upload a file on Sauce Demo', 'Verify the agent recognizes Sauce Demo has no file upload feature in the storefront flow.', 'Low', 'Minor', 'file_upload_validation', ['file_name' => 'sample.txt'], ['upload'], ['upload']),
            $this->unsupportedCase('Reset password on Sauce Demo', 'Verify the agent recognizes Sauce Demo has no self-service password reset flow.', 'Low', 'Minor', 'password_reset', ['username' => 'standard_user'], ['password reset'], ['unsupported']),
            $this->unsupportedCase('Register a new user on Sauce Demo', 'Verify the agent recognizes Sauce Demo has no account registration flow.', 'Low', 'Minor', 'unsupported_feature', ['email' => 'new.user@example.com'], ['sign up', 'register'], ['unsupported']),
            $this->unsupportedCase('Apply coupon code during checkout on Sauce Demo', 'Verify the agent recognizes Sauce Demo checkout does not expose a coupon code input.', 'Low', 'Minor', 'form_validation_error', ['coupon' => 'SALE10'], ['coupon'], ['unsupported']),
            $this->unsupportedCase('Save product to wishlist on Sauce Demo', 'Verify the agent recognizes Sauce Demo does not provide a wishlist capability.', 'Low', 'Minor', 'crud_create', ['product' => 'Sauce Labs Backpack'], ['wishlist'], ['unsupported']),
            $this->unsupportedCase('Export product list as CSV on Sauce Demo', 'Verify the agent recognizes Sauce Demo does not provide product export from inventory.', 'Low', 'Minor', 'file_download_export', [], ['csv', 'export'], ['unsupported']),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function internetCases(): array
    {
        return [
            $this->case('Form authentication valid login succeeds', 'Verify form authentication succeeds with valid credentials and the secure area is displayed.', 'High', 'Critical', 'authentication', ['username' => 'tomsmith', 'password' => 'SuperSecretPassword!'], 'passed', 'positive', ['secure area'], ['login']),
            $this->case('Form authentication invalid username shows error', 'Verify an invalid username is rejected with the expected authentication error.', 'High', 'Critical', 'authentication', ['username' => 'wronguser', 'password' => 'SuperSecretPassword!'], 'passed', 'negative', ['your username is invalid'], ['error']),
            $this->case('Form authentication invalid password shows error', 'Verify an invalid password is rejected with the expected authentication error.', 'High', 'Critical', 'authentication', ['username' => 'tomsmith', 'password' => 'wrongpass'], 'passed', 'negative', ['your password is invalid'], ['error']),
            $this->case('Secure area logout returns login page', 'Verify logging out from the secure area returns the user to the login page.', 'High', 'Major', 'session_management', ['username' => 'tomsmith', 'password' => 'SuperSecretPassword!'], 'passed', 'positive', ['login page'], ['logout']),
            $this->case('Forgot password page loads', 'Verify the forgot password page is reachable from the examples list.', 'Low', 'Minor', 'password_reset', [], 'passed', 'positive', ['forgot password'], ['navigate']),
            $this->case('Dropdown shows default placeholder option', 'Verify the dropdown page initially shows the placeholder option before selection.', 'Low', 'Minor', 'dropdown_validation', [], 'passed', 'positive', ['please select an option'], ['dropdown']),
            $this->case('Dropdown can select Option 1', 'Verify selecting Option 1 updates the dropdown value.', 'Low', 'Minor', 'dropdown_validation', ['option' => 'Option 1'], 'passed', 'positive', ['option 1'], ['select']),
            $this->case('Dropdown can select Option 2', 'Verify selecting Option 2 updates the dropdown value.', 'Low', 'Minor', 'dropdown_validation', ['option' => 'Option 2'], 'passed', 'positive', ['option 2'], ['select']),
            $this->case('Dropdown can switch from Option 1 to Option 2', 'Verify the selected value can change from Option 1 to Option 2.', 'Low', 'Minor', 'dropdown_validation', ['option_sequence' => ['Option 1', 'Option 2']], 'passed', 'edge_case', ['option 2'], ['select']),
            $this->case('First checkbox can be checked', 'Verify the first checkbox can be checked on the checkboxes page.', 'Low', 'Minor', 'checkbox_radio_validation', ['checkbox' => 1, 'state' => 'checked'], 'passed', 'positive', ['checkboxes'], ['check']),
            $this->case('Second checkbox can be unchecked', 'Verify the second checkbox can be unchecked on the checkboxes page.', 'Low', 'Minor', 'checkbox_radio_validation', ['checkbox' => 2, 'state' => 'unchecked'], 'passed', 'positive', ['checkboxes'], ['uncheck']),
            $this->case('Both checkboxes can be checked', 'Verify both checkboxes can end in the checked state.', 'Low', 'Minor', 'checkbox_radio_validation', ['checkboxes' => 'both_checked'], 'passed', 'edge_case', ['checkboxes'], ['check']),
            $this->case('Checkbox states can both be cleared', 'Verify both checkboxes can end in the unchecked state after toggling.', 'Low', 'Minor', 'checkbox_radio_validation', ['checkboxes' => 'both_unchecked'], 'passed', 'edge_case', ['checkboxes'], ['uncheck']),
            $this->case('File upload accepts text file', 'Verify uploading a text file succeeds and the upload confirmation page is shown.', 'Medium', 'Major', 'file_upload_validation', ['file_name' => 'benchmark.txt'], 'passed', 'positive', ['file uploaded'], ['upload']),
            $this->case('Uploaded filename is displayed after upload', 'Verify the uploaded filename is visible on the upload result page.', 'Medium', 'Major', 'file_upload_validation', ['file_name' => 'benchmark.txt'], 'passed', 'positive', ['benchmark.txt'], ['upload']),
            $this->case('File upload accepts image file', 'Verify uploading an image file succeeds and returns the uploaded file name.', 'Medium', 'Major', 'file_upload_validation', ['file_name' => 'benchmark.png'], 'passed', 'edge_case', ['benchmark.png'], ['upload']),
            $this->case('File download page lists downloadable files', 'Verify the file download page exposes at least one downloadable file link.', 'Low', 'Minor', 'file_download_export', [], 'passed', 'positive', ['file downloader'], ['download']),
            $this->case('First downloadable file can be downloaded', 'Verify the first file link on the file download page triggers a file download.', 'Medium', 'Major', 'file_download_export', ['file_index' => 1], 'passed', 'positive', ['download'], ['file']),
            $this->case('Second downloadable file can be downloaded', 'Verify another file link on the file download page triggers a file download.', 'Medium', 'Major', 'file_download_export', ['file_index' => 2], 'passed', 'positive', ['download'], ['file']),
            $this->case('Dynamic loading example 1 reveals Hello World', 'Verify the hidden element dynamic loading example eventually shows Hello World after Start.', 'Medium', 'Major', 'loading_state_behavior', ['example' => 1], 'passed', 'positive', ['hello world'], ['loading']),
            $this->case('Dynamic loading example 2 renders Hello World', 'Verify the rendered element dynamic loading example eventually shows Hello World after Start.', 'Medium', 'Major', 'loading_state_behavior', ['example' => 2], 'passed', 'positive', ['hello world'], ['loading']),
            $this->case('Dynamic loading spinner disappears before assertion', 'Verify the agent waits for the loading indicator to finish before asserting the content.', 'Medium', 'Major', 'loading_state_behavior', ['example' => 1], 'passed', 'edge_case', ['hello world'], ['wait']),
            $this->case('Dynamic content remains hidden before start', 'Verify the dynamic content is not visible until the Start action is triggered.', 'Medium', 'Major', 'loading_state_behavior', ['example' => 2], 'passed', 'edge_case', ['start'], ['loading']),
            $this->case('JavaScript alert can be accepted', 'Verify accepting the JavaScript alert updates the result text.', 'Low', 'Minor', 'alert_dialog_behavior', [], 'passed', 'positive', ['you successfully clicked an alert'], ['alert']),
            $this->case('JavaScript confirm can be accepted', 'Verify accepting the JavaScript confirm updates the result text.', 'Low', 'Minor', 'alert_dialog_behavior', ['confirm' => 'accept'], 'passed', 'positive', ['you clicked: ok'], ['confirm']),
            $this->case('JavaScript confirm can be dismissed', 'Verify dismissing the JavaScript confirm updates the result text.', 'Low', 'Minor', 'alert_dialog_behavior', ['confirm' => 'dismiss'], 'passed', 'negative', ['you clicked: cancel'], ['confirm']),
            $this->case('JavaScript prompt accepts custom text', 'Verify submitting custom text in the JavaScript prompt shows the entered value.', 'Low', 'Minor', 'alert_dialog_behavior', ['prompt_text' => 'benchmark prompt'], 'passed', 'positive', ['benchmark prompt'], ['prompt']),
            $this->case('JavaScript prompt accepts empty text', 'Verify accepting the JavaScript prompt with empty text still updates the result area.', 'Low', 'Minor', 'alert_dialog_behavior', ['prompt_text' => ''], 'passed', 'edge_case', ['you entered'], ['prompt']),
            $this->case('Broken images page loads', 'Verify the broken images example page is reachable.', 'Low', 'Minor', 'error_page_handling', [], 'passed', 'positive', ['broken images'], ['navigate']),
            $this->case('Broken images page contains three image elements', 'Verify the broken images example displays three image elements in the page DOM.', 'Low', 'Minor', 'table_data_display', [], 'passed', 'positive', ['img'], ['image']),
            $this->case('Broken image detection flags missing assets', 'Verify the agent can detect that at least one image on the broken images page is broken.', 'Medium', 'Major', 'error_page_handling', [], 'passed', 'edge_case', ['broken'], ['image']),
            $this->case('Hovering avatar 1 shows profile caption', 'Verify hovering the first avatar reveals the profile caption.', 'Low', 'Minor', 'modal_dialog_behavior', ['avatar' => 1], 'passed', 'positive', ['name: user1'], ['hover']),
            $this->case('Hovering avatar 2 shows profile caption', 'Verify hovering the second avatar reveals the profile caption.', 'Low', 'Minor', 'modal_dialog_behavior', ['avatar' => 2], 'passed', 'positive', ['name: user2'], ['hover']),
            $this->case('Hovering avatar 3 shows profile caption', 'Verify hovering the third avatar reveals the profile caption.', 'Low', 'Minor', 'modal_dialog_behavior', ['avatar' => 3], 'passed', 'positive', ['name: user3'], ['hover']),
            $this->case('Hovering avatar reveals View profile link', 'Verify hovering an avatar reveals the View profile link.', 'Low', 'Minor', 'navigation_routing', ['avatar' => 1], 'passed', 'positive', ['view profile'], ['hover']),
            $this->case('Disappearing elements page loads navigation links', 'Verify the disappearing elements page is reachable and renders its navigation menu.', 'Low', 'Minor', 'navigation_routing', [], 'passed', 'positive', ['disappearing elements'], ['navigate']),
            $this->case('Disappearing elements Home link stays visible', 'Verify the Home link is visible on the disappearing elements page.', 'Low', 'Minor', 'navigation_routing', [], 'passed', 'positive', ['home'], ['menu']),
            $this->case('Disappearing elements optional links are tolerated', 'Verify the agent handles optional disappearing menu links without treating absence as a crash.', 'Medium', 'Minor', 'navigation_routing', [], 'passed', 'edge_case', ['home'], ['disappearing']),
            $this->case('Status code 200 page is reachable', 'Verify the Status Codes example can navigate to the 200 page.', 'Low', 'Minor', 'error_page_handling', ['status_code' => 200], 'passed', 'positive', ['this page returned a 200 status code'], ['status']),
            $this->case('Status code 301 page is reachable', 'Verify the Status Codes example can navigate to the 301 page.', 'Low', 'Minor', 'error_page_handling', ['status_code' => 301], 'passed', 'positive', ['this page returned a 301 status code'], ['status']),
            $this->case('Status code 404 page is reachable', 'Verify the Status Codes example can navigate to the 404 page.', 'Low', 'Minor', 'error_page_handling', ['status_code' => 404], 'passed', 'positive', ['this page returned a 404 status code'], ['status']),
            $this->case('Status code 500 page is reachable', 'Verify the Status Codes example can navigate to the 500 page.', 'Low', 'Minor', 'error_page_handling', ['status_code' => 500], 'passed', 'positive', ['this page returned a 500 status code'], ['status']),
            $this->case('Status codes index shows all status links', 'Verify the Status Codes index page shows the 200, 301, 404, and 500 links.', 'Low', 'Minor', 'navigation_routing', [], 'passed', 'positive', ['200', '301', '404', '500'], ['status codes']),
            $this->case('Redirector link navigates to Status Codes', 'Verify the Redirect Link example ultimately reaches the status codes page.', 'Low', 'Minor', 'navigation_routing', [], 'passed', 'positive', ['status codes'], ['redirect']),
            $this->case('Redirect chain completes successfully', 'Verify the Redirect Link example follows the redirect chain without a broken page.', 'Low', 'Minor', 'navigation_routing', [], 'passed', 'positive', ['status codes'], ['redirect']),
            $this->case('Add Element creates one Delete button', 'Verify clicking Add Element creates a Delete button.', 'Low', 'Minor', 'crud_create', ['add_count' => 1], 'passed', 'positive', ['delete'], ['add element']),
            $this->case('Add Element can create three Delete buttons', 'Verify clicking Add Element three times creates three Delete buttons.', 'Low', 'Minor', 'crud_create', ['add_count' => 3], 'passed', 'positive', ['delete'], ['add element']),
            $this->case('Delete button removes one added element', 'Verify clicking a Delete button removes one dynamic element.', 'Low', 'Minor', 'crud_delete_confirmation', ['add_count' => 2, 'remove_count' => 1], 'passed', 'positive', ['delete'], ['remove']),
            $this->case('Delete buttons can all be removed', 'Verify removing every Delete button clears the dynamic list.', 'Low', 'Minor', 'crud_delete_confirmation', ['add_count' => 3, 'remove_count' => 3], 'passed', 'positive', ['add element'], ['remove']),
            $this->case('Add and remove elements can be repeated', 'Verify the add/remove elements workflow can be repeated without breaking the page.', 'Low', 'Minor', 'crud_update', ['sequence' => ['add', 'add', 'remove', 'add']], 'passed', 'edge_case', ['delete'], ['add element']),
            $this->case('Numeric input accepts 42', 'Verify the Inputs example accepts a numeric value typed directly.', 'Low', 'Minor', 'form_success_submission', ['value' => 42], 'passed', 'positive', ['42'], ['input']),
            $this->case('Numeric input increments with ArrowUp', 'Verify pressing ArrowUp increases the numeric input value.', 'Low', 'Minor', 'form_success_submission', ['initial_value' => 1, 'key' => 'ArrowUp'], 'passed', 'positive', ['2'], ['arrowup']),
            $this->case('Numeric input decrements with ArrowDown', 'Verify pressing ArrowDown decreases the numeric input value.', 'Low', 'Minor', 'form_success_submission', ['initial_value' => 2, 'key' => 'ArrowDown'], 'passed', 'positive', ['1'], ['arrowdown']),
            $this->case('Numeric input accepts negative value', 'Verify the Inputs example accepts a negative number.', 'Low', 'Minor', 'form_success_submission', ['value' => -5], 'passed', 'edge_case', ['-5'], ['input']),
            $this->case('Numeric input accepts multiple digits', 'Verify the Inputs example accepts a multi-digit number.', 'Low', 'Minor', 'form_success_submission', ['value' => 12345], 'passed', 'edge_case', ['12345'], ['input']),
            $this->case('Key presses reports ENTER', 'Verify pressing ENTER updates the Key Presses result text.', 'Low', 'Minor', 'form_success_submission', ['key' => 'ENTER'], 'passed', 'positive', ['enter'], ['key presses']),
            $this->case('Key presses reports ESCAPE', 'Verify pressing ESCAPE updates the Key Presses result text.', 'Low', 'Minor', 'form_success_submission', ['key' => 'ESCAPE'], 'passed', 'positive', ['escape'], ['key presses']),
            $this->case('Key presses reports SPACE', 'Verify pressing SPACE updates the Key Presses result text.', 'Low', 'Minor', 'form_success_submission', ['key' => 'SPACE'], 'passed', 'positive', ['space'], ['key presses']),
            $this->case('Key presses reports BACK_SPACE', 'Verify pressing BACK_SPACE updates the Key Presses result text.', 'Low', 'Minor', 'form_success_submission', ['key' => 'BACK_SPACE'], 'passed', 'positive', ['back_space'], ['key presses']),
            $this->case('Key presses reports letter A', 'Verify pressing the A key updates the Key Presses result text.', 'Low', 'Minor', 'form_success_submission', ['key' => 'A'], 'passed', 'positive', ['a'], ['key presses']),
        ];
    }

    /**
     * @param  array<string, mixed>  $providedInputs
     * @param  array<int, string>  $assertionKeywords
     * @param  array<int, string>  $traceKeywords
     * @return array<string, mixed>
     */
    private function case(
        string $title,
        string $description,
        string $priority,
        string $criticality,
        string $expectedScenarioType,
        array $providedInputs,
        string $expectedStatus,
        string $testKind,
        array $assertionKeywords,
        array $traceKeywords,
        bool $enabled = true,
    ): array {
        return [
            'title' => $title,
            'description' => $description,
            'priority' => $priority,
            'criticality' => $criticality,
            'expected_scenario_type' => $expectedScenarioType,
            'provided_inputs' => $providedInputs,
            'expected_result' => [
                'expected_status' => $expectedStatus,
                'assertion_keywords' => $assertionKeywords,
                'trace_keywords' => $traceKeywords,
            ],
            'test_kind' => $testKind,
            'enabled' => $enabled,
        ];
    }

    /**
     * @param  array<string, mixed>  $providedInputs
     * @param  array<int, string>  $assertionKeywords
     * @param  array<int, string>  $traceKeywords
     * @return array<string, mixed>
     */
    private function unsupportedCase(
        string $title,
        string $description,
        string $priority,
        string $criticality,
        string $expectedScenarioType,
        array $providedInputs,
        array $assertionKeywords,
        array $traceKeywords,
    ): array {
        return $this->case(
            $title,
            $description,
            $priority,
            $criticality,
            $expectedScenarioType === 'unsupported_feature' ? 'unsupported_feature' : $expectedScenarioType,
            $providedInputs,
            'unsupported',
            'unsupported',
            $assertionKeywords,
            $traceKeywords,
        );
    }
}
