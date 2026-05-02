<?php

namespace App\Services;

use App\Models\UserStory;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Services\TestCaseGeneration\TestCaseGeneratorInterface;
use App\Services\TestCaseGeneration\TestCaseGeneratorFactory;
use Illuminate\Support\Facades\Auth;
use Exception;

/**
 * Main Test Case Generation Service
 * 
 * Coordinates test case generation using multiple free/open-source backends:
 * - Local LLM (Ollama, LM Studio) - Recommended
 * - EvoMaster - For API testing
 * - Fallback - Rule-based generator
 */
class TestCaseGenerationService
{
    /**
     * Generate checklist from user story using available LLM/AI service
     *
     * @param UserStory $userStory
     * @return Checklist
     * @throws Exception
     */
    public function generateChecklistFromUserStory(UserStory $userStory): Checklist
    {
        $generation = $this->generateTestCasesForUserStory($userStory);
        $generator = $generation['generator'];
        $testCases = $generation['test_cases'];

        if (empty($testCases)) {
            throw new Exception('No test cases generated');
        }

        // Create a new checklist with the generated items
        $checklist = Checklist::create([
            'name' => "Generated ({$generator->getName()}): {$userStory->title}",
            'description' => $this->buildChecklistDescription($userStory, $generator->getName()),
            'category' => 'ai-generated',
            'is_active' => true,
            'created_by' => Auth::id(),
            'template_scope' => 'global',
            'lifecycle_status' => 'draft',
            'generated_from' => 'ai',
            'source_user_story_id' => $userStory->id,
        ]);

        // Add test cases as checklist items
        foreach ($testCases as $index => $testCase) {
            ChecklistItem::create([
                'checklist_id' => $checklist->id,
                'title' => $testCase['name'] ?? 'Test Case ' . ($index + 1),
                'description' => $this->buildItemDescription($testCase),
                'priority' => $this->mapSeverityToPriority($testCase['severity'] ?? 'medium'),
                'criticality' => $this->mapSeveritytoCriticality($testCase['severity'] ?? 'medium'),
                'order' => $index + 1,
            ]);
        }

        return $checklist;
    }

    /**
     * Generate raw test cases without persisting a checklist.
     *
     * @param UserStory $userStory
     * @return array{generator: TestCaseGeneratorInterface, generator_name: string, test_cases: array}
     * @throws Exception
     */
    public function generateTestCasesForUserStory(UserStory $userStory, array $context = []): array
    {
        $errors = [];

        foreach (TestCaseGeneratorFactory::orderedAvailableGenerators() as $generator) {
            try {
                $testCases = $generator->generateTestCases($userStory, $context);

                if (!empty($testCases)) {
                    return [
                        'generator' => $generator,
                        'generator_name' => $generator->getName(),
                        'test_cases' => $testCases,
                        'fallback_errors' => $errors,
                    ];
                }

                $errors[] = "{$generator->getName()}: no test cases generated";
            } catch (Exception $e) {
                $errors[] = "{$generator->getName()}: {$e->getMessage()}";
            }
        }

        throw new Exception('No test cases generated. ' . implode(' | ', $errors));
    }

    /**
     * Get available generators and their status
     *
     * @return array
     */
    public function getAvailableGenerators(): array
    {
        return TestCaseGeneratorFactory::getAvailable();
    }

    /**
     * Map severity level to criticality
     *
     * @param string $severity
     * @return string
     */
    private function mapSeveritytoCriticality(string $severity): string
    {
        $mapping = [
            'critical' => 'Critical',
            'high' => 'Critical',
            'medium' => 'Major',
            'low' => 'Minor',
        ];

        return $mapping[strtolower($severity)] ?? 'Major';
    }

    private function mapSeverityToPriority(string $severity): string
    {
        $mapping = [
            'critical' => 'High',
            'high' => 'High',
            'medium' => 'Medium',
            'low' => 'Low',
        ];

        return $mapping[strtolower($severity)] ?? 'Medium';
    }

    private function buildItemDescription(array $testCase): string
    {
        $description = trim((string) ($testCase['description'] ?? ''));
        $expected = trim((string) ($testCase['expected_result'] ?? ''));

        if ($expected === '') {
            return $description;
        }

        return trim($description . "\n\nExpected result: " . $expected);
    }

    /**
     * Build checklist description
     *
     * @param UserStory $userStory
     * @param string $generatorName
     * @return string
     */
    private function buildChecklistDescription(UserStory $userStory, string $generatorName): string
    {
        return <<<DESC
Generated from User Story: {$userStory->title}
Generator: {$generatorName}
Generated At: {$this->getCurrentTimestamp()}

Description:
{$userStory->description}

Acceptance Criteria:
{$userStory->acceptance_criteria}
DESC;
    }

    /**
     * Get current timestamp
     *
     * @return string
     */
    private function getCurrentTimestamp(): string
    {
        return now()->format('Y-m-d H:i:s');
    }
}
