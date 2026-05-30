<?php

namespace Tests\Unit;

use App\Jobs\ExecuteSingleTestCaseRun;
use App\Models\VersionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class ExecuteSingleTestCaseRunTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_merge_provided_inputs_into_execution_profile_prefers_user_values(): void
    {
        $job = new ExecuteSingleTestCaseRun('test-run-id');

        $profile = [
            'required_inputs' => [
                [
                    'key' => 'email',
                    'label' => 'Email',
                    'kind' => 'email',
                    'required' => true,
                    'value' => null,
                ],
                [
                    'key' => 'password',
                    'label' => 'Password',
                    'kind' => 'password',
                    'required' => true,
                    'value' => null,
                ],
            ],
        ];

        $merged = $this->invokeMergeProvidedInputsIntoExecutionProfile($job, $profile, [
            'email' => 'qa.user@example.com',
            'password' => 'Secret123!',
        ], []);

        $this->assertSame('qa.user@example.com', $merged['required_inputs'][0]['value']);
        $this->assertSame('Secret123!', $merged['required_inputs'][1]['value']);
    }

    public function test_merge_provided_inputs_into_execution_profile_marks_intentionally_blank_required_field_as_allow_empty(): void
    {
        $job = new ExecuteSingleTestCaseRun('test-run-id');

        $profile = [
            'required_inputs' => [
                [
                    'key' => 'email',
                    'label' => 'Email or username',
                    'kind' => 'email',
                    'required' => true,
                    'value' => null,
                ],
            ],
        ];

        $merged = $this->invokeMergeProvidedInputsIntoExecutionProfile($job, $profile, [
            'email' => '',
        ], [
            'test_case_title' => 'Missing username prevents login',
            'test_case_description' => 'Verify leaving the username blank shows the required username validation message.',
            'expected_result' => [
                'assertion_keywords' => ['username is required'],
            ],
        ]);

        $this->assertSame('', $merged['required_inputs'][0]['value']);
        $this->assertTrue((bool) ($merged['required_inputs'][0]['allow_empty'] ?? false));
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

    private function invokeMergeProvidedInputsIntoExecutionProfile(
        ExecuteSingleTestCaseRun $job,
        array $profile,
        array $providedInputs,
        array $runPayload,
    ): array {
        $method = new ReflectionMethod(ExecuteSingleTestCaseRun::class, 'mergeProvidedInputsIntoExecutionProfile');
        $method->setAccessible(true);

        /** @var array<string, mixed> $merged */
        $merged = $method->invoke($job, $profile, $providedInputs, $runPayload);

        return $merged;
    }
}
