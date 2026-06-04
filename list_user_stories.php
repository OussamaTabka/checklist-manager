<?php
// Script to list user stories from the database

require __DIR__ . '/backend/vendor/autoload.php';
$app = require __DIR__ . '/backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\UserStory;

// Get all user stories with their projects
$userStories = UserStory::with('project')
    ->orderBy('priority')
    ->orderBy('created_at', 'desc')
    ->get();

echo "Found " . count($userStories) . " user stories:\n\n";
echo sprintf("%-4s | %-10s | %-40s | %-10s | %-20s\n", "ID", "Priority", "Title", "Status", "Project");
echo str_repeat("-", 120) . "\n";

foreach ($userStories as $story) {
    echo sprintf("%-4d | %-10s | %-40s | %-10s | %-20s\n",
        $story->id,
        $story->priority ?? 'N/A',
        substr($story->title, 0, 40),
        $story->status ?? 'N/A',
        substr($story->project?->name ?? 'N/A', 0, 20)
    );
}

echo "\n\nJSON format for evaluation selection:\n\n";
echo json_encode($userStories->map(fn($us) => [
    'id' => $us->id,
    'story_id' => $us->story_id,
    'title' => $us->title,
    'priority' => $us->priority,
    'status' => $us->status,
    'project' => $us->project?->name,
    'has_acceptance_criteria' => !empty($us->acceptance_criteria),
    'has_business_rules' => !empty($us->business_rules),
])->values(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
