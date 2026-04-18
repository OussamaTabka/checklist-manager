<?php

namespace App\Services\TestCaseGeneration;

use App\Models\UserStory;

/**
 * Fallback Test Case Generator
 * 
 * Simple rule-based generator when other services are unavailable
 * Generates basic test scenarios from user story structure
 */
class FallbackGenerator implements TestCaseGeneratorInterface
{
    /**
     * Generate basic test cases from user story
     *
     * @param UserStory $userStory
     * @return array
     */
    public function generateTestCases(UserStory $userStory): array
    {
        $testCases = [];

        // Parse acceptance criteria into test cases
        if ($userStory->acceptance_criteria) {
            $testCases = $this->generateFromCriteria($userStory->acceptance_criteria);
        }

        // If not enough test cases, add standard ones
        if (count($testCases) < 3) {
            $testCases = array_merge($testCases, $this->generateStandardTestCases($userStory));
        }

        return array_slice($testCases, 0, 8); // Limit to 8 test cases
    }

    /**
     * Check if available (always available as fallback)
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Get service name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Fallback (Rule-based)';
    }

    /**
     * Generate test cases from acceptance criteria
     *
     * @param string $criteria
     * @return array
     */
    private function generateFromCriteria(string $criteria): array
    {
        $testCases = [];
        $lines = array_filter(array_map('trim', explode("\n", $criteria)));

        foreach ($lines as $index => $criterion) {
            // Remove numbering like "1.", "2.", etc.
            $criterion = preg_replace('/^\d+\.\s*/', '', $criterion);

            if (!empty($criterion)) {
                $testCases[] = [
                    'name' => "Verify: " . substr($criterion, 0, 50),
                    'description' => $criterion,
                    'expected_result' => 'Criterion is satisfied',
                    'severity' => $this->determineSeverity($index),
                ];
            }
        }

        return $testCases;
    }

    /**
     * Generate standard test cases for any user story
     *
     * @param UserStory $userStory
     * @return array
     */
    private function generateStandardTestCases(UserStory $userStory): array
    {
        return [
            [
                'name' => "Happy Path: {$userStory->title}",
                'description' => 'Test the main/happy path scenario',
                'expected_result' => 'Feature works as expected',
                'severity' => 'high',
            ],
            [
                'name' => 'Input Validation',
                'description' => 'Test with invalid/missing inputs',
                'expected_result' => 'System validates and provides feedback',
                'severity' => 'high',
            ],
            [
                'name' => 'Error Handling',
                'description' => 'Test error scenarios and recovery',
                'expected_result' => 'Appropriate error messages and state recovery',
                'severity' => 'medium',
            ],
            [
                'name' => 'Boundary Conditions',
                'description' => 'Test edge cases and limits',
                'expected_result' => 'System handles boundaries correctly',
                'severity' => 'medium',
            ],
            [
                'name' => 'State Management',
                'description' => 'Test state transitions and persistence',
                'expected_result' => 'State is correctly maintained',
                'severity' => 'medium',
            ],
        ];
    }

    /**
     * Determine severity based on position (first criteria are usually most important)
     *
     * @param int $index
     * @return string
     */
    private function determineSeverity(int $index): string
    {
        if ($index === 0) {
            return 'critical';
        } elseif ($index <= 2) {
            return 'high';
        } elseif ($index <= 5) {
            return 'medium';
        }
        return 'low';
    }
}
