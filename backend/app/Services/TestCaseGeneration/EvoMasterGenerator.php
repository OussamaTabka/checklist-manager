<?php

namespace App\Services\TestCaseGeneration;

use App\Models\UserStory;
use Illuminate\Support\Facades\Http;
use Exception;

/**
 * EvoMaster Test Case Generator
 * 
 * Uses EvoMaster for search-based test generation
 * Perfect for API/REST endpoints
 * 
 * @see https://github.com/WebCheckingCode/EvoMaster
 */
class EvoMasterGenerator implements TestCaseGeneratorInterface
{
    private string $baseUrl;
    private string $port;

    public function __construct()
    {
        $this->baseUrl = config('services.test_generation.evomaster_url', 'http://localhost');
        $this->port = config('services.test_generation.evomaster_port', '40898');
    }

    /**
     * Generate test cases using EvoMaster
     *
     * @param UserStory $userStory
     * @return array
     * @throws Exception
     */
    public function generateTestCases(UserStory $userStory, array $context = []): array
    {
        if (!$this->isAvailable()) {
            throw new Exception('EvoMaster service is not available. Ensure it is running on ' . $this->baseUrl . ':' . $this->port);
        }

        try {
            // Prepare prompt for EvoMaster
            $prompt = $this->buildPrompt($userStory, $context);

            // Call EvoMaster API
            $response = Http::timeout(30)->post(
                "{$this->baseUrl}:{$this->port}/api/test-generation",
                [
                    'user_story_title' => $userStory->title,
                    'description' => $userStory->description,
                    'acceptance_criteria' => $userStory->acceptance_criteria,
                    'generation_context' => $context,
                    'prompt' => $prompt,
                ]
            );

            if (!$response->successful()) {
                throw new Exception("EvoMaster error: {$response->status()} - {$response->body()}");
            }

            $data = $response->json();
            return $this->parseTestCases($data['test_cases'] ?? []);

        } catch (Exception $e) {
            throw new Exception("Failed to generate test cases with EvoMaster: {$e->getMessage()}");
        }
    }

    /**
     * Check if EvoMaster is available
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}:{$this->port}/health");
            return $response->successful();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get service name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'EvoMaster';
    }

    /**
     * Build a structured prompt for EvoMaster
     *
     * @param UserStory $userStory
     * @return string
     */
    private function buildPrompt(UserStory $userStory, array $context = []): string
    {
        $focus = implode(', ', $context['generation_focus'] ?? []);
        $focusSection = $focus !== '' ? "\nFocus missing coverage on: {$focus}" : '';

        return <<<PROMPT
Generate comprehensive test cases for the following user story:

Title: {$userStory->title}

Description:
{$userStory->description}

Acceptance Criteria:
{$userStory->acceptance_criteria}
{$focusSection}

For each test case, provide:
1. A clear test name
2. Step-by-step test steps
3. Expected result
4. Severity level (low, medium, high, critical)

Focus on:
- Happy path scenarios
- Edge cases
- Error handling
- Security considerations
- Performance bounds

Format each test case as a separate item with clear sections.
PROMPT;
    }

    /**
     * Parse EvoMaster response into standard format
     *
     * @param array $testCases
     * @return array
     */
    private function parseTestCases(array $testCases): array
    {
        return array_map(function ($testCase) {
            return [
                'name' => $testCase['name'] ?? 'Test Case',
                'description' => $testCase['description'] ?? $testCase['steps'] ?? '',
                'expected_result' => $testCase['expected_result'] ?? $testCase['expected'] ?? '',
                'severity' => strtolower($testCase['severity'] ?? 'medium'),
            ];
        }, $testCases);
    }
}
