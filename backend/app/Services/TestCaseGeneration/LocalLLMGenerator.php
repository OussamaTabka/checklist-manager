<?php

namespace App\Services\TestCaseGeneration;

use App\Models\UserStory;
use Illuminate\Support\Facades\Http;
use Exception;

/**
 * Local LLM Test Case Generator
 * 
 * Uses local LLM services like:
 * - Ollama (https://ollama.ai)
 * - LM Studio (https://lmstudio.ai)
 * - Llama2, Mistral, Neural Chat, etc.
 * 
 * Free, open-source, privacy-friendly, runs locally
 */
class LocalLLMGenerator implements TestCaseGeneratorInterface
{
    private string $baseUrl;
    private string $model;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = config('services.test_generation.llm_url', 'http://localhost:11434');
        $this->model = config('services.test_generation.llm_model', 'mistral');
        $this->timeout = min(5, max(3, (int) config('services.test_generation.llm_timeout', 5)));
    }

    /**
     * Generate test cases using local LLM
     *
     * @param UserStory $userStory
     * @return array
     * @throws Exception
     */
    public function generateTestCases(UserStory $userStory): array
    {
        if (!$this->isAvailable()) {
            throw new Exception("Local LLM service not available. Ensure {$this->model} is running on {$this->baseUrl}");
        }

        try {
            $prompt = $this->buildPrompt($userStory);

            // Call local LLM API (Ollama format)
            $response = Http::timeout($this->timeout)->post(
                "{$this->baseUrl}/api/generate",
                [
                    'model' => $this->model,
                    'prompt' => $prompt,
                    'stream' => false,
                    'temperature' => 0.7,
                ]
            );

            if (!$response->successful()) {
                throw new Exception("LLM error: {$response->status()}");
            }

            $generated = $response->json('response', '');
            return $this->parseTestCases($generated);

        } catch (Exception $e) {
            throw new Exception("Failed to generate test cases with Local LLM: {$e->getMessage()}");
        }
    }

    /**
     * Check if local LLM is available
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/api/tags");
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
        return "Local LLM ({$this->model})";
    }

    /**
     * Build an optimized prompt for test case generation
     *
     * @param UserStory $userStory
     * @return string
     */
    private function buildPrompt(UserStory $userStory): string
    {
        return <<<PROMPT
You are an expert QA engineer. Generate comprehensive test cases for this user story.

USER STORY:
Title: {$userStory->title}

Description:
{$userStory->description}

Acceptance Criteria:
{$userStory->acceptance_criteria}

INSTRUCTIONS:
Generate 5-8 test cases covering:
1. Happy path (main functionality)
2. Edge cases and boundary conditions
3. Error scenarios
4. Data validation
5. Security considerations

For EACH test case, use this exact format:

TEST CASE: [name]
SEVERITY: [low/medium/high/critical]
DESCRIPTION: [what is being tested]
STEPS:
1. [Step 1]
2. [Step 2]
3. [Continue as needed]
EXPECTED_RESULT: [What should happen]
---

Be concise but thorough. Focus on testability and clarity.
PROMPT;
    }

    /**
     * Parse LLM response into standard format
     *
     * @param string $response
     * @return array
     */
    private function parseTestCases(string $response): array
    {
        $testCases = [];
        
        // Split by test case markers
        $blocks = preg_split('/^TEST CASE:/m', $response, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($blocks as $block) {
            $testCase = $this->parseTestCaseBlock($block);
            if (!empty($testCase['name'])) {
                $testCases[] = $testCase;
            }
        }

        return !empty($testCases) ? $testCases : $this->generateFallbackTestCases();
    }

    /**
     * Parse individual test case block
     *
     * @param string $block
     * @return array
     */
    private function parseTestCaseBlock(string $block): array
    {
        $lines = explode("\n", trim($block));
        $testCase = [
            'name' => '',
            'description' => '',
            'expected_result' => '',
            'severity' => 'medium',
        ];

        $currentSection = '';

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            if (preg_match('/^([A-Z_]+):\s*(.*)/', $line, $matches)) {
                $key = strtolower($matches[1]);
                $value = $matches[2];

                switch ($key) {
                    case 'severity':
                        $testCase['severity'] = strtolower($value);
                        break;
                    default:
                        if ($key === 'test case') {
                            $testCase['name'] = $value;
                        } else {
                            $testCase[$key] = $value;
                        }
                }
                $currentSection = $key;
            } else {
                if ($currentSection) {
                    $testCase[$currentSection] = ($testCase[$currentSection] ?? '') . ' ' . $line;
                }
            }
        }

        return $testCase;
    }

    /**
     * Generate fallback test cases if parsing fails
     *
     * @return array
     */
    private function generateFallbackTestCases(): array
    {
        return [
            [
                'name' => 'Happy Path Test',
                'description' => 'Test normal/expected user flow',
                'expected_result' => 'Feature works as expected',
                'severity' => 'high',
            ],
            [
                'name' => 'Edge Case Test',
                'description' => 'Test boundary conditions and limits',
                'expected_result' => 'System handles edge cases gracefully',
                'severity' => 'medium',
            ],
            [
                'name' => 'Error Handling Test',
                'description' => 'Test error scenarios and error messages',
                'expected_result' => 'Appropriate error messages displayed',
                'severity' => 'high',
            ],
        ];
    }
}
