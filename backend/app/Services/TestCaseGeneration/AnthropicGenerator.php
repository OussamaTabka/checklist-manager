<?php

namespace App\Services\TestCaseGeneration;

use App\Models\UserStory;
use Exception;
use Illuminate\Support\Facades\Http;

/**
 * Anthropic Test Case Generator
 *
 * Uses Anthropic Messages API to generate checklist test cases.
 */
class AnthropicGenerator implements TestCaseGeneratorInterface
{
    private string $baseUrl;
    private string $model;
    private ?string $apiKey;
    private string $apiVersion;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.test_generation.anthropic_url', 'https://api.anthropic.com/v1'), '/');
        $this->model = config('services.test_generation.anthropic_model', 'claude-3-5-sonnet-latest');
        $this->apiKey = config('services.test_generation.anthropic_api_key');
        $this->apiVersion = config('services.test_generation.anthropic_version', '2023-06-01');
        $this->timeout = (int) config('services.test_generation.timeout', 120);
    }

    /**
     * @throws Exception
     */
    public function generateTestCases(UserStory $userStory): array
    {
        if (!$this->isAvailable()) {
            throw new Exception('Anthropic API key is missing. Set ANTHROPIC_API_KEY in your environment.');
        }

        $response = Http::timeout($this->timeout)
            ->withHeaders([
                'x-api-key' => (string) $this->apiKey,
                'anthropic-version' => $this->apiVersion,
                'content-type' => 'application/json',
            ])
            ->post("{$this->baseUrl}/messages", [
                'model' => $this->model,
                'max_tokens' => (int) config('services.test_generation.max_tokens', 1200),
                'temperature' => 0.2,
                'system' => 'You are a senior QA engineer. Return only valid JSON with actionable test cases.',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $this->buildPrompt($userStory),
                    ],
                ],
            ]);

        if (!$response->successful()) {
            throw new Exception('Anthropic request failed: ' . $response->status() . ' ' . $response->body());
        }

        $content = $this->extractTextContent($response->json('content', []));
        $testCases = $this->parseJsonTestCases($content);

        if (empty($testCases)) {
            throw new Exception('Anthropic response did not contain valid test cases.');
        }

        return $testCases;
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }

    public function getName(): string
    {
        return "Anthropic ({$this->model})";
    }

    private function buildPrompt(UserStory $userStory): string
    {
        return <<<PROMPT
Generate 5 to 8 software test cases for the user story below.

Return only JSON in this format:
{
  "test_cases": [
    {
      "name": "...",
      "description": "...",
      "expected_result": "...",
      "severity": "low|medium|high|critical"
    }
  ]
}

User story title:
{$userStory->title}

User story description:
{$userStory->description}

Acceptance criteria:
{$userStory->acceptance_criteria}

Ensure coverage of happy path, validation, edge cases, and error handling.
PROMPT;
    }

    private function extractTextContent(array $contentBlocks): string
    {
        $text = [];

        foreach ($contentBlocks as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $text[] = (string) ($block['text'] ?? '');
            }
        }

        return trim(implode("\n", $text));
    }

    private function parseJsonTestCases(string $content): array
    {
        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            $json = $this->extractJsonObject($content);
            $decoded = is_string($json) ? json_decode($json, true) : null;
        }

        if (!is_array($decoded)) {
            return [];
        }

        $cases = $decoded['test_cases'] ?? $decoded;

        if (!is_array($cases)) {
            return [];
        }

        $normalized = [];

        foreach ($cases as $testCase) {
            if (!is_array($testCase)) {
                continue;
            }

            $name = trim((string) ($testCase['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $severity = strtolower((string) ($testCase['severity'] ?? 'medium'));
            if (!in_array($severity, ['low', 'medium', 'high', 'critical'], true)) {
                $severity = 'medium';
            }

            $normalized[] = [
                'name' => $name,
                'description' => trim((string) ($testCase['description'] ?? '')),
                'expected_result' => trim((string) ($testCase['expected_result'] ?? '')),
                'severity' => $severity,
            ];
        }

        return $normalized;
    }

    private function extractJsonObject(string $text): ?string
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        return substr($text, $start, $end - $start + 1);
    }
}
