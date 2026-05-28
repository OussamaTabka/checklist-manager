<?php

namespace App\Services;

use App\Models\AgentBenchmarkCase;
use App\Models\TestResult;
use Illuminate\Support\Collection;

class AgentBenchmarkEvaluationService
{
    /**
     * @return array{
     *     cases: array<int, array<string, mixed>>,
     *     metrics: array<string, mixed>
     * }
     */
    public function evaluate(): array
    {
        $cases = AgentBenchmarkCase::query()
            ->with(['project', 'checklist', 'checklistItem'])
            ->where('enabled', true)
            ->orderBy('source_app')
            ->orderBy('id')
            ->get();

        $latestResults = $this->latestResultsForChecklistItems(
            $cases->pluck('checklist_item_id')->filter()->map(static fn ($id) => (int) $id)->all()
        );

        $rows = [];
        $scenarioComparable = 0;
        $scenarioMatched = 0;
        $statusComparable = 0;
        $statusMatched = 0;
        $falsePassCount = 0;
        $falseFailCount = 0;
        $missingResultsCount = 0;
        $unsupportedExpectedCount = 0;
        $unsupportedDetectedCount = 0;
        $assertionComparable = 0;
        $assertionMatched = 0;
        $traceComparable = 0;
        $traceMatched = 0;
        $evaluatedCases = 0;
        $environmentBlockedCount = 0;
        $pipelineName = null;

        foreach ($cases as $case) {
            $result = $latestResults->get($case->checklist_item_id);
            $resultPayload = $result?->result_payload;
            $expected = is_array($case->expected_result) ? $case->expected_result : [];
            $expectedStatus = $this->normalizeStatus($expected['expected_status'] ?? null);
            $predictedScenarioType = $this->extractPredictedScenarioType($resultPayload);
            $rawActualStatus = $this->normalizeStatus($result?->status);
            $actualStatus = $this->resolveActualStatus($result?->status, $predictedScenarioType, $resultPayload);
            $failureSource = $this->extractFailureSource($resultPayload);
            $errorMessage = $this->resolveErrorMessage($result);
            $environmentBlocked = $this->isEnvironmentBlocked($result, $failureSource);
            $rowPipeline = $this->resolvePipelineName($resultPayload);
            $pipelineName ??= $rowPipeline;

            $scenarioTypeMatch = null;
            if ($predictedScenarioType !== null) {
                $scenarioComparable++;
                $scenarioTypeMatch = $predictedScenarioType === $case->expected_scenario_type;
                if ($scenarioTypeMatch) {
                    $scenarioMatched++;
                }
            }

            $statusMatch = null;
            if ($result !== null && $expectedStatus !== null && $actualStatus !== null) {
                $statusComparable++;
                $statusMatch = $expectedStatus === $actualStatus;
                if ($statusMatch) {
                    $statusMatched++;
                }
            }

            if ($result === null) {
                $missingResultsCount++;
            } else {
                $evaluatedCases++;
                if ($environmentBlocked) {
                    $environmentBlockedCount++;
                }
            }

            if (in_array($expectedStatus, ['failed', 'unsupported'], true) && $rawActualStatus === 'passed') {
                $falsePassCount++;
            }

            if (!$environmentBlocked && $expectedStatus === 'passed' && $actualStatus === 'failed') {
                $falseFailCount++;
            }

            if ($expectedStatus === 'unsupported') {
                $unsupportedExpectedCount++;
                if ($actualStatus === 'unsupported' || $predictedScenarioType === 'unsupported_feature') {
                    $unsupportedDetectedCount++;
                }
            }

            $assertionMatch = $this->matchKeywords(
                $expected['assertion_keywords'] ?? [],
                $this->collectAssertionText($result?->result_payload),
                $result !== null,
                $assertionComparable,
                $assertionMatched,
            );

            $traceMatch = $this->matchKeywords(
                $expected['trace_keywords'] ?? [],
                $this->collectTraceText($result?->result_payload),
                $result !== null,
                $traceComparable,
                $traceMatched,
            );

            $rows[] = [
                'source_app' => $case->source_app,
                'pipeline' => $rowPipeline,
                'item_title' => $case->checklistItem?->title ?? 'Unknown item',
                'expected_scenario_type' => $case->expected_scenario_type,
                'predicted_scenario_type' => $predictedScenarioType,
                'expected_status' => $expectedStatus,
                'actual_status' => $actualStatus,
                'error_message' => $errorMessage,
                'failure_source' => $failureSource,
                'environment_blocked' => $environmentBlocked,
                'status_match' => $statusMatch,
                'scenario_type_match' => $scenarioTypeMatch,
                'assertion_keyword_match' => $assertionMatch,
                'trace_keyword_match' => $traceMatch,
                'final_evaluation' => $this->buildFinalEvaluation(
                    $result !== null,
                    $expectedStatus,
                    $actualStatus,
                    $environmentBlocked,
                    $statusMatch,
                    $scenarioTypeMatch,
                    $assertionMatch,
                    $traceMatch,
                ),
            ];
        }

        return [
            'cases' => $rows,
            'metrics' => [
                'pipeline_name' => $pipelineName ?? 'deterministic',
                'total_cases' => $cases->count(),
                'evaluated_cases' => $evaluatedCases,
                'agent_evaluated_cases' => max(0, $evaluatedCases - $environmentBlockedCount),
                'environment_blocked_count' => $environmentBlockedCount,
                'missing_results_count' => $missingResultsCount,
                'scenario_type_match_rate' => $this->rate($scenarioMatched, $scenarioComparable),
                'status_match_rate' => $this->rate($statusMatched, $statusComparable),
                'false_pass_count' => $falsePassCount,
                'false_fail_count' => $falseFailCount,
                'unsupported_detection_rate' => $this->rate($unsupportedDetectedCount, $unsupportedExpectedCount),
                'assertion_keyword_match_rate' => $this->rate($assertionMatched, $assertionComparable),
                'trace_keyword_match_rate' => $this->rate($traceMatched, $traceComparable),
            ],
        ];
    }

    /**
     * @param  array<int, int>  $checklistItemIds
     * @return Collection<int, TestResult>
     */
    private function latestResultsForChecklistItems(array $checklistItemIds): Collection
    {
        return TestResult::query()
            ->whereIn('checklist_item_id', $checklistItemIds)
            ->whereNotNull('checklist_item_id')
            ->orderByDesc('executed_at')
            ->orderByDesc('id')
            ->get()
            ->unique('checklist_item_id')
            ->keyBy('checklist_item_id');
    }

    /**
     * @param  mixed  $payload
     */
    private function extractPredictedScenarioType(mixed $payload): ?string
    {
        $candidates = [
            data_get($payload, 'predicted_scenario_type'),
            data_get($payload, 'scenario_type'),
            data_get($payload, 'run_spec.metadata.predicted_scenario_type'),
            data_get($payload, 'run_spec.cases.0.execution_profile.predicted_scenario_type'),
            data_get($payload, 'run_spec.cases.0.execution_profile.scenario_type'),
            data_get($payload, 'execution_profile.predicted_scenario_type'),
            data_get($payload, 'execution_profile.scenario_type'),
            data_get($payload, 'generated_plan.predicted_scenario_type'),
            data_get($payload, 'generated_plan.scenario_type'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * @param  mixed  $payload
     */
    private function resolveActualStatus(?string $status, ?string $predictedScenarioType, mixed $payload): ?string
    {
        if (
            $predictedScenarioType === 'unsupported_feature'
            || data_get($payload, 'error_type') === 'unsupported_feature'
        ) {
            return 'unsupported';
        }

        return $this->normalizeStatus($status);
    }

    private function extractFailureSource(mixed $payload): ?array
    {
        foreach ([
            data_get($payload, 'failure_source'),
            data_get($payload, 'error.failure_source'),
            data_get($payload, 'metadata.failure_source'),
        ] as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveErrorMessage(?TestResult $result): ?string
    {
        if ($result && is_string($result->error_message) && trim($result->error_message) !== '') {
            return trim($result->error_message);
        }

        foreach ([
            data_get($result?->result_payload, 'error_message'),
            data_get($result?->result_payload, 'error.error_message'),
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * @param  mixed  $status
     */
    private function normalizeStatus(mixed $status): ?string
    {
        if (!is_string($status) || trim($status) === '') {
            return null;
        }

        $normalized = mb_strtolower(trim($status));

        return match ($normalized) {
            'passed', 'failed', 'blocked', 'skipped', 'unsupported' => $normalized,
            default => null,
        };
    }

    /**
     * @param  mixed  $payload
     */
    private function collectAssertionText(mixed $payload): string
    {
        $assertions = [];

        foreach ([
            data_get($payload, 'generated_plan.asserts'),
            data_get($payload, 'run_spec.cases.0.asserts'),
            data_get($payload, 'assertions'),
            data_get($payload, 'expected_assertions'),
        ] as $candidate) {
            if (is_array($candidate)) {
                $assertions = array_merge($assertions, $this->flattenStrings($candidate));
            }
        }

        return mb_strtolower(implode(' ', $assertions));
    }

    /**
     * @param  mixed  $payload
     */
    private function collectTraceText(mixed $payload): string
    {
        $trace = [];

        foreach ([
            data_get($payload, 'execution_trace'),
            data_get($payload, 'trace'),
            data_get($payload, 'logs'),
        ] as $candidate) {
            if (is_array($candidate)) {
                $trace = array_merge($trace, $this->flattenStrings($candidate));
            }
        }

        return mb_strtolower(implode(' ', $trace));
    }

    /**
     * @param  mixed  $keywords
     */
    private function matchKeywords(
        mixed $keywords,
        string $haystack,
        bool $hasResult,
        int &$comparable,
        int &$matched,
    ): ?bool {
        $expectedKeywords = collect(is_array($keywords) ? $keywords : [])
            ->filter(static fn ($keyword) => is_string($keyword) && trim($keyword) !== '')
            ->map(static fn ($keyword) => mb_strtolower(trim($keyword)))
            ->values()
            ->all();

        if ($expectedKeywords === []) {
            return null;
        }

        if (!$hasResult) {
            return false;
        }

        $comparable++;
        $isMatch = collect($expectedKeywords)->every(
            static fn (string $keyword) => str_contains($haystack, $keyword)
        );

        if ($isMatch) {
            $matched++;
        }

        return $isMatch;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function flattenStrings(array $values): array
    {
        $flattened = [];

        foreach ($values as $value) {
            if (is_string($value)) {
                $flattened[] = $value;
                continue;
            }

            if (is_array($value)) {
                $flattened = array_merge($flattened, $this->flattenStrings($value));
            }
        }

        return $flattened;
    }

    private function buildFinalEvaluation(
        bool $hasResult,
        ?string $expectedStatus,
        ?string $actualStatus,
        bool $environmentBlocked,
        ?bool $statusMatch,
        ?bool $scenarioTypeMatch,
        ?bool $assertionMatch,
        ?bool $traceMatch,
    ): string {
        if (!$hasResult) {
            return 'missing_result';
        }

        if ($environmentBlocked) {
            return 'environment_blocked';
        }

        if (in_array($expectedStatus, ['failed', 'unsupported'], true) && $actualStatus === 'passed') {
            return 'false_pass';
        }

        if ($expectedStatus === 'passed' && $actualStatus === 'failed') {
            return 'false_fail';
        }

        if ($statusMatch === false) {
            return 'status_mismatch';
        }

        if ($scenarioTypeMatch === false) {
            return 'scenario_mismatch';
        }

        if ($assertionMatch === false) {
            return 'assertion_keywords_mismatch';
        }

        if ($traceMatch === false) {
            return 'trace_keywords_mismatch';
        }

        return 'matched';
    }

    private function rate(int $matched, int $total): float
    {
        if ($total === 0) {
            return 0.0;
        }

        return round(($matched / $total) * 100, 2);
    }

    private function isEnvironmentBlocked(?TestResult $result, ?array $failureSource): bool
    {
        if (!$result) {
            return false;
        }

        return $result->error_type === 'url_unreachable'
            && ($failureSource['phase'] ?? null) === 'initial_navigation';
    }

    /**
     * @param  mixed  $payload
     */
    private function resolvePipelineName(mixed $payload): string
    {
        $pipeline = data_get($payload, 'pipeline');

        return is_string($pipeline) && trim($pipeline) !== ''
            ? trim($pipeline)
            : 'deterministic';
    }
}
