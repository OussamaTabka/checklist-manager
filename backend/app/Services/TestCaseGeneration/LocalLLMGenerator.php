<?php

namespace App\Services\TestCaseGeneration;

use App\Models\UserStory;
use App\Services\StoryContextExtractor;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

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
    private float $temperature;

    public function __construct(
        private ?StoryContextExtractor $storyContextExtractor = null
    ) {
        $this->baseUrl = config('services.test_generation.llm_url', 'http://localhost:11434');
        $this->model = config('services.test_generation.llm_model', 'mistral');
        $this->timeout = max(5, min(45, (int) config('services.test_generation.llm_timeout', 30)));
        $this->temperature = max(0, min(1, (float) config('services.test_generation.llm_temperature', 0.2)));
        $this->storyContextExtractor ??= new StoryContextExtractor();
    }

    public function generateTestCases(UserStory $userStory, array $context = []): array
    {
        if (!$this->isAvailable()) {
            throw new Exception("Local LLM service not available. Ensure {$this->model} is running on {$this->baseUrl}");
        }

        try {
            $storyContext = $context['story_context'] ?? $this->storyContextExtractor->extract($userStory);
            $prompt = $this->buildPrompt($userStory, $storyContext, $context);

            $response = Http::connectTimeout(5)->timeout($this->timeout)->post(
                "{$this->baseUrl}/api/generate",
                [
                    'model' => $this->model,
                    'prompt' => $prompt,
                    'system' => $this->systemPrompt(),
                    'stream' => false,
                    'format' => $this->responseSchema(),
                    'options' => [
                        'temperature' => $this->temperature,
                    ],
                ]
            );

            if (!$response->successful()) {
                throw new Exception("LLM error: {$response->status()}");
            }

            $generated = (string) $response->json('response', '');
            $parsed = $this->parseStructuredResponse($generated);

            return $parsed !== [] ? $parsed : $this->generateFallbackTestCases($userStory, $context);
        } catch (Exception $e) {
            throw new Exception("Failed to generate test cases with Local LLM: {$e->getMessage()}");
        }
    }

    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/api/tags");

            if (!$response->successful()) {
                return false;
            }

            $models = collect($response->json('models', []))
                ->pluck('name')
                ->filter()
                ->values();

            if ($models->isEmpty()) {
                return true;
            }

            return $models->contains(fn (string $name) => str_starts_with($name, $this->model));
        } catch (Exception) {
            return false;
        }
    }

    public function getName(): string
    {
        return "Local LLM ({$this->model})";
    }

    private function systemPrompt(): string
    {
        return 'You are a senior QA test designer. Produce precise, domain-specific, non-generic test cases in valid JSON only.';
    }

    private function buildPrompt(UserStory $userStory, array $storyContext, array $context): string
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
2. Avoid generic cases like "verify errors" unless tied to a concrete condition from the story.
3. Prefer domain wording from the story.
4. Cover business flow, negative cases, concurrency, data integrity, notifications, and integrations when relevant.
5. Each test case must be specific enough that a tester can execute it without rewriting it.
6. Return valid JSON matching the provided schema.
7. Keep between 4 and 8 test cases.
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
                            'covers' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                        'required' => ['name', 'description', 'expected_result', 'severity'],
                    ],
                ],
            ],
            'required' => ['test_cases'],
        ];
    }

    private function parseStructuredResponse(string $response): array
    {
        $decoded = json_decode(trim($response), true);

        if (is_array($decoded) && isset($decoded['test_cases']) && is_array($decoded['test_cases'])) {
            return $this->normalizeCases($decoded['test_cases']);
        }

        return $this->parseLegacyResponse($response);
    }

    private function parseLegacyResponse(string $response): array
    {
        $testCases = [];

        preg_match_all('/^TEST CASE:\s*.*?(?=^TEST CASE:|\z)/ims', $response, $matches);
        $blocks = $matches[0] ?? [];

        foreach ($blocks as $block) {
            $testCase = $this->parseTestCaseBlock($block);
            if (!empty($testCase['name'])) {
                $testCases[] = $testCase;
            }
        }

        return $this->normalizeCases($testCases);
    }

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
            if ($line === '') {
                continue;
            }

            if (preg_match('/^([A-Z][A-Z_ ]+):\s*(.*)/', $line, $matches)) {
                $key = strtolower(str_replace(' ', '_', $matches[1]));
                $value = trim($matches[2]);

                switch ($key) {
                    case 'test_case':
                        $testCase['name'] = $value;
                        break;
                    case 'severity':
                        $testCase['severity'] = strtolower($value);
                        break;
                    default:
                        $testCase[$key] = $value;
                }

                $currentSection = $key;
                continue;
            }

            if ($currentSection !== '') {
                $testCase[$currentSection] = trim(($testCase[$currentSection] ?? '') . ' ' . $line);
            }
        }

        return $testCase;
    }

    private function normalizeCases(array $cases): array
    {
        return Collection::make($cases)
            ->map(function (array $testCase) {
                return [
                    'name' => trim((string) ($testCase['name'] ?? '')),
                    'description' => trim((string) ($testCase['description'] ?? '')),
                    'expected_result' => trim((string) ($testCase['expected_result'] ?? '')),
                    'severity' => $this->normalizeSeverity((string) ($testCase['severity'] ?? 'medium')),
                    'category' => trim((string) ($testCase['category'] ?? '')),
                    'covers' => array_values(array_filter(array_map('strval', $testCase['covers'] ?? []))),
                ];
            })
            ->filter(fn (array $testCase) => $testCase['name'] !== '' && $testCase['description'] !== '')
            ->unique(fn (array $testCase) => strtolower($testCase['name']))
            ->values()
            ->all();
    }

    private function normalizeSeverity(string $severity): string
    {
        $severity = strtolower(trim($severity));

        return in_array($severity, ['low', 'medium', 'high', 'critical'], true) ? $severity : 'medium';
    }

    private function generateFallbackTestCases(UserStory $userStory, array $context): array
    {
        $focus = array_values(array_filter(array_map('strval', $context['generation_focus'] ?? [])));
        $baseTitle = trim((string) $userStory->title);

        if ($focus === []) {
            $focus = ['critical business flow', 'negative path', 'data validation'];
        }

        return collect($focus)
            ->take(4)
            ->map(fn (string $item, int $index) => [
                'name' => sprintf('Gap %02d - %s', $index + 1, ucfirst($item)),
                'description' => "Target the missing coverage area: {$item} for {$baseTitle}.",
                'expected_result' => 'The behavior remains consistent, explicit, and testable for this coverage gap.',
                'severity' => $index === 0 ? 'high' : 'medium',
                'category' => 'gap-fill',
                'covers' => [$item],
            ])
            ->all();
    }

    private function bulletList(array $items): string
    {
        $items = array_values(array_filter(array_map(fn ($item) => trim((string) $item), $items)));

        if ($items === []) {
            return '- None';
        }

        return implode("\n", array_map(fn (string $item) => "- {$item}", $items));
    }
}
