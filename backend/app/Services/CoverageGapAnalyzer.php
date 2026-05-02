<?php

namespace App\Services;

use Illuminate\Support\Collection;

class CoverageGapAnalyzer
{
    public function analyze(array $storyContext, array $reusedItems, array $generatedItems): array
    {
        $allItems = [...$reusedItems, ...$generatedItems];
        $itemTexts = Collection::make($allItems)
            ->map(fn (array $item) => $this->normalizeText(trim(($item['title'] ?? '') . ' ' . ($item['description'] ?? ''))))
            ->filter()
            ->values()
            ->all();

        [$coveredCriteria, $missingCriteria] = $this->analyzeTextCoverage($storyContext['acceptance_criteria'] ?? [], $itemTexts, 'criterion');
        [$coveredRules, $missingRules] = $this->analyzeTextCoverage($storyContext['business_rules'] ?? [], $itemTexts, 'business rule');
        [$coveredScenarios, $missingScenarios] = $this->analyzeTextCoverage($storyContext['scenarios'] ?? [], $itemTexts, 'scenario');

        $coveredDimensions = [];
        $missingDimensions = [];

        foreach ($storyContext['dimensions'] ?? [] as $dimension) {
            if ($this->dimensionCovered($dimension, $itemTexts)) {
                $coveredDimensions[] = $dimension;
            } else {
                $missingDimensions[] = $dimension;
            }
        }

        $coverageRatio = $this->coverageRatio(
            count($coveredCriteria) + count($coveredRules) + count($coveredScenarios) + count($coveredDimensions),
            count($missingCriteria) + count($missingRules) + count($missingScenarios) + count($missingDimensions)
        );

        $gapSummary = $this->buildGapSummary($missingCriteria, $missingRules, $missingScenarios, $missingDimensions);

        return [
            'covered_criteria' => $coveredCriteria,
            'missing_criteria' => $missingCriteria,
            'covered_business_rules' => $coveredRules,
            'missing_business_rules' => $missingRules,
            'covered_scenarios' => $coveredScenarios,
            'missing_scenarios' => $missingScenarios,
            'covered_dimensions' => $coveredDimensions,
            'missing_dimensions' => $missingDimensions,
            'coverage_ratio' => $coverageRatio,
            'generation_focus' => $this->buildGenerationFocus($missingCriteria, $missingRules, $missingScenarios, $missingDimensions),
            'gap_summary' => $gapSummary,
        ];
    }

    private function analyzeTextCoverage(array $inputs, array $itemTexts, string $label): array
    {
        $covered = [];
        $missing = [];

        foreach ($inputs as $input) {
            $text = trim((string) $input);
            $tokens = $this->tokenize($text);
            $isCovered = $this->matchesAnyItem($tokens, $itemTexts);

            if ($isCovered) {
                $covered[] = $text;
            } else {
                $missing[] = $text;
            }
        }

        return [$covered, $missing];
    }

    private function matchesAnyItem(array $criterionTokens, array $itemTexts): bool
    {
        if ($criterionTokens === []) {
            return false;
        }

        foreach ($itemTexts as $text) {
            $matches = 0;

            foreach ($criterionTokens as $token) {
                if (str_contains($text, $token)) {
                    $matches++;
                }
            }

            if ($matches >= max(1, (int) ceil(count($criterionTokens) / 3))) {
                return true;
            }
        }

        return false;
    }

    private function dimensionCovered(string $dimension, array $itemTexts): bool
    {
        $keywords = match ($dimension) {
            'happy_path' => ['success', 'successful', 'confirm', 'complete', 'happy path', 'reserve', 'book'],
            'validation' => ['validation', 'invalid', 'required', 'format'],
            'business_rules' => ['must', 'only', 'unique', 'future', 'allowed'],
            'error_handling' => ['error', 'failure', 'failed', 'declined', 'refused'],
            'notifications' => ['email', 'sms', 'notification', 'confirmation'],
            'data_integrity' => ['stock', 'inventory', 'duplicate', 'persist', 'coherent'],
            'security' => ['security', 'access', 'authorization', 'card', 'token'],
            'usability' => ['clear', 'visible', 'accessible', 'responsive', 'message'],
            'concurrency' => ['double', 'already', 'simultaneous', 'once', 'between time'],
            'integrations' => ['provider', 'gateway', 'service', 'api', 'external'],
            default => [],
        };

        foreach ($itemTexts as $text) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $this->normalizeText($keyword))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function buildGenerationFocus(array $missingCriteria, array $missingRules, array $missingScenarios, array $missingDimensions): array
    {
        $focus = [];

        if ($missingCriteria !== []) {
            $focus[] = 'acceptance criteria not yet covered';
        }

        if ($missingRules !== []) {
            $focus[] = 'business rules not enforced explicitly';
        }

        if ($missingScenarios !== []) {
            $focus[] = 'additional scenarios missing from checklist';
        }

        foreach ($missingDimensions as $dimension) {
            $focus[] = str_replace('_', ' ', $dimension) . ' coverage';
        }

        return array_values(array_unique($focus));
    }

    private function buildGapSummary(array $missingCriteria, array $missingRules, array $missingScenarios, array $missingDimensions): array
    {
        $summary = [];

        foreach (array_slice($missingCriteria, 0, 3) as $criterion) {
            $summary[] = "Missing criterion: {$criterion}";
        }

        foreach (array_slice($missingRules, 0, 3) as $rule) {
            $summary[] = "Missing business rule: {$rule}";
        }

        foreach (array_slice($missingScenarios, 0, 3) as $scenario) {
            $summary[] = "Missing scenario: {$scenario}";
        }

        foreach (array_slice($missingDimensions, 0, 4) as $dimension) {
            $summary[] = 'Missing dimension: ' . str_replace('_', ' ', $dimension);
        }

        return $summary;
    }

    private function tokenize(string $value): array
    {
        return Collection::make(preg_split('/[^a-z0-9]+/', $this->normalizeText($value)) ?: [])
            ->map(fn (string $part) => trim($part))
            ->filter(fn (string $part) => strlen($part) >= 4)
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeText(string $value): string
    {
        $normalized = strtolower(trim($value));
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);

        return $transliterated !== false ? $transliterated : $normalized;
    }

    private function coverageRatio(int $covered, int $missing): int
    {
        $total = $covered + $missing;

        if ($total === 0) {
            return 0;
        }

        return (int) round(($covered / $total) * 100);
    }
}
