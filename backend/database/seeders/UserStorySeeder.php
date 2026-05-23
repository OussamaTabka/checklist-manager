<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\UserStory;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserStorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get first project or create one
        $project = Project::first();
        if (!$project) {
            $user = User::where('email', 'admin@example.com')->first();
            if (!$user) {
                $this->command->error('No projects found. Please create a project first.');
                return;
            }
            $project = Project::create([
                'name' => 'Sample Project',
                'description' => 'A sample project for testing user stories',
                'app_url' => 'http://example.com',
                'created_by' => $user->id,
            ]);
        }

        $creator = User::where('email', 'admin@example.com')->first() ?? User::first();

        // Create sample user stories
        $userStories = [
            [
                'story_id' => 'US-LOGIN-001',
                'title' => 'User can login with email and password',
                'description' => 'As a user, I want to login to the application using my email and password so that I can access my account.',
                'acceptance_criteria' => 'Given I am on the login page
When I enter a valid email and password
Then I should be redirected to the dashboard
And I should see a welcome message
Given I enter invalid credentials
When I click login
Then I should see an error message saying "Invalid credentials"',
                'priority' => 'critical',
                'status' => 'ready_for_test',
            ],
            [
                'story_id' => 'US-CHECKLIST-002',
                'title' => 'User can create a new checklist',
                'description' => 'As a project manager, I want to create a new checklist so that I can organize my test items and share with team members.',
                'acceptance_criteria' => 'Given I am on the checklists page
When I click "Create Checklist"
And I fill in the name and description
And I click Save
Then the checklist should appear in the list
And I should be redirected to the checklist details page',
                'priority' => 'high',
                'status' => 'in_progress',
            ],
            [
                'story_id' => 'US-CHECKLIST-003',
                'title' => 'User can add test items to a checklist',
                'description' => 'As a tester, I want to add individual test items to a checklist so that I can track test execution.',
                'acceptance_criteria' => 'Given I am viewing a checklist
When I click "Add Item"
And I enter the item title and description
And I select a criticality level
And I click Save
Then the item should appear in the checklist
And the item should be unchecked by default
And I should be able to add another item',
                'priority' => 'high',
                'status' => 'in_progress',
            ],
            [
                'story_id' => 'US-CHECKLIST-004',
                'title' => 'User can mark test items as complete',
                'description' => 'As a tester, I want to mark test items as complete so that I can track my progress.',
                'acceptance_criteria' => 'Given I am viewing a checklist with items
When I click the checkbox next to an item
Then the item should be marked as complete
And the checklist progress should update
And the completion percentage should show',
                'priority' => 'high',
                'status' => 'backlog',
            ],
            [
                'story_id' => 'US-FILTER-005',
                'title' => 'User can filter checklists by category',
                'description' => 'As a user, I want to filter checklists by category so that I can quickly find relevant checklists.',
                'acceptance_criteria' => 'Given I am on the checklists page
When I click the filter dropdown
And I select a category
Then only checklists from that category should display
And the selected filter should be highlighted
When I select "All Categories"
Then all checklists should display again',
                'priority' => 'medium',
                'status' => 'backlog',
            ],
            [
                'story_id' => 'US-NOTIFY-006',
                'title' => 'User receives email notification when assigned to project',
                'description' => 'As a tester, I want to receive an email when I am assigned to a project so that I am informed immediately.',
                'acceptance_criteria' => 'Given a user is assigned to a project
When the assignment is saved
Then an email should be sent to the user
And the email should contain the project name
And the email should include a link to the project
And the email should be professional and clear',
                'priority' => 'medium',
                'status' => 'ready_for_test',
            ],
            [
                'story_id' => 'US-EXPORT-007',
                'title' => 'User can export checklist as PDF',
                'description' => 'As a project manager, I want to export checklists as PDF so that I can share them offline and print them.',
                'acceptance_criteria' => 'Given I am viewing a checklist
When I click "Export as PDF"
Then a PDF file should be generated
And the PDF should include all checklist items with descriptions
And the PDF should show the completed/incomplete status
And the PDF should be downloaded to my computer',
                'priority' => 'medium',
                'status' => 'backlog',
            ],
            [
                'story_id' => 'US-DUPLICATE-008',
                'title' => 'User can duplicate an existing checklist',
                'description' => 'As a user, I want to duplicate an existing checklist so that I can reuse templates for similar test scenarios.',
                'acceptance_criteria' => 'Given I am viewing a checklist
When I click "Duplicate"
Then a copy of the checklist should be created
And the copy should have the same items
And the copy should have "(Copy)" in the name
And I should be able to edit the duplicated checklist',
                'priority' => 'low',
                'status' => 'backlog',
            ],
            [
                'story_id' => 'US-SEARCH-009',
                'title' => 'User can search for specific test items',
                'description' => 'As a user, I want to search for test items by keyword so that I can quickly find relevant tests.',
                'acceptance_criteria' => 'Given I am viewing a checklist with multiple items
When I type a keyword in the search box
Then only items matching the keyword should display
And the search should work across all field names and descriptions
And search results should update in real-time',
                'priority' => 'low',
                'status' => 'backlog',
            ],
            [
                'story_id' => 'US-REMINDER-010',
                'title' => 'User can set reminder for incomplete items',
                'description' => 'As a tester, I want to set reminders for incomplete test items so that I do not forget to complete them.',
                'acceptance_criteria' => 'Given I am viewing an incomplete test item
When I click "Set Reminder"
And I select a date and time
And I click Save
Then I should receive a reminder at the specified time
And the reminder should include the item details',
                'priority' => 'low',
                'status' => 'backlog',
            ],
            [
                'story_id' => 'US-GENERATE-011',
                'title' => 'System generates test cases from user stories',
                'description' => 'As a developer, I want the system to automatically generate test cases from user story acceptance criteria so that I can save time on test planning.',
                'acceptance_criteria' => 'Given I have a user story with acceptance criteria in Given-When-Then format
When I click "Generate Test Cases"
Then the system should parse the acceptance criteria
And create individual test cases for each scenario
And the test cases should appear in a new checklist
And each test case should have appropriate criticality assigned',
                'priority' => 'critical',
                'status' => 'in_progress',
            ],
        ];

        foreach ($userStories as $story) {
            UserStory::create([
                'project_id' => $project->id,
                'created_by' => $creator->id,
                'story_id' => $story['story_id'],
                'title' => $story['title'],
                'description' => $story['description'],
                'acceptance_criteria' => $story['acceptance_criteria'],
                'priority' => $story['priority'],
                'status' => $story['status'],
            ]);
        }

        $this->command->info('✅ ' . count($userStories) . ' user stories created successfully!');
        $this->command->info('Project: ' . $project->name);
    }
}
