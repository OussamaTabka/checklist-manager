<?php

namespace Tests\Feature;

use App\Models\AgentBenchmarkCase;
use Database\Seeders\AgentBenchmarkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AgentBenchmarkDatasetExportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_jsonl_export_is_valid(): void
    {
        $datasetPath = storage_path('app/datasets/agent_benchmark_dataset.jsonl');
        $reportPath = storage_path('app/datasets/agent_benchmark_report.json');
        File::delete($datasetPath);
        File::delete($reportPath);

        $this->seed(AgentBenchmarkSeeder::class);

        $this->artisan('agent:export-benchmark-dataset')
            ->assertExitCode(0);

        $this->assertFileExists($datasetPath);
        $this->assertFileExists($reportPath);

        $lines = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', (string) file_get_contents($datasetPath)) ?: [],
            static fn ($line) => trim($line) !== '',
        ));

        $this->assertCount(AgentBenchmarkCase::where('enabled', true)->count(), $lines);

        foreach ($lines as $line) {
            $record = json_decode($line, true);

            $this->assertIsArray($record);
            $this->assertIsArray($record['input'] ?? null);
            $this->assertIsArray($record['output'] ?? null);
            $this->assertIsArray($record['expected_result'] ?? null);
            $this->assertIsArray($record['metadata'] ?? null);
            $this->assertNotEmpty($record['output']['scenario_type'] ?? null);
        }

        $report = json_decode((string) file_get_contents($reportPath), true);
        $this->assertSame(100, $report['total_examples'] ?? null);
        $this->assertSame(40, $report['count_per_source_app']['sauce_demo'] ?? null);
        $this->assertSame(60, $report['count_per_source_app']['the_internet'] ?? null);
    }
}
