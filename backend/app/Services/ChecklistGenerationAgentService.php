<?php

namespace App\Services;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\UserStory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ChecklistGenerationAgentService
{
    private const GENERIC_TOKENS = [
        'user', 'client', 'customer', 'validation', 'valid', 'invalid', 'error', 'message',
        'notification', 'email', 'submit', 'confirm', 'required', 'clear', 'display',
        'verify', 'test', 'testing', 'should', 'with', 'after', 'before',
    ];

    private const DOMAIN_KEYWORDS = [
        'payment' => ['paiement', 'payment', 'carte', 'card', 'checkout', 'commande', 'order', 'cvv', 'facture', 'receipt'],
        'booking' => ['rendez', 'appointment', 'booking', 'creneau', 'slot', 'medecin', 'doctor', 'reservation', 'reserve', 'specialite'],
        'authentication' => ['login', 'auth', 'authentication', 'password', 'session', 'dashboard', 'credential'],
        'api' => ['api', 'endpoint', 'request', 'response', 'json', 'bearer', 'token'],
    ];

    public function __construct(
        private ChecklistRecommendationService $recommendations,
        private TestCaseGenerationService $testCaseGenerator,
        private StoryContextExtractor $storyContextExtractor,
        private CoverageGapAnalyzer $coverageGapAnalyzer,
        private RiskProfileAnalyzer $riskProfileAnalyzer,
        private ChecklistGenerationTextService $generationText,
    ) {
    }

    public function generateDraftForUserStory(UserStory $userStory, string $language = 'fr'): array
    {
        $language = $this->generationText->normalizeLanguage($language);
        $storyContext = $this->storyContextExtractor->extract($userStory);
        $riskProfile = $this->riskProfileAnalyzer->analyze($storyContext);
        $recommendations = $this->recommendations->suggestForUserStory($userStory, 3);
        $reusableItems = $this->collectReusableItems($storyContext, $recommendations, $language);
        $preGenerationCoverage = $this->coverageGapAnalyzer->analyze($storyContext, $reusableItems, []);
        $generationWarnings = [];
        $generation = null;

        try {
            $generation = $this->testCaseGenerator->generateTestCasesForUserStory(
                $userStory,
                $this->buildGenerationContext($storyContext, $reusableItems, $preGenerationCoverage, $recommendations, $riskProfile, $language)
            );
        } catch (\Exception $exception) {
            $generationWarnings[] = $exception->getMessage();
        }

        return DB::transaction(function () use ($userStory, $storyContext, $riskProfile, $recommendations, $reusableItems, $generation, $generationWarnings, $preGenerationCoverage, $language) {
            $generatedItems = $this->normalizeGeneratedCases($generation['test_cases'] ?? [], $language);
            $coverage = $this->coverageGapAnalyzer->analyze($storyContext, $reusableItems, $generatedItems);
            $mergedItems = $this->mergeItems($reusableItems, $generatedItems);

            if ($mergedItems === []) {
                throw new \RuntimeException($this->generationText->text($language, 'agent_error_empty'));
            }

            $checklist = Checklist::create([
                'name' => $this->buildChecklistName($userStory, count($reusableItems) > 0, $language),
                'description' => $this->buildChecklistDescription(
                    $userStory,
                    $generation['generator_name'] ?? $this->generationText->text($language, 'draft_base_reuse'),
                    $recommendations,
                    $language
                ),
                'project_id' => $userStory->project_id,
                'category' => 'agent-generated',
                'is_active' => true,
                'created_by' => Auth::id(),
                'template_scope' => 'project',
                'lifecycle_status' => 'draft',
                'generated_from' => 'ai',
                'source_user_story_id' => $userStory->id,
            ]);

            foreach ($mergedItems as $index => $item) {
                ChecklistItem::create([
                    'checklist_id' => $checklist->id,
                    'title' => $item['title'],
                    'description' => $item['description'],
                    'priority' => $item['priority'],
                    'criticality' => $item['criticality'],
                    'order' => $index + 1,
                    'source_type' => $item['source'] ?? null,
                    'source_checklist_id' => $item['source_checklist_id'],
                    'source_checklist_name' => $item['source_checklist_name'],
                ]);
            }

            return [
                'message' => $this->generationText->text($language, 'draft_message'),
                'decision' => $this->buildDecision($storyContext, $riskProfile, $recommendations, $reusableItems, $generatedItems, $coverage, $language),
                'reuse_summary' => [
                    'reused_items' => count($reusableItems),
                    'generated_items' => count($generatedItems),
                    'final_items' => count($mergedItems),
                    'source_checklists' => collect($reusableItems)
                        ->pluck('source_checklist_name')
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                ],
                'coverage' => $coverage,
                'checklist' => $checklist->load('items'),
                'pending_validation' => true,
                'suggestions' => $recommendations,
                'generation_provider' => $generation['generator_name'] ?? $this->generationText->text($language, 'generated_only'),
                'generation_warnings' => array_values(array_filter([
                    ...($generation['fallback_errors'] ?? []),
                    ...$generationWarnings,
                ])),
                'generation_debug' => [
                    'story_tokens' => $storyContext['tokens'] ?? [],
                    'story_domain' => $storyContext['primary_domain'] ?? 'general',
                    'story_intent' => $storyContext['intent'] ?? 'generic_flow',
                    'risk_profile' => $riskProfile,
                    'pre_generation_coverage' => $preGenerationCoverage,
                    'generation_focus' => $preGenerationCoverage['generation_focus'] ?? [],
                    'language' => $language,
                ],
                'available_generators' => $this->testCaseGenerator->getAvailableGenerators(),
            ];
        });
    }

    private function collectReusableItems(array $storyContext, array $recommendations, string $language): array
    {
        $storyTokens = $storyContext['tokens'] ?? [];
        $projectId = (int) ($storyContext['project_id'] ?? 0);

        $suggestionMap = collect($recommendations['suggestions'] ?? [])
            ->where('score', '>=', 30)
            ->keyBy('id');

        $sameProjectSuggestionIds = $suggestionMap
            ->filter(fn (array $suggestion) => (int) ($suggestion['project_id'] ?? 0) === $projectId)
            ->keys()
            ->take(3);

        $fallbackSuggestionIds = $suggestionMap
            ->keys()
            ->reject(fn ($id) => $sameProjectSuggestionIds->contains($id))
            ->take(2);

        $suggestionIds = $sameProjectSuggestionIds
            ->concat($fallbackSuggestionIds)
            ->values();

        if ($suggestionIds->isEmpty()) {
            return [];
        }

        return Checklist::query()
            ->with(['items' => fn ($query) => $query->orderBy('order')])
            ->whereIn('id', $suggestionIds)
            ->where('lifecycle_status', 'approved')
            ->get()
            ->sortByDesc(fn (Checklist $checklist) => (int) $checklist->project_id === $projectId)
            ->flatMap(function (Checklist $checklist) use ($storyTokens, $suggestionMap, $projectId, $language) {
                $suggestion = $suggestionMap->get($checklist->id, []);

                return $checklist->items
                    ->map(function (ChecklistItem $item) use ($checklist, $storyTokens, $suggestion, $projectId, $language) {
                        $itemScore = $this->scoreReusableItem(
                            $item,
                            $checklist,
                            $storyTokens,
                            (int) ($suggestion['score'] ?? 0),
                            (int) $checklist->project_id === $projectId
                        );

                        if ($itemScore < 25) {
                            return null;
                        }

                        return [
                            'title' => $this->generationText->localizeCaseName($item->title, $language),
                            'description' => $this->generationText->localizeChecklistText($item->description ?? '', $language),
                            'priority' => $this->normalizePriority($item->priority),
                            'criticality' => $this->normalizeCriticality($item->criticality),
                            'source' => 'reused',
                            'source_checklist_id' => $checklist->id,
                            'source_checklist_name' => $checklist->name,
                            'source_project_match' => (int) $checklist->project_id === $projectId,
                            'relevance_score' => $itemScore,
                        ];
                    })
                    ->filter();
            })
            ->sortByDesc('relevance_score')
            ->values()
            ->all();
    }

    private function normalizeGeneratedCases(array $testCases, string $language): array
    {
        return collect($testCases)
            ->map(function (array $testCase, int $index) use ($language) {
                $localized = $this->generationText->localizeCase([
                    ...$testCase,
                    'language' => $language,
                ], $language);

                return [
                    'title' => trim((string) ($localized['name'] ?? 'Test Case ' . ($index + 1))),
                    'description' => $this->buildGeneratedItemDescription($localized, $language),
                    'priority' => $this->severityToPriority($localized['severity'] ?? 'medium'),
                    'criticality' => $this->severityToCriticality($localized['severity'] ?? 'medium'),
                    'source' => 'generated',
                    'source_checklist_id' => null,
                    'source_checklist_name' => null,
                    'generation_category' => trim((string) ($localized['category'] ?? '')),
                    'covers' => array_values(array_filter(array_map('strval', $localized['covers'] ?? []))),
                ];
            })
            ->filter(fn (array $item) => $item['title'] !== '')
            ->values()
            ->all();
    }

    private function mergeItems(array $reusableItems, array $generatedItems): array
    {
        return Collection::make([...$reusableItems, ...$generatedItems])
            ->unique(fn (array $item) => $this->fingerprint($item['title']))
            ->take(12)
            ->values()
            ->all();
    }

    private function buildDecision(array $storyContext, array $riskProfile, array $recommendations, array $reusableItems, array $generatedItems, array $coverage, string $language): array
    {
        $recommendedAction = $recommendations['summary']['recommended_action'] ?? 'create_new_draft';
        $coverageRatio = (int) ($coverage['coverage_ratio'] ?? 0);
        $missing = array_values(array_unique(array_merge(
            $coverage['missing_criteria'] ?? [],
            $coverage['missing_business_rules'] ?? [],
            $coverage['missing_scenarios'] ?? [],
            $coverage['missing_dimensions'] ?? [],
        )));
        $covered = array_values(array_unique(array_merge(
            $coverage['covered_criteria'] ?? [],
            $coverage['covered_business_rules'] ?? [],
            $coverage['covered_scenarios'] ?? [],
            $coverage['covered_dimensions'] ?? [],
        )));

        return [
            'recommended_action' => $recommendedAction,
            'strategy' => count($reusableItems) > 0 ? 'reuse_then_generate_gaps' : 'generate_new_draft',
            'best_score' => $recommendations['summary']['best_score'] ?? 0,
            'coverage_ratio' => $coverageRatio,
            'domain' => $storyContext['primary_domain'] ?? 'general',
            'intent' => $storyContext['intent'] ?? 'generic_flow',
            'risk_level' => $riskProfile['level'] ?? 'medium',
            'covered' => array_slice($covered, 0, 8),
            'missing' => array_slice($missing, 0, 8),
            'reason' => count($reusableItems) > 0
                ? $this->generationText->text($language, 'decision_reason_reused')
                : $this->generationText->text($language, 'decision_reason_generated'),
            'generated_cases' => count($generatedItems),
            'confidence' => $this->confidenceLevel($coverageRatio, count($reusableItems), count($generatedItems)),
            'explanation' => $recommendations['summary']['explanation']
                ?? $this->generationText->text($language, 'decision_explanation_default'),
        ];
    }

    private function buildChecklistDescription(UserStory $userStory, string $generatorName, array $recommendations, string $language): string
    {
        $action = $recommendations['summary']['recommended_action'] ?? 'GENERATE_NEW';
        $score = $recommendations['summary']['best_score'] ?? 0;

        return <<<DESC
{$this->generationText->text($language, 'description_generated_from', ['title' => $userStory->title])}
{$this->generationText->text($language, 'description_generator', ['generator' => $generatorName])}
{$this->generationText->text($language, 'description_reuse_decision', ['action' => $action])}
{$this->generationText->text($language, 'description_reuse_score', ['score' => $score])}
{$this->generationText->text($language, 'description_generated_at', ['timestamp' => $this->getCurrentTimestamp()])}

{$this->generationText->text($language, 'description_label')}
{$userStory->description}

{$this->generationText->text($language, 'acceptance_criteria_label')}
{$userStory->acceptance_criteria}
DESC;
    }

    private function buildChecklistName(UserStory $userStory, bool $includesReusableItems, string $language): string
    {
        $projectName = trim((string) optional($userStory->project)->name);
        $storyTitle = trim((string) $userStory->title);
        $prefix = $includesReusableItems
            ? $this->generationText->text($language, 'name_adapted')
            : $this->generationText->text($language, 'name_generated');
        $storyContext = trim((string) $userStory->title . ' ' . (string) $userStory->description);

        if ($projectName !== '') {
            $projectTokens = $this->tokenizeForReuse($projectName);
            $storyTokens = $this->tokenizeForReuse($storyContext);
            $sharedTokens = array_intersect($projectTokens, $storyTokens);

            if ($sharedTokens !== []) {
                return "{$projectName} - {$prefix} - {$storyTitle}";
            }
        }

        return "{$prefix} - {$storyTitle}";
    }

    private function buildGeneratedItemDescription(array $testCase, string $language): string
    {
        $description = trim((string) ($testCase['description'] ?? ''));
        $expected = trim((string) ($testCase['expected_result'] ?? ''));
        $covers = array_values(array_filter(array_map('strval', $testCase['covers'] ?? [])));

        if ($covers !== []) {
            $description = trim($description . "\n\n" . $this->generationText->text($language, 'covers_label') . ' ' . implode(', ', $covers));
        }

        if ($expected === '') {
            return $description;
        }

        return trim($description . "\n\n" . $this->generationText->text($language, 'expected_result_label') . ' ' . $expected);
    }

    private function fingerprint(string $value): string
    {
        $normalized = strtolower($value);

        return preg_replace('/[^a-z0-9]+/', '', $normalized) ?: $normalized;
    }

    private function scoreReusableItem(ChecklistItem $item, Checklist $checklist, array $storyTokens, int $sourceChecklistScore, bool $sameProject = false): int
    {
        $itemContext = implode(' ', array_filter([
            $checklist->name,
            $item->title,
            $item->description,
        ]));

        $itemTokens = $this->tokenizeForReuse($itemContext);
        $overlap = array_values(array_intersect($storyTokens, $itemTokens));
        $storyDomains = $this->detectDomains(implode(' ', $storyTokens));
        $itemDomains = $this->detectDomains($itemContext);

        if ($overlap === []) {
            return 0;
        }

        if (!$this->domainsAreCompatible($storyDomains, $itemDomains)) {
            return 0;
        }

        $tokenScore = min(80, count($overlap) * 20);
        $sourceBonus = min(20, (int) floor($sourceChecklistScore / 10));
        $domainBonus = $itemDomains !== [] && array_intersect($storyDomains, $itemDomains) !== [] ? 20 : 0;
        $projectBonus = $sameProject ? 20 : 0;
        $approvedBonus = $checklist->lifecycle_status === 'approved' ? 10 : 0;

        return $tokenScore + $sourceBonus + $domainBonus + $projectBonus + $approvedBonus;
    }

    private function tokenizeForReuse(string $value): array
    {
        $normalized = strtolower($value);
        $parts = preg_split('/[^a-z0-9]+/', $normalized) ?: [];

        return Collection::make($parts)
            ->map(fn (string $part) => trim($part))
            ->filter(fn (string $part) => strlen($part) >= 4)
            ->reject(fn (string $part) => in_array($part, self::GENERIC_TOKENS, true))
            ->unique()
            ->values()
            ->all();
    }

    private function detectDomains(string $value): array
    {
        $normalized = strtolower($value);
        $domains = [];

        foreach (self::DOMAIN_KEYWORDS as $domain => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, strtolower($keyword))) {
                    $domains[] = $domain;
                    break;
                }
            }
        }

        return array_values(array_unique($domains));
    }

    private function domainsAreCompatible(array $storyDomains, array $itemDomains): bool
    {
        if ($storyDomains === [] || $itemDomains === []) {
            return true;
        }

        return array_intersect($storyDomains, $itemDomains) !== [];
    }

    private function confidenceLevel(int $coverageRatio, int $reusedItems, int $generatedItems): string
    {
        if ($coverageRatio >= 80 && $reusedItems > 0 && $generatedItems > 0) {
            return 'high';
        }

        if ($coverageRatio >= 50) {
            return 'medium';
        }

        return 'low';
    }

    private function normalizePriority(?string $priority): string
    {
        return in_array($priority, ['Low', 'Medium', 'High'], true) ? $priority : 'Medium';
    }

    private function normalizeCriticality(?string $criticality): string
    {
        return in_array($criticality, ['Minor', 'Major', 'Critical'], true) ? $criticality : 'Major';
    }

    private function severityToPriority(string $severity): string
    {
        return match (strtolower($severity)) {
            'critical', 'high' => 'High',
            'low' => 'Low',
            default => 'Medium',
        };
    }

    private function severityToCriticality(string $severity): string
    {
        return match (strtolower($severity)) {
            'critical', 'high' => 'Critical',
            'low' => 'Minor',
            default => 'Major',
        };
    }

    private function getCurrentTimestamp(): string
    {
        return now()->format('Y-m-d H:i:s');
    }

    private function buildGenerationContext(array $storyContext, array $reusableItems, array $coverage, array $recommendations, array $riskProfile, string $language): array
    {
        return [
            'story_context' => $storyContext,
            'reusable_items' => array_slice($reusableItems, 0, 6),
            'generation_focus' => $coverage['generation_focus'] ?? [],
            'gap_summary' => $coverage['gap_summary'] ?? [],
            'recommendation_summary' => $recommendations['summary'] ?? [],
            'risk_profile' => $riskProfile,
            'language' => $language,
        ];
    }
}
