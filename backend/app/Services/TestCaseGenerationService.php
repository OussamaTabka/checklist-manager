<?php

namespace App\Services;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\UserStory;
use App\Services\TestCaseGeneration\TestCaseGeneratorFactory;
use App\Services\TestCaseGeneration\TestCaseGeneratorInterface;
use Exception;
use Illuminate\Support\Facades\Auth;

class TestCaseGenerationService
{
    public function __construct(
        private ?ChecklistGenerationTextService $generationText = null
    ) {
        $this->generationText ??= app(ChecklistGenerationTextService::class);
    }

    public function generateChecklistFromUserStory(UserStory $userStory): Checklist
    {
        $generation = $this->generateTestCasesForUserStory($userStory);
        $generator = $generation['generator'];
        $testCases = $generation['test_cases'];

        if (empty($testCases)) {
            throw new Exception('No test cases generated');
        }

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

        foreach ($testCases as $index => $testCase) {
            $language = $this->generationText->normalizeLanguage($testCase['language'] ?? 'fr');

            ChecklistItem::create([
                'checklist_id' => $checklist->id,
                'title' => $this->generationText->localizeCaseName((string) ($testCase['name'] ?? 'Test Case ' . ($index + 1)), $language),
                'description' => $this->buildItemDescription($testCase, $language),
                'priority' => $this->mapSeverityToPriority($testCase['severity'] ?? 'medium'),
                'criticality' => $this->mapSeveritytoCriticality($testCase['severity'] ?? 'medium'),
                'order' => $index + 1,
            ]);
        }

        return $checklist;
    }

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

    public function getAvailableGenerators(): array
    {
        return TestCaseGeneratorFactory::getAvailable();
    }

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

    private function buildItemDescription(array $testCase, string $language = 'fr'): string
    {
        $description = trim((string) ($testCase['description'] ?? ''));
        $expected = trim((string) ($testCase['expected_result'] ?? ''));

        if ($expected === '') {
            return $description;
        }

        return trim($description . "\n\n" . $this->generationText->text($language, 'expected_result_label') . ' ' . $expected);
    }

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

    private function getCurrentTimestamp(): string
    {
        return now()->format('Y-m-d H:i:s');
    }
}
