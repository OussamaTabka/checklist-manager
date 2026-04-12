<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ProjectVersion;
use App\Models\VersionItem;

function parseVersionId(array $argv): ?int
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--version=')) {
            $value = substr($arg, strlen('--version='));
            if ($value !== '' && ctype_digit($value)) {
                return (int) $value;
            }
        }
    }

    return null;
}

$requestedVersionId = parseVersionId($argv);

$version = $requestedVersionId
    ? ProjectVersion::find($requestedVersionId)
    : ProjectVersion::query()->orderByDesc('id')->first();

if (!$version) {
    fwrite(STDERR, "No project version found. Create a project/version first.\n");
    exit(1);
}

$nextOrder = (int) (VersionItem::query()
    ->where('project_version_id', $version->id)
    ->max('order') ?? 0);

$timestamp = now()->format('Ymd-His');

$templates = [
    [
        'title' => "[FORCED-FAIL {$timestamp}] Dashboard stats panel should be visible",
        'description' => 'Dashboard scenario. Run with use_auth=false so redirect to login prevents dashboard-stats selector from appearing.',
        'priority' => 'High',
        'criticality' => 'Critical',
    ],
    [
        'title' => "[FORCED-FAIL {$timestamp}] Projects table should load",
        'description' => 'Projects scenario. Run with use_auth=false so redirect to login prevents projects-table selector from appearing.',
        'priority' => 'High',
        'criticality' => 'Major',
    ],
    [
        'title' => "[FORCED-FAIL {$timestamp}] Users table should load",
        'description' => 'Users scenario. Run with use_auth=false so redirect to login prevents users-table selector from appearing.',
        'priority' => 'Medium',
        'criticality' => 'Major',
    ],
    [
        'title' => "[FORCED-FAIL {$timestamp}] Checklists table should load",
        'description' => 'Checklists scenario. Run with use_auth=false so redirect to login prevents checklists-table selector from appearing.',
        'priority' => 'Medium',
        'criticality' => 'Minor',
    ],
];

$created = [];

foreach ($templates as $template) {
    $nextOrder++;

    $item = VersionItem::create([
        'project_version_id' => $version->id,
        'title' => $template['title'],
        'description' => $template['description'],
        'priority' => $template['priority'],
        'criticality' => $template['criticality'],
        'order' => $nextOrder,
        'status' => 'Not Tested',
        'tested_by' => null,
        'tested_at' => null,
    ]);

    $created[] = $item;
}

echo "Created " . count($created) . " forced-fail test case items in project_version_id={$version->id}\n";
foreach ($created as $item) {
    echo " - id={$item->id} | {$item->title}\n";
}

echo "\nTo execute one and force failure, call run endpoint with use_auth=false and a local app base_url.\n";
echo "Example payload: {\"base_url\":\"http://localhost:5173\",\"use_auth\":false}\n";
