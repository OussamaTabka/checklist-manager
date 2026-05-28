<?php

namespace Tests\Feature;

use App\Models\AgentBenchmarkCase;
use App\Models\Project;
use Database\Seeders\AgentBenchmarkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentBenchmarkSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_benchmark_projects(): void
    {
        $this->seed(AgentBenchmarkSeeder::class);

        $this->assertDatabaseHas('projects', ['name' => 'Agent Benchmark - Sauce Demo']);
        $this->assertDatabaseHas('projects', ['name' => 'Agent Benchmark - The Internet']);
    }

    public function test_seeder_creates_at_least_one_hundred_benchmark_cases(): void
    {
        $this->seed(AgentBenchmarkSeeder::class);

        $this->assertGreaterThanOrEqual(100, AgentBenchmarkCase::count());
    }

    public function test_each_benchmark_case_has_expected_scenario_type_and_expected_result(): void
    {
        $this->seed(AgentBenchmarkSeeder::class);

        $this->assertSame(
            0,
            AgentBenchmarkCase::query()
                ->whereNull('expected_scenario_type')
                ->orWhere('expected_scenario_type', '')
                ->count()
        );

        $this->assertSame(
            0,
            AgentBenchmarkCase::all()->filter(static fn (AgentBenchmarkCase $case) => empty($case->expected_result))->count()
        );
    }

    public function test_sauce_demo_and_the_internet_projects_exist_with_expected_urls(): void
    {
        $this->seed(AgentBenchmarkSeeder::class);

        $sauceDemo = Project::where('name', 'Agent Benchmark - Sauce Demo')->firstOrFail();
        $internet = Project::where('name', 'Agent Benchmark - The Internet')->firstOrFail();

        $this->assertSame('https://www.saucedemo.com', $sauceDemo->app_url);
        $this->assertSame('https://the-internet.herokuapp.com', $internet->app_url);
    }
}
