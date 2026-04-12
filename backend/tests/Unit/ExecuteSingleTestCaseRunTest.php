<?php

namespace Tests\Unit;

use App\Jobs\ExecuteSingleTestCaseRun;
use App\Models\VersionItem;
use ReflectionMethod;
use Tests\TestCase;

class ExecuteSingleTestCaseRunTest extends TestCase
{
    public function test_resolve_case_result_matches_external_id_when_present(): void
    {
        $job = new ExecuteSingleTestCaseRun('test-run-id');
        $item = $this->makeVersionItem(42, 'Checkout flow');

        $result = $this->invokeResolveCaseResult(
            $job,
            [
                ['external_id' => 11, 'status' => 'failed'],
                ['external_id' => '42', 'status' => 'passed'],
            ],
            $item,
            ['test_case_id' => 42, 'test_case_title' => 'Checkout flow'],
        );

        $this->assertIsArray($result);
        $this->assertSame('passed', $result['status']);
    }

    public function test_resolve_case_result_matches_title_when_external_id_missing(): void
    {
        $job = new ExecuteSingleTestCaseRun('test-run-id');
        $item = $this->makeVersionItem(77, 'Cross browser smoke');

        $result = $this->invokeResolveCaseResult(
            $job,
            [
                ['title' => 'Cross Browser Smoke', 'status' => 'blocked'],
            ],
            $item,
            ['test_case_id' => 77, 'test_case_title' => 'Cross browser smoke'],
        );

        $this->assertIsArray($result);
        $this->assertSame('blocked', $result['status']);
    }

    public function test_resolve_case_result_falls_back_to_single_entry_for_single_case_job(): void
    {
        $job = new ExecuteSingleTestCaseRun('test-run-id');
        $item = $this->makeVersionItem(99, 'Profile page');

        $result = $this->invokeResolveCaseResult(
            $job,
            [
                ['status' => 'passed'],
            ],
            $item,
            ['test_case_id' => 99, 'test_case_title' => 'Profile page'],
        );

        $this->assertIsArray($result);
        $this->assertSame('passed', $result['status']);
    }

    public function test_resolve_case_result_returns_null_when_multiple_unmatched_entries_exist(): void
    {
        $job = new ExecuteSingleTestCaseRun('test-run-id');
        $item = $this->makeVersionItem(12, 'Search test');

        $result = $this->invokeResolveCaseResult(
            $job,
            [
                ['external_id' => 101, 'title' => 'A', 'status' => 'failed'],
                ['external_id' => 102, 'title' => 'B', 'status' => 'passed'],
            ],
            $item,
            ['test_case_id' => 12, 'test_case_title' => 'Search test'],
        );

        $this->assertNull($result);
    }

    public function test_is_docker_infra_error_result_returns_true_for_docker_exit_code_error(): void
    {
        $job = new ExecuteSingleTestCaseRun('test-run-id');

        $isDockerInfra = $this->invokeIsDockerInfraErrorResult($job, [
            'status' => 'runner_error',
            'results' => [
                [
                    'error_type' => 'infra_error',
                    'error_message' => 'docker_exit_code_127; missing_or_invalid_result_json: ENOENT',
                ],
            ],
        ]);

        $this->assertTrue($isDockerInfra);
    }

    public function test_is_docker_infra_error_result_returns_false_for_non_docker_runner_error(): void
    {
        $job = new ExecuteSingleTestCaseRun('test-run-id');

        $isDockerInfra = $this->invokeIsDockerInfraErrorResult($job, [
            'status' => 'runner_error',
            'results' => [
                [
                    'error_type' => 'infra_error',
                    'error_message' => 'missing_or_invalid_result_json: ENOENT',
                ],
            ],
        ]);

        $this->assertFalse($isDockerInfra);
    }

    private function invokeResolveCaseResult(
        ExecuteSingleTestCaseRun $job,
        array $results,
        VersionItem $item,
        array $runPayload,
    ): ?array {
        $method = new ReflectionMethod(ExecuteSingleTestCaseRun::class, 'resolveCaseResult');
        $method->setAccessible(true);

        /** @var array<string, mixed>|null $resolved */
        $resolved = $method->invoke($job, $results, $item, $runPayload);

        return $resolved;
    }

    private function makeVersionItem(int $id, string $title): VersionItem
    {
        $item = new VersionItem();
        $item->id = $id;
        $item->title = $title;

        return $item;
    }

    private function invokeIsDockerInfraErrorResult(ExecuteSingleTestCaseRun $job, array $payload): bool
    {
        $method = new ReflectionMethod(ExecuteSingleTestCaseRun::class, 'isDockerInfraErrorResult');
        $method->setAccessible(true);

        return (bool) $method->invoke($job, $payload);
    }
}
