<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\VersionItem;
use App\Models\Checklist;

// Get or create first user
$user = User::first() ?? User::create([
    'name' => 'Test User',
    'email' => 'test@example.com',
    'password' => bcrypt('password'),
]);

// Create checklist
$checklist = Checklist::create([
    'name' => 'Authentication Tests',
    'description' => 'Checklist for authentication functionality tests',
    'created_by' => $user->id,
]);

// Create project
$project = Project::create([
    'name' => 'Auth System Testing',
    'description' => 'Test cases for login authentication system on Sauce Demo',
    'app_url' => 'https://www.saucedemo.com',
    'created_by' => $user->id,
]);

// Create version
$version = ProjectVersion::create([
    'project_id' => $project->id,
    'checklist_id' => $checklist->id,
    'version_number' => '1.0',
]);

// Create test cases
$testCases = [
    [
        'title' => 'Login with valid credentials',
        'description' => 'User should be able to login with correct username and password on Sauce Demo',
        'test_type' => 'ui',
        'target_type' => 'ui',
        'target_config' => [
            'type' => 'ui',
            'base_url' => 'https://www.saucedemo.com'
        ],
        'steps_json' => [
            ['action' => 'goto', 'url' => '/'],
            ['action' => 'fill', 'selector' => '#user-name', 'value' => 'standard_user'],
            ['action' => 'fill', 'selector' => '#password', 'value' => 'secret_sauce'],
            ['action' => 'click', 'selector' => '#login-button'],
            ['action' => 'assert_text', 'text' => 'Products', 'timeout' => 5000]
        ],
        'expected_json' => [
            'status' => 'passed',
            'contains_text' => 'Products'
        ],
        'is_executable' => true,
        'order' => 1,
        'priority' => 'High',
        'criticality' => 'Critical',
    ],
    [
        'title' => 'Reject login with invalid credentials',
        'description' => 'Login should fail with incorrect username',
        'test_type' => 'ui',
        'target_type' => 'ui',
        'target_config' => [
            'type' => 'ui',
            'base_url' => 'https://www.saucedemo.com'
        ],
        'steps_json' => [
            ['action' => 'goto', 'url' => '/'],
            ['action' => 'fill', 'selector' => '#user-name', 'value' => 'invalid_user'],
            ['action' => 'fill', 'selector' => '#password', 'value' => 'wrong_password'],
            ['action' => 'click', 'selector' => '#login-button'],
            ['action' => 'assert_text', 'text' => 'Username and password do not match', 'timeout' => 3000]
        ],
        'expected_json' => [
            'status' => 'passed',
            'error_appears' => true
        ],
        'is_executable' => true,
        'order' => 2,
        'priority' => 'High',
        'criticality' => 'High',
    ],
    [
        'title' => 'Verify products page displays after login',
        'description' => 'After successful login, user should see the products inventory page',
        'test_type' => 'ui',
        'target_type' => 'ui',
        'target_config' => [
            'type' => 'ui',
            'base_url' => 'https://www.saucedemo.com'
        ],
        'steps_json' => [
            ['action' => 'goto', 'url' => '/'],
            ['action' => 'fill', 'selector' => '#user-name', 'value' => 'standard_user'],
            ['action' => 'fill', 'selector' => '#password', 'value' => 'secret_sauce'],
            ['action' => 'click', 'selector' => '#login-button'],
            ['action' => 'assert_text', 'text' => 'Sauce Labs Backpack', 'timeout' => 5000]
        ],
        'expected_json' => [
            'status' => 'passed',
            'product_loaded' => true
        ],
        'is_executable' => true,
        'order' => 3,
        'priority' => 'Medium',
        'criticality' => 'High',
    ],
];

foreach ($testCases as $testCase) {
    VersionItem::create(array_merge($testCase, ['project_version_id' => $version->id]));
}

echo "✅ Project created successfully!\n";
echo "Project ID: {$project->id}\n";
echo "Project Name: {$project->name}\n";
echo "App URL: {$project->app_url}\n";
echo "Version ID: {$version->id}\n";
echo "Test Cases: " . count($testCases) . " created\n\n";
echo "📋 Test Cases:\n";
foreach ($testCases as $idx => $test) {
    echo ($idx + 1) . ". " . $test['title'] . "\n";
}
