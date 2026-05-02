<?php

namespace App\Services;

use App\Models\Checklist;
use App\Models\UserStory;
use Illuminate\Support\Collection;

class ChecklistRecommendationService
{
    private const FEATURE_KEYWORDS = [
        'authentication' => ['auth', 'authentication', 'login', 'logout', 'session', 'password', 'credential'],
        'validation' => ['validation', 'validate', 'required', 'invalid', 'error', 'constraint'],
        'api' => ['api', 'endpoint', 'request', 'response', 'json', 'token', 'bearer'],
        'crud' => ['create', 'update', 'edit', 'delete', 'remove', 'save'],
        'search' => ['search', 'filter', 'sort', 'query'],
        'upload' => ['upload', 'file', 'attachment', 'document'],
        'notification' => ['notification', 'email', 'alert', 'message', 'sms', 'confirmation', 'reminder'],
        'dashboard' => ['dashboard', 'kpi', 'report', 'metric'],
        'booking' => ['appointment', 'booking', 'slot', 'reservation', 'rendez', 'teleconsultation', 'consultation'],
        'payment' => ['payment', 'checkout', 'card', 'cvv', 'order'],
    ];

    private const ENTITY_KEYWORDS = [
        'user' => ['user', 'customer', 'client', 'tester', 'patient'],
        'admin' => ['admin', 'manager', 'owner'],
        'project' => ['project', 'workspace'],
        'checklist' => ['checklist', 'template', 'scenario'],
        'data' => ['data', 'record', 'payload', 'field'],
        'doctor' => ['doctor', 'medecin', 'practitioner', 'specialty'],
    ];

    public function __construct(
        private ?StoryContextExtractor $storyContextExtractor = null,
        private ?RiskProfileAnalyzer $riskProfileAnalyzer = null,
    ) {
        $this->storyContextExtractor ??= new StoryContextExtractor();
        $this->riskProfileAnalyzer ??= new RiskProfileAnalyzer();
    }

    public function suggestForUserStory(UserStory $userStory, int $limit = 5): array
    {
        $storyContext = $this->storyContextExtractor->extract($userStory);
        $riskProfile = $this->riskProfileAnalyzer->analyze($storyContext);
        $storyTokens = $storyContext['tokens'] ?? [];

        $checklists = Checklist::query()
            ->with(['items:id,checklist_id,title,description,priority,criticality', 'userStories:id,title,description,project_id'])
            ->where('is_active', true)
            ->where('lifecycle_status', 'approved')
            ->where(function ($query) use ($userStory) {
                $query->where('project_id', $userStory->project_id)
                    ->orWhere('template_scope', 'global');
            })
            ->orderByRaw('CASE WHEN project_id = ? THEN 0 ELSE 1 END', [$userStory->project_id])
            ->orderByDesc('updated_at')
            ->get();

        $scoredSuggestions = $checklists
            ->map(fn (Checklist $checklist) => $this->scoreChecklist($checklist, $userStory, $storyContext, $storyTokens, $riskProfile));

        $suggestions = $scoredSuggestions
            ->filter(fn (array $suggestion) => $this->isDisplayableSuggestion($suggestion, $storyContext))
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();

        $bestScore = $suggestions[0]['score'] ?? 0;
        $bestSuggestion = $suggestions[0] ?? null;

        return [
            'user_story_id' => $userStory->story_id ?: "US-{$userStory->id}",
            'summary' => [
                'best_score' => $bestScore,
                'recommended_action' => $suggestions === [] ? 'GENERATE_NEW' : $this->recommendedAction($bestScore),
                'story_id' => $userStory->id,
                'story_title' => $userStory->title,
                'primary_domain' => $storyContext['primary_domain'] ?? 'general',
                'intent' => $storyContext['intent'] ?? 'generic_flow',
                'risk_level' => $riskProfile['level'],
                'risk_score' => $riskProfile['score'],
                'required_dimensions' => $riskProfile['required_dimensions'],
                'coverage_ratio' => $bestSuggestion['coverage_ratio'] ?? 0,
                'review_flow' => ['REVIEW', 'ADAPT', 'ATTACH', 'EXECUTE'],
                'explanation' => $bestSuggestion['explanation'] ?? 'No suitable checklist was found with strong coverage.',
            ],
            'story_context' => [
                'domain' => $storyContext['primary_domain'] ?? 'general',
                'intent' => $storyContext['intent'] ?? 'generic_flow',
                'entities' => $storyContext['entities'] ?? [],
                'dimensions' => $storyContext['dimensions'] ?? [],
                'integration_points' => $storyContext['integration_points'] ?? [],
            ],
            'risk_profile' => $riskProfile,
            'suggestions' => $suggestions,
        ];
    }

    public function findSuggestionForChecklist(UserStory $userStory, Checklist $checklist): ?array
    {
        $storyContext = $this->storyContextExtractor->extract($userStory);
        $riskProfile = $this->riskProfileAnalyzer->analyze($storyContext);

        $suggestion = $this->scoreChecklist($checklist, $userStory, $storyContext, $storyContext['tokens'] ?? [], $riskProfile);

        return $suggestion['score'] > 0 ? $suggestion : null;
    }

    private function scoreChecklist(
        Checklist $checklist,
        UserStory $userStory,
        array $storyContext,
        array $storyTokens,
        array $riskProfile
    ): array {
        $checklistContext = $this->buildChecklistContext($checklist);
        $checklistTokens = $this->tokenize($checklistContext);
        $checklistConcepts = $this->extractConcepts($checklistContext);
        $checklistDimensions = $this->inferDimensions($checklistContext);
        $checklistDomains = $this->inferDomains($checklistContext);
        $criteriaCoverage = $this->coverageScore($storyContext['acceptance_criteria'] ?? [], $checklistContext);
        $businessRuleCoverage = $this->coverageScore($storyContext['business_rules'] ?? [], $checklistContext);
        $scenarioCoverage = $this->coverageScore($storyContext['scenarios'] ?? [], $checklistContext);
        $riskCoverage = $this->dimensionCoverage($riskProfile['required_dimensions'] ?? [], $checklistDimensions);
        $storyConcepts = $this->extractConcepts(implode(' ', $storyTokens));
        $entityAlignment = $this->entityAlignmentScore($storyContext['entities'] ?? [], $checklistContext);
        $intentCompatibility = $this->intentCompatibilityScore($storyContext['intent'] ?? 'generic_flow', $checklistContext);

        $lexicalCoverage = array_values(array_intersect($storyTokens, $checklistTokens));
        $lexicalScore = $storyTokens === [] ? 0 : (count($lexicalCoverage) / max(count($storyTokens), 1)) * 20;
        $domainScore = $this->domainScore($storyContext['primary_domain'] ?? 'general', $checklistDomains) * 20;
        $criteriaScore = $criteriaCoverage['percent'] * 0.20;
        $ruleScore = $businessRuleCoverage['percent'] * 0.10;
        $scenarioScore = $scenarioCoverage['percent'] * 0.10;
        $riskScore = $riskCoverage['percent'] * 0.15;
        $projectScore = ((int) $checklist->project_id === (int) $userStory->project_id ? 1 : 0) * 5;
        $conceptScore = $this->conceptMatchScore($storyConcepts, $checklistConcepts) * 0.05;
        $entityScore = $entityAlignment * 0.10;
        $intentScore = $intentCompatibility * 0.10;

        $score = min(100, (int) round(
            $lexicalScore + $domainScore + $criteriaScore + $ruleScore + $scenarioScore + $riskScore + $projectScore + $conceptScore + $entityScore + $intentScore
        ));

        $covered = array_values(array_unique(array_merge(
            $criteriaCoverage['covered_labels'],
            $businessRuleCoverage['covered_labels'],
            $scenarioCoverage['covered_labels'],
            $riskCoverage['covered_labels']
        )));
        $missing = array_values(array_unique(array_merge(
            $criteriaCoverage['missing_labels'],
            $businessRuleCoverage['missing_labels'],
            $scenarioCoverage['missing_labels'],
            $riskCoverage['missing_labels']
        )));

        return [
            'checklist_id' => sprintf('CL-%03d', $checklist->id),
            'source_checklist_id' => $checklist->id,
            'title' => $checklist->name,
            'description' => $checklist->description,
            'category' => $checklist->category,
            'score' => $score,
            'coverage_ratio' => $this->aggregateCoverageRatio([
                $criteriaCoverage['percent'],
                $businessRuleCoverage['percent'],
                $scenarioCoverage['percent'],
                $riskCoverage['percent'],
            ]),
            'coverage' => array_slice($covered, 0, 12),
            'missing' => array_slice($missing, 0, 12),
            'matching_keywords' => array_slice($lexicalCoverage, 0, 10),
            'recommendation' => $this->recommendedAction($score),
            'items_count' => $checklist->items->count(),
            'status' => $checklist->is_active ? 'Active' : 'Inactive',
            'template_scope' => $checklist->template_scope,
            'generated_from' => $checklist->generated_from,
            'lifecycle_status' => $checklist->lifecycle_status,
            'actions' => ['VIEW_DETAILS', 'ADAPT_COPY', 'ATTACH_DIRECTLY'],
            'score_breakdown' => [
                'text_similarity' => (int) round($lexicalScore),
                'domain_match' => (int) round($domainScore),
                'acceptance_criteria' => (int) round($criteriaScore),
                'business_rules' => (int) round($ruleScore),
                'scenarios' => (int) round($scenarioScore),
                'risk_coverage' => (int) round($riskScore),
                'project_relevance' => (int) round($projectScore),
                'concept_match' => (int) round($conceptScore),
                'entity_alignment' => (int) round($entityScore),
                'intent_compatibility' => (int) round($intentScore),
                'execution_history' => 0,
            ],
            'explanation' => $this->buildExplanation($storyContext, $score, $covered, $missing, (int) $checklist->project_id === (int) $userStory->project_id),

            // Backward compatibility for existing UI
            'id' => $checklist->id,
            'name' => $checklist->name,
            'matched_terms' => array_slice($lexicalCoverage, 0, 10),
        ];
    }

    private function recommendedAction(int $bestScore): string
    {
        if ($bestScore >= 85) {
            return 'REUSE';
        }

        if ($bestScore >= 50) {
            return 'ADAPT_EXISTING';
        }

        return 'GENERATE_NEW';
    }

    private function buildChecklistContext(Checklist $checklist): string
    {
        return implode(' ', [
            $checklist->name,
            $checklist->description,
            $checklist->category,
            $checklist->userStories->pluck('title')->implode(' '),
            $checklist->items->pluck('title')->implode(' '),
            $checklist->items->pluck('description')->implode(' '),
        ]);
    }

    private function tokenize(string $value): array
    {
        $normalized = $this->normalizeText($value);
        $parts = preg_split('/[^a-z0-9]+/', $normalized) ?: [];

        return Collection::make($parts)
            ->map(fn (string $part) => trim($part))
            ->filter(fn (string $part) => strlen($part) >= 4)
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeText(string $value): string
    {
        $normalized = strtolower(trim($value));
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);

        return $transliterated !== false ? $transliterated : $normalized;
    }

    private function extractConcepts(string $context): array
    {
        $normalized = $this->normalizeText($context);
        $matches = [];

        foreach ([self::FEATURE_KEYWORDS, self::ENTITY_KEYWORDS] as $map) {
            foreach ($map as $concept => $keywords) {
                foreach ($keywords as $keyword) {
                    if (str_contains($normalized, $this->normalizeText($keyword))) {
                        $matches[] = str_replace('_', ' ', $concept);
                        break;
                    }
                }
            }
        }

        return array_values(array_unique($matches));
    }

    private function inferDimensions(string $context): array
    {
        $dimensions = [];
        $normalized = $this->normalizeText($context);

        foreach ([
            'happy_path' => ['success', 'confirm', 'normal', 'nominal'],
            'validation' => ['validation', 'invalid', 'required', 'format'],
            'business_rules' => ['must', 'only', 'future', 'rule'],
            'error_handling' => ['error', 'failed', 'timeout', 'unavailable'],
            'notifications' => ['notification', 'email', 'sms', 'reminder'],
            'data_integrity' => ['duplicate', 'status', 'persist', 'consistency'],
            'security' => ['permission', 'access', 'token', 'password'],
            'integrations' => ['api', 'provider', 'gateway', 'scheduler'],
            'auditability' => ['log', 'audit', 'trace'],
        ] as $dimension => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, $keyword)) {
                    $dimensions[] = $dimension;
                    break;
                }
            }
        }

        return array_values(array_unique($dimensions));
    }

    private function inferDomains(string $context): array
    {
        $domains = [];
        $normalized = $this->normalizeText($context);

        foreach (self::FEATURE_KEYWORDS as $domain => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, $this->normalizeText($keyword))) {
                    $domains[] = $domain;
                    break;
                }
            }
        }

        return array_values(array_unique($domains));
    }

    private function coverageScore(array $requirements, string $checklistContext): array
    {
        $covered = [];
        $missing = [];
        $normalizedChecklist = $this->normalizeText($checklistContext);

        foreach ($requirements as $requirement) {
            $requirement = trim((string) $requirement);
            if ($requirement === '') {
                continue;
            }

            $tokens = $this->tokenize($requirement);
            $matches = 0;

            foreach ($tokens as $token) {
                if (str_contains($normalizedChecklist, $token)) {
                    $matches++;
                }
            }

            if ($matches >= max(1, (int) ceil(count($tokens) / 3))) {
                $covered[] = $requirement;
            } else {
                $missing[] = $requirement;
            }
        }

        $total = count($covered) + count($missing);
        $percent = $total === 0 ? 0 : (int) round((count($covered) / $total) * 100);

        return [
            'percent' => $percent,
            'covered_labels' => array_map(fn (string $label) => "covered: {$label}", $covered),
            'missing_labels' => array_map(fn (string $label) => "missing: {$label}", $missing),
        ];
    }

    private function dimensionCoverage(array $requiredDimensions, array $checklistDimensions): array
    {
        $covered = array_values(array_intersect($requiredDimensions, $checklistDimensions));
        $missing = array_values(array_diff($requiredDimensions, $checklistDimensions));
        $total = count($covered) + count($missing);

        return [
            'percent' => $total === 0 ? 0 : (int) round((count($covered) / $total) * 100),
            'covered_labels' => array_map(fn (string $label) => "dimension covered: {$label}", $covered),
            'missing_labels' => array_map(fn (string $label) => "dimension missing: {$label}", $missing),
        ];
    }

    private function domainScore(string $storyDomain, array $checklistDomains): int
    {
        if ($storyDomain === 'general') {
            return 50;
        }

        if (in_array($storyDomain, $checklistDomains, true)) {
            return 100;
        }

        return count(array_intersect([$storyDomain, 'notification', 'booking', 'payment', 'authentication'], $checklistDomains)) > 0 ? 45 : 0;
    }

    private function conceptMatchScore(array $storyConcepts, array $checklistConcepts): int
    {
        if ($storyConcepts === []) {
            return 0;
        }

        return (int) round((count(array_intersect($storyConcepts, $checklistConcepts)) / count($storyConcepts)) * 100);
    }

    private function entityAlignmentScore(array $storyEntities, string $checklistContext): int
    {
        if ($storyEntities === []) {
            return 0;
        }

        $normalized = $this->normalizeText($checklistContext);
        $matched = 0;

        foreach ($storyEntities as $entity) {
            $keywords = self::ENTITY_KEYWORDS[$entity] ?? [$entity];

            foreach ($keywords as $keyword) {
                if (str_contains($normalized, $this->normalizeText($keyword))) {
                    $matched++;
                    break;
                }
            }
        }

        return (int) round(($matched / count($storyEntities)) * 100);
    }

    private function intentCompatibilityScore(string $storyIntent, string $checklistContext): int
    {
        $normalized = $this->normalizeText($checklistContext);

        $intentKeywords = match ($storyIntent) {
            'create_booking' => ['book', 'reserve', 'reservation', 'slot'],
            'cancel_booking' => ['cancel', 'annul', 'appointment', 'slot'],
            'reschedule_booking' => ['reschedule', 'move', 'change', 'appointment'],
            'reminder_notification' => ['reminder', 'notification', 'scheduler', 'appointment', 'consultation'],
            'authentication_access' => ['login', 'password', 'session', 'access'],
            'payment_processing' => ['payment', 'checkout', 'card', 'invoice'],
            default => [],
        };

        if ($intentKeywords === []) {
            return 0;
        }

        $matches = 0;

        foreach ($intentKeywords as $keyword) {
            if (str_contains($normalized, $this->normalizeText($keyword))) {
                $matches++;
            }
        }

        return (int) round(($matches / count($intentKeywords)) * 100);
    }

    private function isDisplayableSuggestion(array $suggestion, array $storyContext): bool
    {
        $breakdown = $suggestion['score_breakdown'] ?? [];
        $storyDomain = $storyContext['primary_domain'] ?? 'general';
        $storyIntent = $storyContext['intent'] ?? 'generic_flow';

        if (($suggestion['score'] ?? 0) < 55) {
            return false;
        }

        if (($suggestion['coverage_ratio'] ?? 0) < 45) {
            return false;
        }

        if (($breakdown['domain_match'] ?? 0) < 12) {
            return false;
        }

        if (($breakdown['entity_alignment'] ?? 0) < 5) {
            return false;
        }

        if ($storyDomain === 'notification' && ($breakdown['intent_compatibility'] ?? 0) < 6) {
            return false;
        }

        if (in_array($storyIntent, ['reminder_notification', 'cancel_booking', 'create_booking', 'reschedule_booking'], true)
            && ($breakdown['intent_compatibility'] ?? 0) < 4) {
            return false;
        }

        return true;
    }

    private function aggregateCoverageRatio(array $ratios): int
    {
        $ratios = array_values(array_filter($ratios, fn ($value) => is_numeric($value)));

        if ($ratios === []) {
            return 0;
        }

        return (int) round(array_sum($ratios) / count($ratios));
    }

    private function buildExplanation(array $storyContext, int $score, array $covered, array $missing, bool $sameProject): string
    {
        $domain = $storyContext['primary_domain'] ?? 'general';
        $intent = $storyContext['intent'] ?? 'generic_flow';
        $coveragePreview = implode(', ', array_slice($covered, 0, 3));
        $missingPreview = implode(', ', array_slice($missing, 0, 3));

        if ($score < 50) {
            return "Weak {$domain}/{$intent} fit. Coverage is low and important gaps remain: {$missingPreview}. Generate a new draft.";
        }

        $projectHint = $sameProject ? 'same project' : 'shared template';
        return "This {$projectHint} checklist matches the {$domain} domain and {$intent} intent. Covered: {$coveragePreview}. Missing: {$missingPreview}.";
    }
}
