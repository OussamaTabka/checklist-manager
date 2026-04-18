<?php

namespace App\Services;

use App\Models\UserStory;
use App\Models\Checklist;
use App\Models\ChecklistItem;
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
        // Get the best available generator
        $generator = TestCaseGeneratorFactory::make();

        // Generate test cases
        $testCases = $generator->generateTestCases($userStory);

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
        ]);

        // Add test cases as checklist items
        foreach ($testCases as $index => $testCase) {
            ChecklistItem::create([
                'checklist_id' => $checklist->id,
                'name' => $testCase['name'] ?? 'Test Case ' . ($index + 1),
                'description' => $testCase['description'] ?? '',
                'expected_result' => $testCase['expected_result'] ?? '',
                'criticality' => $this->mapSeveritytoCriticality($testCase['severity'] ?? 'medium'),
                'order' => $index + 1,
            ]);
        }

        return $checklist;
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
            'high' => 'High',
            'medium' => 'Medium',
            'low' => 'Low',
        ];

        return $mapping[strtolower($severity)] ?? 'Medium';
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
