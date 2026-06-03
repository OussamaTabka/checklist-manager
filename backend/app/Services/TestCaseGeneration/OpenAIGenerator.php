<?php

namespace App\Services\TestCaseGeneration;

use App\Models\UserStory;
use App\Services\ChecklistGenerationTextService;
use App\Services\StoryContextExtractor;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIGenerator implements TestCaseGeneratorInterface
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    private int $timeout;
    private float $temperature;

    public function __construct(
        private ?StoryContextExtractor $storyContextExtractor = null,
        private ?ChecklistGenerationTextService $generationText = null,
    ) {
        $this->apiKey = config('services.test_generation.openai_api_key', '');
        $this->model = config('services.test_generation.openai_model', 'gpt-5.3-codex');
        $configUrl = config('services.test_generation.openai_api_url', 'https://codex.sale/v1/chat/completions');
        $this->baseUrl = rtrim($configUrl, '/');
        if (!str_ends_with($this->baseUrl, '/chat/completions')) {
            $this->baseUrl .= '/chat/completions';
        }
        $this->timeout = max(10, min(120, (int) config('services.test_generation.openai_timeout', 60)));
        $this->temperature = max(0, min(1, (float) config('services.test_generation.openai_temperature', 0.1)));
        $this->storyContextExtractor ??= new StoryContextExtractor();
        $this->generationText ??= app(ChecklistGenerationTextService::class);
    }

    public function generateTestCases(UserStory $userStory, array $context = []): array
    {
        if (!$this->isAvailable()) {
            throw new Exception('OpenAI Codex API key not configured');
        }

        $storyContext = $context['story_context'] ?? $this->storyContextExtractor->extract($userStory);
        $language = $this->generationText->normalizeLanguage($context['language'] ?? 'fr');
        $prompt = $this->buildPrompt($userStory, $storyContext, $context, $language);

        Log::info('OpenAI payload', [
            'user_prompt' => $prompt,
            'url' => $this->baseUrl,
            'model' => $this->model,
            'temperature' => $this->temperature,
        ]);

        $response = Http::withToken($this->apiKey)
            ->timeout($this->timeout)
            ->post($this->baseUrl, [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_tokens' => (int) config('services.test_generation.openai_max_tokens', 4096),
                'temperature' => $this->temperature,
            ]);

        if (!$response->successful()) {
            $error = "OpenAI API error {$response->status()}: {$response->body()}";
            Log::error('OpenAI generation failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new Exception($error);
        }

        $content = $response->json('choices.0.message.content', '');

        Log::info('OpenAI response received', ['content_length' => strlen($content)]);

        if (empty($content)) {
            throw new Exception('OpenAI API returned an empty response');
        }

        $parsed = $this->parseResponse($content);

        if (empty($parsed)) {
            throw new Exception('OpenAI API returned no valid test cases');
        }

        return $parsed;
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }

    public function getName(): string
    {
        return "OpenAI Codex ({$this->model})";
    }

    private function systemPrompt(): string
    {
        return 'You are a senior QA test designer. Produce precise, domain-specific, non-generic test cases. Return only valid JSON matching the schema provided.';
    }

    private function buildPrompt(UserStory $userStory, array $storyContext, array $context, string $language): string
    {
        $acceptanceCriteria = $this->bulletList($storyContext['acceptance_criteria'] ?? []);
        $businessRules = $this->bulletList($storyContext['business_rules'] ?? []);
        $scenarios = $this->bulletList($storyContext['scenarios'] ?? []);
        $reusedItems = $this->bulletList(
            array_map(
                fn (array $item) => trim(($item['title'] ?? '') . ' - ' . ($item['description'] ?? '')),
                array_slice($context['reusable_items'] ?? [], 0, 6)
            )
        );
        $generationFocus = $this->bulletList($context['generation_focus'] ?? []);
        $gapSummary = $this->bulletList($context['gap_summary'] ?? []);
        $targetLanguage = $language === 'en' ? 'English' : 'French';

        $schema = json_encode($this->responseSchema(), JSON_PRETTY_PRINT);

        return <<<PROMPT
Generate a compact, high-value QA checklist for this user story.

USER STORY TITLE:
{$userStory->title}

DESCRIPTION:
{$userStory->description}

ACTOR:
{$storyContext['actor']}

GOAL:
{$storyContext['goal']}

BENEFIT:
{$storyContext['benefit']}

ACCEPTANCE CRITERIA:
{$acceptanceCriteria}

BUSINESS RULES:
{$businessRules}

ADDITIONAL SCENARIOS:
{$scenarios}

ALREADY REUSED CHECKLIST ITEMS:
{$reusedItems}

MISSING COVERAGE TO PRIORITIZE:
{$generationFocus}

GAP SUMMARY:
{$gapSummary}

INSTRUCTIONS:
1. Generate only the highest-value cases that fill missing coverage or sharpen vague reused coverage.
2. Avoid generic cases unless tied to a concrete condition from the story.
3. Prefer domain wording from the story.
4. Cover business flow, negative cases, data integrity, and edge cases when relevant.
5. Each test case must be specific enough that a tester can execute it without rewriting it.
6. Write every field in {$targetLanguage}.
7. Do not prefix test case names with "Criterion", "Critere", "TC-", or numeric references.
8. Return between 4 and 8 test cases.

RESPONSE SCHEMA:
{$schema}
PROMPT;
    }

    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'test_cases' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'expected_result' => ['type' => 'string'],
                            'severity' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']],
                            'category' => ['type' => 'string'],
                            'covers' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => ['name', 'description', 'expected_result', 'severity'],
                    ],
                ],
            ],
            'required' => ['test_cases'],
        ];
    }

    private function parseResponse(string $content): array
    {
        $content = trim($content);

        // Strip markdown code fences if present
        if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/', $content, $matches)) {
            $content = $matches[1];
        }

        $decoded = json_decode($content, true);

        if (!is_array($decoded) || !isset($decoded['test_cases']) || !is_array($decoded['test_cases'])) {
            Log::error('OpenAI response schema mismatch', ['content' => substr($content, 0, 500)]);
            throw new Exception('OpenAI API response did not match expected JSON schema');
        }

        return $this->normalizeCases($decoded['test_cases']);
    }

    private function normalizeCases(array $cases): array
    {
        return Collection::make($cases)
            ->map(function (array $testCase) {
                $language = $this->generationText->normalizeLanguage($testCase['language'] ?? 'fr');

                return [
                    'name' => $this->generationText->localizeCaseName((string) ($testCase['name'] ?? ''), $language),
                    'description' => $this->generationText->localizeChecklistText((string) ($testCase['description'] ?? ''), $language),
                    'expected_result' => $this->generationText->localizeChecklistText((string) ($testCase['expected_result'] ?? ''), $language),
                    'severity' => $this->normalizeSeverity((string) ($testCase['severity'] ?? 'medium')),
                    'category' => $this->generationText->localizeChecklistText((string) ($testCase['category'] ?? ''), $language),
                    'covers' => array_values(array_filter(array_map(
                        fn ($cover) => $this->generationText->localizeChecklistText((string) $cover, $language),
                        $testCase['covers'] ?? []
                    ))),
                    'language' => $language,
                ];
            })
            ->filter(fn (array $tc) => $tc['name'] !== '' && $tc['description'] !== '')
            ->unique(fn (array $tc) => strtolower($tc['name']))
            ->values()
            ->all();
    }

    private function normalizeSeverity(string $severity): string
    {
        $severity = strtolower(trim($severity));

        return in_array($severity, ['low', 'medium', 'high', 'critical'], true) ? $severity : 'medium';
    }

    private function bulletList(array $items): string
    {
        $items = array_values(array_filter(array_map(fn ($item) => trim((string) $item), $items)));

        return $items !== [] ? implode("\n", array_map(fn (string $i) => "- {$i}", $items)) : '- None';
    }
}
