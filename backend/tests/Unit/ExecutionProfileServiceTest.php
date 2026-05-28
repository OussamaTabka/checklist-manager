<?php

namespace Tests\Unit;

use App\Services\ExecutionProfileService;
use Tests\TestCase;

class ExecutionProfileServiceTest extends TestCase
{
    public function test_valid_sauce_demo_login_uses_normalized_identity_input_and_success_assertions(): void
    {
        $generated = app(ExecutionProfileService::class)->generateForPayload([
            'external_id' => 1,
            'test_case_title' => 'Valid user can login',
            'test_case_description' => 'Login with valid credentials and verify inventory loads.',
            'test_case_text' => 'Valid user can login. Login with valid credentials and verify inventory loads.',
            'base_url' => 'https://www.saucedemo.com',
            'target_type' => 'checklist_item',
            'provided_inputs' => [
                'username' => 'standard_user',
                'email' => 'standard_user',
                'password' => 'secret_sauce',
            ],
            'expected_result' => [
                'expected_status' => 'passed',
            ],
        ]);

        $steps = data_get($generated, 'run_spec.cases.0.steps', []);
        $asserts = data_get($generated, 'run_spec.cases.0.asserts', []);
        $requiredInputs = data_get($generated, 'run_spec.cases.0.execution_profile.required_inputs', []);

        $this->assertTrue(collect($steps)->contains(fn (array $step): bool => ($step['action'] ?? null) === 'fill' && ($step['input_key'] ?? null) === 'email'));
        $this->assertTrue(collect($steps)->contains(fn (array $step): bool => ($step['action'] ?? null) === 'fill' && ($step['input_key'] ?? null) === 'password'));
        $this->assertTrue(collect($asserts)->contains(fn (array $assert): bool => ($assert['type'] ?? null) === 'expect_url_contains' && ($assert['value'] ?? null) === '/inventory.html'));
        $this->assertTrue(collect($asserts)->contains(fn (array $assert): bool => ($assert['type'] ?? null) === 'expect_visible' && data_get($assert, 'selector.by') === 'text' && data_get($assert, 'selector.text') === 'Products'));
        $this->assertSame('standard_user', data_get(collect($requiredInputs)->firstWhere('key', 'email'), 'value'));
        $this->assertSame('secret_sauce', data_get(collect($requiredInputs)->firstWhere('key', 'password'), 'value'));
    }

    public function test_local_sauce_demo_login_uses_inventory_destination_when_source_app_matches(): void
    {
        $generated = app(ExecutionProfileService::class)->generateForPayload([
            'external_id' => 101,
            'test_case_title' => 'Valid user can login',
            'test_case_description' => 'Login with valid credentials and verify inventory loads.',
            'test_case_text' => 'Valid user can login. Login with valid credentials and verify inventory loads.',
            'base_url' => 'http://127.0.0.1:4177',
            'target_type' => 'checklist_item',
            'source_app' => 'sauce_demo',
            'provided_inputs' => [
                'username' => 'standard_user',
                'email' => 'standard_user',
                'password' => 'secret_sauce',
            ],
            'expected_result' => [
                'expected_status' => 'passed',
                'assertion_keywords' => ['products'],
            ],
        ]);

        $steps = data_get($generated, 'run_spec.cases.0.steps', []);
        $asserts = data_get($generated, 'run_spec.cases.0.asserts', []);
        $requiredInputs = data_get($generated, 'run_spec.cases.0.execution_profile.required_inputs', []);

        $this->assertTrue(collect($steps)->contains(fn (array $step): bool => ($step['action'] ?? null) === 'wait_for_url' && ($step['contains'] ?? null) === '/inventory.html'));
        $this->assertFalse(collect($steps)->contains(fn (array $step): bool => ($step['action'] ?? null) === 'wait_for_url' && ($step['contains'] ?? null) === '/dashboard'));
        $this->assertTrue(collect($asserts)->contains(fn (array $assert): bool => ($assert['type'] ?? null) === 'expect_url_contains' && ($assert['value'] ?? null) === '/inventory.html'));
        $this->assertTrue(collect($asserts)->contains(fn (array $assert): bool => ($assert['type'] ?? null) === 'expect_visible' && data_get($assert, 'selector.by') === 'text' && data_get($assert, 'selector.text') === 'Products'));
        $this->assertSame('standard_user', data_get(collect($requiredInputs)->firstWhere('key', 'email'), 'value'));
    }

    public function test_locked_out_sauce_demo_login_generates_error_assertion(): void
    {
        $generated = app(ExecutionProfileService::class)->generateForPayload([
            'external_id' => 2,
            'test_case_title' => 'Locked out user cannot login',
            'test_case_description' => 'Login with locked_out_user and verify the locked out error is shown.',
            'test_case_text' => 'Locked out user cannot login. Verify the locked out error is shown.',
            'base_url' => 'https://www.saucedemo.com',
            'target_type' => 'checklist_item',
            'provided_inputs' => [
                'username' => 'locked_out_user',
                'password' => 'secret_sauce',
            ],
            'expected_result' => [
                'expected_status' => 'passed',
                'assertion_keywords' => ['locked out'],
            ],
        ]);

        $steps = data_get($generated, 'run_spec.cases.0.steps', []);
        $asserts = data_get($generated, 'run_spec.cases.0.asserts', []);

        $this->assertFalse(collect($steps)->contains(fn (array $step): bool => ($step['action'] ?? null) === 'wait_for_url' && ($step['contains'] ?? null) === '/inventory.html'));
        $this->assertTrue(collect($steps)->contains(fn (array $step): bool => ($step['action'] ?? null) === 'wait_for_selector' && str_contains((string) data_get($step, 'selector.value', ''), 'h3[data-test="error"]')));
        $this->assertTrue(collect($asserts)->contains(fn (array $assert): bool => ($assert['type'] ?? null) === 'expect_visible' && str_contains((string) data_get($assert, 'selector.value', ''), '.error-message-container')));
        $this->assertTrue(collect($asserts)->contains(fn (array $assert): bool => ($assert['type'] ?? null) === 'expect_text_contains' && ($assert['text'] ?? null) === 'locked out'));
    }

    public function test_missing_username_login_allows_blank_identity_and_submits_for_validation_feedback(): void
    {
        $generated = app(ExecutionProfileService::class)->generateForPayload([
            'external_id' => 3,
            'test_case_title' => 'Missing username prevents login',
            'test_case_description' => 'Leave the username field blank and verify the required error is shown.',
            'test_case_text' => 'Missing username prevents login. Leave the username field blank and verify the required error is shown.',
            'base_url' => 'https://www.saucedemo.com',
            'target_type' => 'checklist_item',
            'provided_inputs' => [
                'username' => '',
                'email' => '',
                'password' => 'secret_sauce',
            ],
            'expected_result' => [
                'expected_status' => 'passed',
                'visible_text_contains' => 'Username is required',
            ],
        ]);

        $requiredInputs = data_get($generated, 'run_spec.cases.0.execution_profile.required_inputs', []);
        $steps = data_get($generated, 'run_spec.cases.0.steps', []);
        $asserts = data_get($generated, 'run_spec.cases.0.asserts', []);

        $identityInput = collect($requiredInputs)->firstWhere('key', 'email');

        $this->assertIsArray($identityInput);
        $this->assertTrue((bool) data_get($identityInput, 'allow_empty'));
        $this->assertFalse(collect($steps)->contains(fn (array $step): bool => ($step['action'] ?? null) === 'fill' && ($step['input_key'] ?? null) === 'email'));
        $this->assertTrue(collect($steps)->contains(fn (array $step): bool => ($step['action'] ?? null) === 'fill' && ($step['input_key'] ?? null) === 'password'));
        $this->assertTrue(collect($steps)->contains(fn (array $step): bool => ($step['action'] ?? null) === 'click'));
        $this->assertTrue(collect($asserts)->contains(fn (array $assert): bool => ($assert['type'] ?? null) === 'expect_text_contains' && ($assert['text'] ?? null) === 'Username is required'));
    }

    public function test_missing_password_login_allows_blank_password_and_submits_for_validation_feedback(): void
    {
        $generated = app(ExecutionProfileService::class)->generateForPayload([
            'external_id' => 4,
            'test_case_title' => 'Missing password prevents login',
            'test_case_description' => 'Leave the password field blank and verify the required password error is shown.',
            'test_case_text' => 'Missing password prevents login. Leave the password field blank and verify the required password error is shown.',
            'base_url' => 'http://127.0.0.1:4177',
            'target_type' => 'checklist_item',
            'source_app' => 'sauce_demo',
            'provided_inputs' => [
                'username' => 'standard_user',
                'email' => 'standard_user',
                'password' => '',
            ],
            'expected_result' => [
                'expected_status' => 'passed',
                'visible_text_contains' => 'Password is required',
            ],
        ]);

        $requiredInputs = data_get($generated, 'run_spec.cases.0.execution_profile.required_inputs', []);
        $steps = data_get($generated, 'run_spec.cases.0.steps', []);
        $asserts = data_get($generated, 'run_spec.cases.0.asserts', []);

        $passwordInput = collect($requiredInputs)->firstWhere('key', 'password');

        $this->assertIsArray($passwordInput);
        $this->assertTrue((bool) data_get($passwordInput, 'allow_empty'));
        $this->assertFalse(collect($steps)->contains(fn (array $step): bool => ($step['action'] ?? null) === 'fill' && ($step['input_key'] ?? null) === 'password'));
        $this->assertTrue(collect($steps)->contains(fn (array $step): bool => ($step['action'] ?? null) === 'fill' && ($step['input_key'] ?? null) === 'email'));
        $this->assertTrue(collect($asserts)->contains(fn (array $assert): bool => ($assert['type'] ?? null) === 'expect_text_contains' && ($assert['text'] ?? null) === 'Password is required'));
    }

    public function test_missing_username_and_password_login_allows_both_blank_fields(): void
    {
        $generated = app(ExecutionProfileService::class)->generateForPayload([
            'external_id' => 5,
            'test_case_title' => 'Missing username and password prevents login',
            'test_case_description' => 'Submit the login form with both credentials empty and verify the username required error is shown.',
            'test_case_text' => 'Missing username and password prevents login. Submit the login form with both credentials empty and verify the username required error is shown.',
            'base_url' => 'http://127.0.0.1:4177',
            'target_type' => 'checklist_item',
            'source_app' => 'sauce_demo',
            'provided_inputs' => [
                'username' => '',
                'email' => '',
                'password' => '',
            ],
            'expected_result' => [
                'expected_status' => 'passed',
                'visible_text_contains' => 'Username is required',
            ],
        ]);

        $requiredInputs = data_get($generated, 'run_spec.cases.0.execution_profile.required_inputs', []);
        $steps = data_get($generated, 'run_spec.cases.0.steps', []);

        $this->assertTrue((bool) data_get(collect($requiredInputs)->firstWhere('key', 'email'), 'allow_empty'));
        $this->assertTrue((bool) data_get(collect($requiredInputs)->firstWhere('key', 'password'), 'allow_empty'));
        $this->assertFalse(collect($steps)->contains(fn (array $step): bool => ($step['action'] ?? null) === 'fill' && ($step['input_key'] ?? null) === 'email'));
        $this->assertFalse(collect($steps)->contains(fn (array $step): bool => ($step['action'] ?? null) === 'fill' && ($step['input_key'] ?? null) === 'password'));
    }

    public function test_local_sauce_demo_protected_inventory_route_requires_login_plan(): void
    {
        $generated = app(ExecutionProfileService::class)->generateForPayload([
            'external_id' => 8,
            'test_case_title' => 'Inventory page requires login',
            'test_case_description' => 'Verify opening the inventory page without an authenticated session redirects to login.',
            'test_case_text' => 'Inventory page requires login. Verify opening the inventory page without an authenticated session redirects to login.',
            'base_url' => 'http://127.0.0.1:4177',
            'target_type' => 'checklist_item',
            'source_app' => 'sauce_demo',
            'provided_inputs' => [],
            'expected_result' => [
                'expected_status' => 'passed',
                'assertion_keywords' => ['swag labs'],
            ],
        ]);

        $requiredInputs = data_get($generated, 'run_spec.cases.0.execution_profile.required_inputs', []);
        $steps = data_get($generated, 'run_spec.cases.0.steps', []);
        $asserts = data_get($generated, 'run_spec.cases.0.asserts', []);

        $this->assertSame([], $requiredInputs);
        $this->assertTrue(collect($steps)->contains(fn (array $step): bool => ($step['action'] ?? null) === 'goto' && ($step['url'] ?? null) === '/inventory.html'));
        $this->assertTrue(collect($asserts)->contains(fn (array $assert): bool => ($assert['type'] ?? null) === 'expect_visible' && data_get($assert, 'selector.by') === 'text' && data_get($assert, 'selector.text') === 'Swag Labs'));
    }

    public function test_local_sauce_demo_add_backpack_plan_logs_in_and_asserts_cart_badge(): void
    {
        $generated = app(ExecutionProfileService::class)->generateForPayload([
            'external_id' => 10,
            'test_case_title' => 'Add backpack to cart',
            'test_case_description' => 'Verify adding the Sauce Labs Backpack from inventory updates the cart badge.',
            'test_case_text' => 'Add backpack to cart. Verify adding the Sauce Labs Backpack from inventory updates the cart badge.',
            'base_url' => 'http://127.0.0.1:4177',
            'target_type' => 'checklist_item',
            'source_app' => 'sauce_demo',
            'provided_inputs' => [
                'username' => 'standard_user',
                'password' => 'secret_sauce',
                'product' => 'Sauce Labs Backpack',
            ],
            'expected_result' => [
                'expected_status' => 'passed',
                'assertion_keywords' => ['remove', '1'],
            ],
        ]);

        $requiredInputs = data_get($generated, 'run_spec.cases.0.execution_profile.required_inputs', []);
        $steps = data_get($generated, 'run_spec.cases.0.steps', []);
        $asserts = data_get($generated, 'run_spec.cases.0.asserts', []);

        $this->assertSame('standard_user', data_get(collect($requiredInputs)->firstWhere('key', 'email'), 'value'));
        $this->assertSame('secret_sauce', data_get(collect($requiredInputs)->firstWhere('key', 'password'), 'value'));
        $this->assertTrue(collect($steps)->contains(fn (array $step): bool => ($step['action'] ?? null) === 'click' && str_contains((string) data_get($step, 'selector.value', ''), 'add-to-cart-sauce-labs-backpack')));
        $this->assertTrue(collect($asserts)->contains(fn (array $assert): bool => ($assert['type'] ?? null) === 'expect_text_contains' && ($assert['text'] ?? null) === '1'));
        $this->assertTrue(collect($asserts)->contains(fn (array $assert): bool => ($assert['type'] ?? null) === 'expect_visible' && str_contains((string) data_get($assert, 'selector.value', ''), 'remove-sauce-labs-backpack')));
    }

    public function test_local_sauce_demo_remove_from_cart_plan_navigates_to_cart_and_asserts_empty_state(): void
    {
        $generated = app(ExecutionProfileService::class)->generateForPayload([
            'external_id' => 14,
            'test_case_title' => 'Remove backpack from cart page',
            'test_case_description' => 'Verify removing an item from the cart page removes it from the cart list.',
            'test_case_text' => 'Remove backpack from cart page. Verify removing an item from the cart page removes it from the cart list.',
            'base_url' => 'http://127.0.0.1:4177',
            'target_type' => 'checklist_item',
            'source_app' => 'sauce_demo',
            'provided_inputs' => [
                'username' => 'standard_user',
                'password' => 'secret_sauce',
                'product' => 'Sauce Labs Backpack',
            ],
            'expected_result' => [
                'expected_status' => 'passed',
                'assertion_keywords' => ['your cart'],
            ],
        ]);

        $steps = data_get($generated, 'run_spec.cases.0.steps', []);
        $asserts = data_get($generated, 'run_spec.cases.0.asserts', []);

        $this->assertTrue(collect($steps)->contains(fn (array $step): bool => ($step['action'] ?? null) === 'click' && str_contains((string) data_get($step, 'selector.value', ''), 'shopping-cart-link')));
        $this->assertTrue(collect($steps)->contains(fn (array $step): bool => ($step['action'] ?? null) === 'wait_for_url' && ($step['contains'] ?? null) === '/cart.html'));
        $this->assertTrue(collect($asserts)->contains(fn (array $assert): bool => ($assert['type'] ?? null) === 'expect_visible' && str_contains((string) data_get($assert, 'selector.value', ''), 'empty-cart')));
    }

    public function test_local_sauce_demo_product_detail_plan_asserts_back_to_products(): void
    {
        $generated = app(ExecutionProfileService::class)->generateForPayload([
            'external_id' => 16,
            'test_case_title' => 'Product detail page opens from inventory',
            'test_case_description' => 'Verify clicking an inventory item opens the product detail page.',
            'test_case_text' => 'Product detail page opens from inventory. Verify clicking an inventory item opens the product detail page.',
            'base_url' => 'http://127.0.0.1:4177',
            'target_type' => 'checklist_item',
            'source_app' => 'sauce_demo',
            'provided_inputs' => [
                'username' => 'standard_user',
                'password' => 'secret_sauce',
                'product' => 'Sauce Labs Backpack',
            ],
            'expected_result' => [
                'expected_status' => 'passed',
                'assertion_keywords' => ['back to products'],
            ],
        ]);

        $steps = data_get($generated, 'run_spec.cases.0.steps', []);
        $asserts = data_get($generated, 'run_spec.cases.0.asserts', []);

        $this->assertTrue(collect($steps)->contains(fn (array $step): bool => ($step['action'] ?? null) === 'click' && str_contains((string) data_get($step, 'selector.value', ''), 'item-sauce-labs-backpack-title')));
        $this->assertTrue(collect($steps)->contains(fn (array $step): bool => ($step['action'] ?? null) === 'wait_for_url' && str_contains((string) ($step['contains'] ?? ''), '/inventory-item.html?id=sauce-labs-backpack')));
        $this->assertTrue(collect($asserts)->contains(fn (array $assert): bool => ($assert['type'] ?? null) === 'expect_text_contains' && ($assert['text'] ?? null) === 'Back to products'));
    }

    public function test_local_sauce_demo_sorting_plan_uses_sort_query_and_label_assertion(): void
    {
        $generated = app(ExecutionProfileService::class)->generateForPayload([
            'external_id' => 18,
            'test_case_title' => 'Sort product names A to Z',
            'test_case_description' => 'Verify selecting Name (A to Z) sorts products alphabetically ascending.',
            'test_case_text' => 'Sort product names A to Z. Verify selecting Name (A to Z) sorts products alphabetically ascending.',
            'base_url' => 'http://127.0.0.1:4177',
            'target_type' => 'checklist_item',
            'source_app' => 'sauce_demo',
            'provided_inputs' => [
                'username' => 'standard_user',
                'password' => 'secret_sauce',
                'sort' => 'az',
            ],
            'expected_result' => [
                'expected_status' => 'passed',
                'assertion_keywords' => ['name (a to z)'],
            ],
        ]);

        $steps = data_get($generated, 'run_spec.cases.0.steps', []);
        $asserts = data_get($generated, 'run_spec.cases.0.asserts', []);

        $this->assertTrue(collect($steps)->contains(fn (array $step): bool => ($step['action'] ?? null) === 'goto' && ($step['url'] ?? null) === '/inventory.html?sort=az'));
        $this->assertTrue(collect($asserts)->contains(fn (array $assert): bool => ($assert['type'] ?? null) === 'expect_text_contains' && ($assert['text'] ?? null) === 'Name (A to Z)'));
    }
}
