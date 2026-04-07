<?php

namespace Database\Seeders;

use App\Models\ProjectVersion;
use Illuminate\Database\Seeder;

class ExecutableTestItemsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $versions = ProjectVersion::with('items')->get();

        if ($versions->isEmpty()) {
            $this->command->info('No project versions found. Skipping executable test items seeder.');
            return;
        }

        foreach ($versions as $version) {
            $items = $version->items;

            if ($items->isEmpty()) {
                continue;
            }

            // Configure first 2 items for each version as executable
            foreach ($items->take(2) as $item) {
                $item->update([
                    'is_executable' => true,
                    'test_type' => 'ui',
                    'target_type' => 'ui',
                    'target_config' => [
                        'type' => 'ui',
                        'base_url' => 'http://localhost:5173',
                    ],
                    'steps_json' => [
                        [
                            'action' => 'goto',
                            'url' => 'http://localhost:5173',
                        ],
                        [
                            'action' => 'assert_visible',
                            'selector' => 'h1',
                        ],
                    ],
                    'expected_json' => [
                        'title_contains' => 'Checklist',
                        'url' => 'http://localhost:5173',
                    ],
                    'timeout_ms' => 30000,
                ]);

                $this->command->info("✓ Version {$version->id}: configured '{$item->title}' as executable UI test");
            }
        }

        $this->command->info('Executable test items seeder completed successfully!');
    }
}
