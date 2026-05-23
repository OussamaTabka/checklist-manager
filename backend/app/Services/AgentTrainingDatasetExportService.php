<?php

namespace App\Services;

use App\Models\ChecklistItem;
use App\Models\TestRun;
use App\Models\VersionItem;
use Illuminate\Support\Facades\File;

class AgentTrainingDatasetExportService
{
    private const OUTPUT_PATH = 'app/agent-training/training_dataset.jsonl';
    private const BUSINESS_STEP_ACTIONS = ['fill', 'click', 'press', 'wait_for_url', 'set_file'];

    /**
     * @return array{
     *     path:string,
     *     count:int,
     *     total_runs_found:int,
     *     rejected_count:int,
     *     rejection_reasons:array<string,int>,
     *     plans_with_fill:int,
     *     plans_with_click:int,
     *     plans_with_useful_asserts:int
     * }
     */
    public function export(): array
    {
        $runs = TestRun::query()
            ->with([
                'results',
                'results.checklistItem.checklist.project',
                'results.checklistItem.checklist.sourceUserStory',
                'results.versionItem',
                'projectVersion.project',
                'projectVersion.checklist',
                'checklist.project',
                'checklist.sourceUserStory',
            ])
            ->where('status', 'completed')
            ->orderBy('id')
            ->get();

        $outputPath = storage_path(self::OUTPUT_PATH);
        $directory = dirname($outputPath);

        if (!is_dir($directory)) {
            File::makeDirectory($directory, 0777, true);
        }

        $handle = fopen($outputPath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open training dataset output file for writing.');
        }

        $count = 0;
        $rejectedCount = 0;
        $rejectionReasons = [];
        $plansWithFill = 0;
        $plansWithClick = 0;
        $plansWithUsefulAsserts = 0;

        try {
            foreach ($runs as $run) {
                foreach ($run->results as $result) {
                    if ($result->status !== 'passed') {
                        continue;
                    }

                    $item = $result->checklistItem ?: $result->versionItem;
                    if (!$item instanceof ChecklistItem && !$item instanceof VersionItem) {
                        continue;
                    }

                    $output = $this->buildOutputPayload($result->result_payload, $item->execution_profile);
                    $quality = $this->evaluateOutputQuality($output);

                    if (!$quality['accepted']) {
                        $rejectedCount++;
                        foreach ($quality['reasons'] as $reason) {
                            $rejectionReasons[$reason] = ($rejectionReasons[$reason] ?? 0) + 1;
                        }
                        continue;
                    }

                    if ($quality['has_fill']) {
                        $plansWithFill++;
                    }

                    if ($quality['has_click']) {
                        $plansWithClick++;
                    }

                    if ($quality['has_useful_asserts']) {
                        $plansWithUsefulAsserts++;
                    }

                    $record = [
                        'input' => $this->sanitizeData($this->buildInputPayload($run, $item)),
                        'output' => $this->sanitizeData($output),
                        'metadata' => $this->buildMetadataPayload($run, $result->result_payload, $result->duration_ms),
                    ];

                    fwrite($handle, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
                    $count++;
                }
            }
        } finally {
            fclose($handle);
        }

        return [
            'path' => $outputPath,
            'count' => $count,
            'total_runs_found' => $runs->count(),
            'rejected_count' => $rejectedCount,
            'rejection_reasons' => $rejectionReasons,
            'plans_with_fill' => $plansWithFill,
            'plans_with_click' => $plansWithClick,
            'plans_with_useful_asserts' => $plansWithUsefulAsserts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInputPayload(TestRun $run, ChecklistItem|VersionItem $item): array
    {
        $requestPayload = is_array($run->request_payload) ? $run->request_payload : [];
        $checklist = $item instanceof ChecklistItem
            ? $item->checklist
            : $run->projectVersion?->checklist;
        $project = $item instanceof ChecklistItem
            ? $checklist?->project
            : $run->projectVersion?->project;
        $story = $checklist?->sourceUserStory;

        return [
            'title' => (string) ($requestPayload['test_case_title'] ?? $item->title ?? ''),
            'description' => (string) ($requestPayload['test_case_description'] ?? $item->description ?? ''),
            'base_url' => $this->sanitizeUrl((string) ($run->base_url ?: ($requestPayload['base_url'] ?? ''))),
            'provided_inputs' => is_array($requestPayload['provided_inputs'] ?? null) ? $requestPayload['provided_inputs'] : [],
            'priority' => (string) ($requestPayload['priority'] ?? $item->priority ?? ''),
            'criticality' => (string) ($requestPayload['criticality'] ?? $item->criticality ?? ''),
            'context' => array_filter([
                'target_type' => $requestPayload['target_type'] ?? ($item instanceof ChecklistItem ? 'checklist_item' : 'version_item'),
                'project' => $project ? [
                    'id' => $project->id,
                    'name' => $project->name,
                    'description' => $project->description,
                    'test_objectives' => $project->test_objectives,
                ] : null,
                'checklist' => $checklist ? [
                    'id' => $checklist->id,
                    'name' => $checklist->name,
                    'description' => $checklist->description,
                    'acceptance_criteria' => $checklist->acceptance_criteria,
                    'business_rules' => $checklist->business_rules,
                    'priority' => $checklist->priority,
                    'status' => $checklist->status,
                ] : null,
                'source_user_story' => $story ? [
                    'id' => $story->id,
                    'story_id' => $story->story_id,
                    'title' => $story->title,
                    'description' => $story->description,
                    'acceptance_criteria' => $story->acceptance_criteria,
                    'business_rules' => $story->business_rules,
                    'scenarios' => $story->scenarios,
                    'priority' => $story->priority,
                    'status' => $story->status,
                ] : null,
            ], static fn ($value) => $value !== null && $value !== []),
        ];
    }

    /**
     * @param  mixed  $resultPayload
     * @param  mixed  $executionProfile
     * @return array<string, mixed>
     */
    private function buildOutputPayload(mixed $resultPayload, mixed $executionProfile): array
    {
        $payload = is_array($resultPayload) ? $resultPayload : [];
        $profile = is_array($executionProfile) ? $executionProfile : [];

        $output = [];

        if (is_array($payload['run_spec'] ?? null)) {
            $output['run_spec'] = $payload['run_spec'];
        }

        if ($profile !== []) {
            $output['execution_profile'] = $profile;
        }

        if (is_array($payload['generated_plan'] ?? null)) {
            $output['generated_plan'] = $payload['generated_plan'];
        } elseif (is_array($profile['last_generated_plan'] ?? null)) {
            $output['generated_plan'] = $profile['last_generated_plan'];
        }

        return $output;
    }

    /**
     * @param  mixed  $resultPayload
     * @return array<string, mixed>
     */
    private function buildMetadataPayload(TestRun $run, mixed $resultPayload, ?int $durationMs): array
    {
        $payload = is_array($resultPayload) ? $resultPayload : [];

        return [
            'run_id' => $run->run_id,
            'status' => 'passed',
            'error_type' => isset($payload['error_type']) && is_string($payload['error_type']) ? $payload['error_type'] : null,
            'duration_ms' => $durationMs,
            'created_at' => optional($run->created_at)->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array{
     *     accepted:bool,
     *     reasons:array<int,string>,
     *     has_fill:bool,
     *     has_click:bool,
     *     has_useful_asserts:bool
     * }
     */
    private function evaluateOutputQuality(array $output): array
    {
        $steps = $this->resolvePlanSteps($output);
        $asserts = $this->resolvePlanAsserts($output);
        $coverageType = $this->resolveCoverageType($output);
        $diagnostics = $this->resolveDiagnostics($output);

        $hasFill = $this->hasStepAction($steps, 'fill');
        $hasClick = $this->hasStepAction($steps, 'click');
        $hasUsefulAssert = $this->hasUsefulAssert($asserts);
        $hasBusinessStep = $this->hasBusinessStep($steps) || $hasUsefulAssert;

        $reasons = [];

        if ($coverageType === 'generic_ui') {
            $reasons[] = 'coverage_type_generic_ui';
        }

        if ($this->containsGenericUiFallbackDiagnostic($diagnostics)) {
            $reasons[] = 'diagnostics_generic_ui_fallback';
        }

        if ($this->isGenericUiFallbackPlan($steps)) {
            $reasons[] = 'generic_ui_fallback_plan';
        }

        if ($asserts !== [] && $this->areOnlyBodyVisibilityAsserts($asserts)) {
            $reasons[] = 'asserts_only_expect_visible_body';
        }

        if (!$hasBusinessStep) {
            $reasons[] = 'no_business_steps';
        }

        return [
            'accepted' => $reasons === [],
            'reasons' => array_values(array_unique($reasons)),
            'has_fill' => $hasFill,
            'has_click' => $hasClick,
            'has_useful_asserts' => $hasUsefulAssert,
        ];
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<int, array<string, mixed>>
     */
    private function resolvePlanSteps(array $output): array
    {
        $steps = is_array(data_get($output, 'run_spec.cases.0.steps'))
            ? data_get($output, 'run_spec.cases.0.steps')
            : [];

        if ($steps !== []) {
            return array_values(array_filter($steps, 'is_array'));
        }

        $steps = is_array(data_get($output, 'generated_plan.steps'))
            ? data_get($output, 'generated_plan.steps')
            : [];

        return array_values(array_filter($steps, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<int, array<string, mixed>>
     */
    private function resolvePlanAsserts(array $output): array
    {
        $asserts = is_array(data_get($output, 'run_spec.cases.0.asserts'))
            ? data_get($output, 'run_spec.cases.0.asserts')
            : [];

        if ($asserts !== []) {
            return array_values(array_filter($asserts, 'is_array'));
        }

        $asserts = is_array(data_get($output, 'generated_plan.asserts'))
            ? data_get($output, 'generated_plan.asserts')
            : [];

        return array_values(array_filter($asserts, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<int, mixed>
     */
    private function resolveDiagnostics(array $output): array
    {
        $diagnostics = data_get($output, 'run_spec.cases.0.execution_profile.diagnostics');
        if (is_array($diagnostics) && $diagnostics !== []) {
            return $diagnostics;
        }

        $diagnostics = data_get($output, 'generated_plan.diagnostics');
        if (is_array($diagnostics) && $diagnostics !== []) {
            return $diagnostics;
        }

        $diagnostics = data_get($output, 'execution_profile.diagnostics');

        return is_array($diagnostics) ? $diagnostics : [];
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function resolveCoverageType(array $output): string
    {
        $coverageType = data_get($output, 'run_spec.cases.0.execution_profile.coverage_type');
        if (is_string($coverageType) && $coverageType !== '') {
            return mb_strtolower($coverageType);
        }

        $coverageType = data_get($output, 'generated_plan.coverage_type');
        if (is_string($coverageType) && $coverageType !== '') {
            return mb_strtolower($coverageType);
        }

        $coverageType = data_get($output, 'execution_profile.coverage_type');

        return is_string($coverageType) ? mb_strtolower($coverageType) : '';
    }

    /**
     * @param  array<int, mixed>  $diagnostics
     */
    private function containsGenericUiFallbackDiagnostic(array $diagnostics): bool
    {
        foreach ($this->flattenStrings($diagnostics) as $value) {
            if (mb_strtoupper($value) === 'GENERIC_UI_FALLBACK') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function hasBusinessStep(array $steps): bool
    {
        foreach ($steps as $step) {
            $action = $this->normalizeStepAction($step);
            if (in_array($action, self::BUSINESS_STEP_ACTIONS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function hasStepAction(array $steps, string $expectedAction): bool
    {
        foreach ($steps as $step) {
            if ($this->normalizeStepAction($step) === $expectedAction) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function isGenericUiFallbackPlan(array $steps): bool
    {
        if ($steps === []) {
            return false;
        }

        $allowedPatterns = ['goto', 'wait_for_selector_body', 'screenshot'];

        foreach ($steps as $step) {
            if (!in_array($this->classifyFallbackStep($step), $allowedPatterns, true)) {
                return false;
            }
        }

        return $this->hasStepAction($steps, 'goto')
            && $this->hasBodyWaitForSelector($steps)
            && $this->hasStepAction($steps, 'screenshot');
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function hasBodyWaitForSelector(array $steps): bool
    {
        foreach ($steps as $step) {
            if ($this->classifyFallbackStep($step) === 'wait_for_selector_body') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function classifyFallbackStep(array $step): string
    {
        $action = $this->normalizeStepAction($step);

        if ($action === 'wait_for_selector' && $this->stepTargetsBody($step)) {
            return 'wait_for_selector_body';
        }

        return $action;
    }

    /**
     * @param  array<int, array<string, mixed>>  $asserts
     */
    private function areOnlyBodyVisibilityAsserts(array $asserts): bool
    {
        foreach ($asserts as $assert) {
            if (!$this->isBodyVisibilityAssert($assert)) {
                return false;
            }
        }

        return $asserts !== [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $asserts
     */
    private function hasUsefulAssert(array $asserts): bool
    {
        foreach ($asserts as $assert) {
            if (!$this->isBodyVisibilityAssert($assert)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $assert
     */
    private function isBodyVisibilityAssert(array $assert): bool
    {
        $assertType = mb_strtolower((string) ($assert['assert'] ?? $assert['type'] ?? ''));
        if (!in_array($assertType, ['expect_visible', 'visible'], true)) {
            return false;
        }

        $target = mb_strtolower(trim((string) ($assert['target'] ?? $assert['selector'] ?? $assert['value'] ?? '')));
        if ($target === 'body') {
            return true;
        }

        $selector = $assert['selector'] ?? null;
        if (is_array($selector)) {
            $selectorValue = mb_strtolower(trim((string) ($selector['value'] ?? $selector['css'] ?? $selector['selector'] ?? '')));
            if ($selectorValue === 'body') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function stepTargetsBody(array $step): bool
    {
        $selector = $step['selector'] ?? null;
        if (is_string($selector)) {
            return mb_strtolower(trim($selector)) === 'body';
        }

        if (!is_array($selector)) {
            return false;
        }

        $candidates = [
            $selector['value'] ?? null,
            $selector['css'] ?? null,
            $selector['selector'] ?? null,
            $selector['testid'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && mb_strtolower(trim($candidate)) === 'body') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function normalizeStepAction(array $step): string
    {
        return mb_strtolower(trim((string) ($step['action'] ?? '')));
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
                $flattened[] = trim($value);
                continue;
            }

            if (is_array($value)) {
                $flattened = array_merge($flattened, $this->flattenStrings($value));
            }
        }

        return $flattened;
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function sanitizeData(mixed $value): mixed
    {
        if (is_array($value)) {
            if (
                isset($value['key'], $value['value'])
                && is_string($value['key'])
                && $this->isSensitiveKey(mb_strtolower($value['key']))
            ) {
                $value['value'] = $this->redactionForKey(mb_strtolower($value['key']));
            }

            $sanitized = [];

            foreach ($value as $key => $item) {
                $normalizedKey = is_string($key) ? mb_strtolower($key) : '';
                $sanitized[$key] = $this->isSensitiveKey($normalizedKey)
                    ? $this->redactionForKey($normalizedKey)
                    : $this->sanitizeData($item);
            }

            return $sanitized;
        }

        if (is_string($value)) {
            return $this->sanitizeString($value);
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        if ($key === '') {
            return false;
        }

        return (bool) preg_match('/(?:email|mail|password|passwd|pwd|secret|token|api[_-]?key|auth|authorization|bearer|cookie|session|credit|card|iban|ssn)/i', $key);
    }

    private function redactionForKey(string $key): string
    {
        if ((bool) preg_match('/(?:email|mail)/i', $key)) {
            return '[REDACTED_EMAIL]';
        }

        if ((bool) preg_match('/(?:password|passwd|pwd)/i', $key)) {
            return '[REDACTED_PASSWORD]';
        }

        return '[REDACTED]';
    }

    private function sanitizeString(string $value): string
    {
        $sanitized = $this->sanitizeUrl($value);
        $sanitized = preg_replace('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i', '[REDACTED_EMAIL]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('#\b(Bearer)\s+[A-Za-z0-9._\-~+/]+=*\b#i', '$1 [REDACTED_TOKEN]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\b(?:ghp|gho|ghu|ghs|sk|pk)_[A-Za-z0-9]{8,}\b/i', '[REDACTED_TOKEN]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9._-]+\.[A-Za-z0-9._-]+\b/', '[REDACTED_TOKEN]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/(?i)\b(password|passwd|pwd)\b\s*[:=]\s*[^\s,;]+/', '$1=[REDACTED_PASSWORD]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/(?i)\b(token|secret|api[_-]?key|authorization)\b\s*[:=]\s*[^\s,;]+/', '$1=[REDACTED_TOKEN]', $sanitized) ?? $sanitized;

        return $sanitized;
    }

    private function sanitizeUrl(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $parts = @parse_url($value);
        if ($parts === false || !is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $value;
        }

        $sanitized = $parts['scheme'] . '://';

        if (isset($parts['user']) || isset($parts['pass'])) {
            $sanitized .= '[REDACTED]@';
        }

        $sanitized .= $parts['host'];

        if (isset($parts['port'])) {
            $sanitized .= ':' . $parts['port'];
        }

        $sanitized .= $parts['path'] ?? '';

        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
            foreach ($query as $key => $item) {
                if ($this->isSensitiveKey(mb_strtolower((string) $key))) {
                    $query[$key] = '[REDACTED]';
                }
            }

            $queryString = http_build_query($query);
            if ($queryString !== '') {
                $sanitized .= '?' . $queryString;
            }
        }

        if (isset($parts['fragment'])) {
            $sanitized .= '#' . $parts['fragment'];
        }

        return $sanitized;
    }
}
