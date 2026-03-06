<?php

namespace Database\Seeders;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\VersionItem;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Database\Seeder;

class FakeDataSeeder extends Seeder
{
    public function run(): void
    {
        // Get the test users
        $admin = User::where('email', 'admin@test.com')->first();
        $chef = User::where('email', 'chef@test.com')->first();
        $testeur = User::where('email', 'testeur@test.com')->first();

        // Create Checklists with items
        $checklists = [
            [
                'name' => 'Web Application Security Checklist',
                'description' => 'Comprehensive security testing checklist for web applications',
                'category' => 'Security',
                'created_by' => $chef->id,
                'items' => [
                    ['title' => 'SQL Injection Testing', 'description' => 'Test for SQL injection vulnerabilities', 'priority' => 'High', 'criticality' => 'Critical'],
                    ['title' => 'XSS Attack Prevention', 'description' => 'Verify XSS protection mechanisms', 'priority' => 'High', 'criticality' => 'Critical'],
                    ['title' => 'CSRF Token Validation', 'description' => 'Check CSRF token implementation', 'priority' => 'High', 'criticality' => 'Critical'],
                    ['title' => 'Authentication Testing', 'description' => 'Test login, logout, session handling', 'priority' => 'High', 'criticality' => 'Critical'],
                    ['title' => 'Authorization Testing', 'description' => 'Verify role-based access control', 'priority' => 'High', 'criticality' => 'Major'],
                    ['title' => 'Password Policy Validation', 'description' => 'Check password strength requirements', 'priority' => 'Medium', 'criticality' => 'Major'],
                ]
            ],
            [
                'name' => 'API Testing Checklist',
                'description' => 'Standard API endpoint testing procedures',
                'category' => 'Automation',
                'created_by' => $chef->id,
                'items' => [
                    ['title' => 'Request/Response Validation', 'description' => 'Validate JSON schema compliance', 'priority' => 'High', 'criticality' => 'Critical'],
                    ['title' => 'Error Handling Tests', 'description' => 'Test error codes and messages', 'priority' => 'High', 'criticality' => 'Major'],
                    ['title' => 'Rate Limiting', 'description' => 'Verify rate limit enforcement', 'priority' => 'Medium', 'criticality' => 'Major'],
                    ['title' => 'Authentication Headers', 'description' => 'Check Bearer token validation', 'priority' => 'High', 'criticality' => 'Critical'],
                    ['title' => 'CORS Configuration', 'description' => 'Verify CORS headers', 'priority' => 'Medium', 'criticality' => 'Minor'],
                ]
            ],
            [
                'name' => 'Performance Testing Checklist',
                'description' => 'Performance and load testing procedures',
                'category' => 'Performance',
                'created_by' => $admin->id,
                'items' => [
                    ['title' => 'Page Load Time', 'description' => 'Measure page load under normal conditions', 'priority' => 'High', 'criticality' => 'Major'],
                    ['title' => 'Load Testing', 'description' => 'Simulate concurrent users', 'priority' => 'High', 'criticality' => 'Critical'],
                    ['title' => 'Memory Profiling', 'description' => 'Check memory usage patterns', 'priority' => 'Medium', 'criticality' => 'Major'],
                    ['title' => 'Database Query Performance', 'description' => 'Optimize slow queries', 'priority' => 'Medium', 'criticality' => 'Major'],
                ]
            ],
            [
                'name' => 'UI/UX Testing Checklist',
                'description' => 'User interface and experience testing',
                'category' => 'Manual',
                'created_by' => $chef->id,
                'items' => [
                    ['title' => 'Cross-Browser Compatibility', 'description' => 'Test on Chrome, Firefox, Safari, Edge', 'priority' => 'High', 'criticality' => 'Major'],
                    ['title' => 'Responsive Design', 'description' => 'Test on mobile, tablet, desktop', 'priority' => 'High', 'criticality' => 'Major'],
                    ['title' => 'Accessibility (WCAG)', 'description' => 'Check WCAG 2.1 compliance', 'priority' => 'Medium', 'criticality' => 'Major'],
                    ['title' => 'Form Validation', 'description' => 'Test all form input validations', 'priority' => 'High', 'criticality' => 'Major'],
                    ['title' => 'Navigation Testing', 'description' => 'Verify all navigation links work', 'priority' => 'Medium', 'criticality' => 'Minor'],
                    ['title' => 'Button Functionality', 'description' => 'Test all interactive buttons', 'priority' => 'Medium', 'criticality' => 'Minor'],
                ]
            ],
        ];

        $checklistIds = [];
        foreach ($checklists as $checklistData) {
            $items = $checklistData['items'];
            unset($checklistData['items']);

            $checklist = Checklist::create([
                ...$checklistData,
                'is_active' => true,
            ]);

            foreach ($items as $index => $item) {
                ChecklistItem::create([
                    'checklist_id' => $checklist->id,
                    'title' => $item['title'],
                    'description' => $item['description'],
                    'priority' => $item['priority'],
                    'criticality' => $item['criticality'],
                    'order' => $index,
                ]);
            }

            $checklistIds[] = $checklist->id;
        }

        // Create Projects
        $projectNames = [
            'E-Commerce Platform Redesign',
            'Mobile Banking API',
            'Cloud Dashboard',
            'Content Management System',
        ];

        $projects = [];
        foreach ($projectNames as $name) {
            $project = Project::create([
                'name' => $name,
                'description' => "Testing for {$name} implementation",
                'created_by' => $chef->id,
            ]);
            $projects[] = $project;
        }

        // Create Project Versions with Items
        $statuses = ['Not Tested', 'Passed', 'Failed', 'Blocked'];

        foreach ($projects as $index => $project) {
            $checklistId = $checklistIds[$index % count($checklistIds)];
            $checklist = Checklist::find($checklistId);

            $version = ProjectVersion::create([
                'project_id' => $project->id,
                'checklist_id' => $checklistId,
                'version_number' => 1,
            ]);

            foreach ($checklist->items as $item) {
                $status = $statuses[array_rand($statuses)];
                $versionItem = VersionItem::create([
                    'project_version_id' => $version->id,
                    'title' => $item->title,
                    'description' => $item->description,
                    'priority' => $item->priority,
                    'criticality' => $item->criticality,
                    'order' => $item->order,
                    'status' => $status,
                    'tested_by' => in_array($status, ['Passed', 'Failed', 'Blocked']) ? $testeur->id : null,
                    'tested_at' => in_array($status, ['Passed', 'Failed', 'Blocked']) ? now() : null,
                ]);

                // Add some comments to items with status
                if ($status !== 'Not Tested' && rand(0, 1)) {
                    $comments = [
                        'Passed' => [
                            'All tests passed successfully',
                            'No issues found',
                            'Working as expected',
                        ],
                        'Failed' => [
                            'Critical issue found - needs fixing',
                            'Unexpected behavior detected',
                            'Does not match requirements',
                        ],
                        'Blocked' => [
                            'Waiting for dependency',
                            'Cannot proceed until issue is resolved',
                            'Blocked by backend team',
                        ],
                    ];

                    $commentText = $comments[$status][array_rand($comments[$status])];
                    Comment::create([
                        'version_item_id' => $versionItem->id,
                        'user_id' => $testeur->id,
                        'content' => $commentText,
                    ]);
                }
            }

            // Create a second version for some projects
            if ($index % 2 === 0 && count($checklistIds) > 1) {
                $nextChecklistId = $checklistIds[($index + 1) % count($checklistIds)];
                $nextChecklist = Checklist::find($nextChecklistId);

                $version2 = ProjectVersion::create([
                    'project_id' => $project->id,
                    'checklist_id' => $nextChecklistId,
                    'version_number' => 2,
                ]);

                foreach ($nextChecklist->items as $item) {
                    $status = $statuses[array_rand($statuses)];
                    VersionItem::create([
                        'project_version_id' => $version2->id,
                        'title' => $item->title,
                        'description' => $item->description,
                        'priority' => $item->priority,
                        'criticality' => $item->criticality,
                        'order' => $item->order,
                        'status' => $status,
                        'tested_by' => in_array($status, ['Passed', 'Failed', 'Blocked']) ? $testeur->id : null,
                        'tested_at' => in_array($status, ['Passed', 'Failed', 'Blocked']) ? now() : null,
                    ]);
                }
            }
        }
    }
}
