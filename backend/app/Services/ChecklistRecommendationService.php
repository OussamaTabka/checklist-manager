<?php

namespace App\Services;

use App\Models\Checklist;
use App\Models\UserStory;
use Illuminate\Support\Collection;

class ChecklistRecommendationService
{
    private const MINIMUM_SCORE = 60;
    private const MAX_RESULTS = 5;

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

    private const STOP_WORDS = [
        'avec', 'avant', 'apres', 'alors', 'ainsi', 'dans', 'depuis', 'pour', 'sans', 'sous',
        'entre', 'cette', 'cet', 'cette', 'comme', 'plus', 'moins', 'tous', 'toutes', 'leurs',
        'leur', 'etre', 'avoir', 'faire', 'that', 'this', 'with', 'when', 'then', 'given', 'from',
        'into', 'after', 'before', 'user', 'story', 'checklist', 'system', 'application', 'project',
        'details', 'information', 'description', 'criteres', 'critere', 'rules', 'regles', 'scenario',
    ];

    public function __construct(
        private ?StoryContextExtractor $storyContextExtractor = null,
        private ?RiskProfileAnalyzer $riskProfileAnalyzer = null,
    ) {
        $this->storyContextExtractor ??= new StoryContextExtractor();
        $this->riskProfileAnalyzer ??= new RiskProfileAnalyzer();
    }

    public function suggestForUserStory(UserStory $userStory, int $limit = self::MAX_RESULTS): array
    {
        $storyContext = $this->storyContextExtractor->extract($userStory);
        $riskProfile = $this->riskProfileAnalyzer->analyze($storyContext);
        $checklists = $this->candidateChecklists($userStory);

        $suggestions = $this->deduplicateSuggestions(
            $checklists
                ->map(fn (Checklist $checklist) => $this->scoreChecklist($checklist, $userStory, $storyContext, $riskProfile))
                ->filter(fn (array $suggestion) => $this->isDisplayableSuggestion($suggestion, $storyContext))
                ->sortByDesc('score')
                ->values()
                ->all()
        );

        $suggestions = array_slice($suggestions, 0, max(1, $limit));
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
                'review_flow' => ['VIEW', 'ADAPT', 'CREATE_DRAFT', 'ASSOCIATE'],
                'explanation' => $bestSuggestion['explanation'] ?? 'No similar checklist was found in the existing approved templates.',
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

        if (!$this->isEligibleChecklist($checklist, $userStory)) {
            return null;
        }

        $suggestion = $this->scoreChecklist($checklist, $userStory, $storyContext, $riskProfile);

        return $this->isDisplayableSuggestion($suggestion, $storyContext) ? $suggestion : null;
    }

    private function candidateChecklists(UserStory $userStory): Collection
    {
        $attachedChecklistIds = $userStory->checklists()->pluck('checklists.id')->map(fn ($id) => (int) $id)->all();

        return Checklist::query()
            ->with([
                'items:id,checklist_id,title,description,priority,criticality',
                'userStories:id,title,description,project_id',
                'sourceUserStory:id,title,description,acceptance_criteria',
            ])
            ->where('is_active', true)
            ->where('lifecycle_status', 'approved')
            ->whereIn('generated_from', ['manual', 'reuse'])
            ->where(function ($query) use ($userStory) {
                $query->where('project_id', $userStory->project_id)
                    ->orWhere('template_scope', 'global');
            })
            ->when($attachedChecklistIds !== [], fn ($query) => $query->whereNotIn('id', $attachedChecklistIds))
            ->orderByRaw('CASE WHEN project_id = ? THEN 0 ELSE 1 END', [$userStory->project_id])
            ->orderByDesc('updated_at')
            ->get();
    }

    private function isEligibleChecklist(Checklist $checklist, UserStory $userStory): bool
    {
        if (!$checklist->is_active || $checklist->lifecycle_status !== 'approved') {
            return false;
        }

        if (!in_array($checklist->generated_from, ['manual', 'reuse'], true)) {
            return false;
        }

        if (
            (int) $checklist->project_id !== (int) $userStory->project_id &&
            $checklist->template_scope !== 'global'
        ) {
            return false;
        }

        return !$userStory->checklists()->where('checklist_id', $checklist->id)->exists();
    }

    private function scoreChecklist(
        Checklist $checklist,
        UserStory $userStory,
        array $storyContext,
        array $riskProfile
    ): array {
        $storyWeights = $this->buildStoryTokenWeights($userStory, $storyContext);
        $checklistWeights = $this->buildChecklistTokenWeights($checklist);

        $titleSimilarity = $this->weightedSimilarity(
            $this->tokenWeights((string) $userStory->title, 1),
            $this->tokenWeights((string) $checklist->name, 1)
        );
        $criteriaSimilarity = $this->weightedSimilarity(
            $this->tokenWeights(
                implode(' ', array_merge(
                    $storyContext['acceptance_criteria'] ?? [],
                    $storyContext['business_rules'] ?? [],
                    $storyContext['scenarios'] ?? []
                )),
                1
            ),
            $this->tokenWeights(
                $checklist->items->pluck('title')->implode(' ') . ' ' . $checklist->items->pluck('description')->implode(' '),
                1
            )
        );
        $overallSimilarity = $this->weightedSimilarity($storyWeights, $checklistWeights);
        $descriptionSimilarity = $this->weightedSimilarity(
            $this->tokenWeights((string) $userStory->description, 1),
            $this->tokenWeights(
                implode(' ', array_filter([
                    $checklist->description,
                    $checklist->category,
                    optional($checklist->sourceUserStory)->title,
                    optional($checklist->sourceUserStory)->description,
                ])),
                1
            )
        );

        $checklistContext = $this->buildChecklistContext($checklist);
        $checklistDimensions = $this->inferDimensions($checklistContext);
        $checklistDomains = $this->inferDomains($checklistContext);
        $checklistIntent = $this->inferIntent($checklistContext, $checklistDomains);
        $criteriaCoverage = $this->coverageScore($storyContext['acceptance_criteria'] ?? [], $checklistContext);
        $businessRuleCoverage = $this->coverageScore($storyContext['business_rules'] ?? [], $checklistContext);
        $scenarioCoverage = $this->coverageScore($storyContext['scenarios'] ?? [], $checklistContext);
        $riskCoverage = $this->dimensionCoverage($riskProfile['required_dimensions'] ?? [], $checklistDimensions);
        $entityAlignment = $this->entityAlignmentScore($storyContext['entities'] ?? [], $checklistContext);
        $domainScore = $this->domainScore($storyContext['primary_domain'] ?? 'general', $checklistDomains);
        $intentScore = $this->intentScore($storyContext['intent'] ?? 'generic_flow', $checklistIntent, $checklistContext);
        $sameProjectBonus = (int) $checklist->project_id === (int) $userStory->project_id ? 8 : 0;
        $penalty = $this->mismatchPenalty($storyContext, $checklistDomains, $checklistIntent, $overallSimilarity);

        $rawScore = (
            ($overallSimilarity * 0.28) +
            ($titleSimilarity * 0.20) +
            ($criteriaSimilarity * 0.24) +
            ($descriptionSimilarity * 0.08) +
            ($domainScore * 0.10) +
            ($intentScore * 0.05) +
            ($entityAlignment * 0.05) +
            ($riskCoverage['percent'] * 0.05) +
            $sameProjectBonus -
            $penalty
        );

        $score = (int) round(max(0, min(100, $rawScore)));
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
            'matching_keywords' => array_slice(array_keys(array_intersect_key($storyWeights, $checklistWeights)), 0, 12),
            'recommendation' => $this->recommendedAction($score),
            'items_count' => $checklist->items->count(),
            'status' => $checklist->is_active ? 'Active' : 'Inactive',
            'template_scope' => $checklist->template_scope,
            'generated_from' => $checklist->generated_from,
            'lifecycle_status' => $checklist->lifecycle_status,
            'actions' => ['VIEW_DETAILS', 'ADAPT_COPY', 'CREATE_DRAFT'],
            'global_criticality' => $this->globalCriticality($checklist),
            'score_breakdown' => [
                'overall_similarity' => (int) round($overallSimilarity),
                'title_similarity' => (int) round($titleSimilarity),
                'criteria_similarity' => (int) round($criteriaSimilarity),
                'description_similarity' => (int) round($descriptionSimilarity),
                'domain_match' => (int) round($domainScore),
                'intent_match' => (int) round($intentScore),
                'entity_alignment' => (int) round($entityAlignment),
                'risk_coverage' => (int) round($riskCoverage['percent']),
                'same_project_bonus' => $sameProjectBonus,
                'penalty' => $penalty,
            ],
            'explanation' => $this->buildExplanation(
                $storyContext,
                $score,
                $covered,
                $missing,
                (int) $checklist->project_id === (int) $userStory->project_id
            ),

            'id' => $checklist->id,
            'name' => $checklist->name,
            'matched_terms' => array_slice(array_keys(array_intersect_key($storyWeights, $checklistWeights)), 0, 12),
        ];
    }

    private function buildStoryTokenWeights(UserStory $userStory, array $storyContext): array
    {
        $weights = [];

        $this->mergeWeights($weights, $this->tokenWeights((string) $userStory->title, 7));
        $this->mergeWeights($weights, $this->tokenWeights((string) $userStory->description, 4));
        $this->mergeWeights($weights, $this->tokenWeights(implode(' ', $storyContext['acceptance_criteria'] ?? []), 6));
        $this->mergeWeights($weights, $this->tokenWeights(implode(' ', $storyContext['business_rules'] ?? []), 5));
        $this->mergeWeights($weights, $this->tokenWeights(implode(' ', $storyContext['scenarios'] ?? []), 5));
        $this->mergeWeights($weights, $this->tokenWeights((string) ($storyContext['priority'] ?? ''), 2));

        return $weights;
    }

    private function buildChecklistTokenWeights(Checklist $checklist): array
    {
        $weights = [];

        $this->mergeWeights($weights, $this->tokenWeights((string) $checklist->name, 7));
        $this->mergeWeights($weights, $this->tokenWeights((string) $checklist->description, 4));
        $this->mergeWeights($weights, $this->tokenWeights((string) $checklist->category, 2));
        $this->mergeWeights($weights, $this->tokenWeights($checklist->items->pluck('title')->implode(' '), 6));
        $this->mergeWeights($weights, $this->tokenWeights($checklist->items->pluck('description')->implode(' '), 5));
        $this->mergeWeights($weights, $this->tokenWeights($checklist->userStories->pluck('title')->implode(' '), 2));
        $this->mergeWeights($weights, $this->tokenWeights($checklist->userStories->pluck('description')->implode(' '), 1));
        $this->mergeWeights($weights, $this->tokenWeights((string) optional($checklist->sourceUserStory)->title, 2));
        $this->mergeWeights($weights, $this->tokenWeights((string) optional($checklist->sourceUserStory)->description, 1));

        return $weights;
    }

    private function tokenWeights(string $value, int $weight): array
    {
        $weights = [];

        foreach ($this->tokenize($value) as $token) {
            $weights[$token] = ($weights[$token] ?? 0) + $weight;
        }

        return $weights;
    }

    private function mergeWeights(array &$target, array $weights): void
    {
        foreach ($weights as $token => $weight) {
            $target[$token] = ($target[$token] ?? 0) + $weight;
        }
    }

    private function weightedSimilarity(array $sourceWeights, array $candidateWeights): int
    {
        if ($sourceWeights === []) {
            return 0;
        }

        $matchedWeight = 0;
        $sourceWeight = array_sum($sourceWeights);

        foreach ($sourceWeights as $token => $weight) {
            if (array_key_exists($token, $candidateWeights)) {
                $matchedWeight += min($weight, $candidateWeights[$token]);
            }
        }

        return (int) round(($matchedWeight / max($sourceWeight, 1)) * 100);
    }

    private function deduplicateSuggestions(array $suggestions): array
    {
        $deduplicated = [];

        foreach ($suggestions as $suggestion) {
            $key = $suggestion['source_checklist_id'] ?? $this->normalizeText((string) ($suggestion['title'] ?? $suggestion['name'] ?? ''));
            if ($key === null || $key === '') {
                continue;
            }

            if (!isset($deduplicated[$key]) || ($suggestion['score'] ?? 0) > ($deduplicated[$key]['score'] ?? 0)) {
                $deduplicated[$key] = $suggestion;
            }
        }

        return array_values($deduplicated);
    }

    private function recommendedAction(int $bestScore): string
    {
        if ($bestScore >= 85) {
            return 'REUSE';
        }

        if ($bestScore >= self::MINIMUM_SCORE) {
            return 'ADAPT_EXISTING';
        }

        return 'GENERATE_NEW';
    }

    private function buildChecklistContext(Checklist $checklist): string
    {
        return implode(' ', array_filter([
            $checklist->name,
            $checklist->description,
            $checklist->category,
            optional($checklist->sourceUserStory)->title,
            optional($checklist->sourceUserStory)->description,
            optional($checklist->sourceUserStory)->acceptance_criteria,
            $checklist->userStories->pluck('title')->implode(' '),
            $checklist->userStories->pluck('description')->implode(' '),
            $checklist->items->pluck('title')->implode(' '),
            $checklist->items->pluck('description')->implode(' '),
        ]));
    }

    private function tokenize(string $value): array
    {
        $normalized = $this->normalizeText($value);
        $parts = preg_split('/[^a-z0-9]+/', $normalized) ?: [];

        return Collection::make($parts)
            ->map(fn (string $part) => trim($part))
            ->filter(fn (string $part) => strlen($part) >= 3)
            ->reject(fn (string $part) => in_array($part, self::STOP_WORDS, true))
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

    private function inferDimensions(string $context): array
    {
        $dimensions = [];
        $normalized = $this->normalizeText($context);

        foreach ([
            'happy_path' => ['success', 'confirm', 'normal', 'nominal'],
            'validation' => ['validation', 'invalid', 'required', 'format'],
            'business_rules' => ['must', 'only', 'future', 'rule', 'cancel', 'annul'],
            'error_handling' => ['error', 'failed', 'timeout', 'unavailable', 'block'],
            'notifications' => ['notification', 'email', 'sms', 'reminder', 'confirmation'],
            'data_integrity' => ['duplicate', 'status', 'persist', 'consistency', 'slot'],
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

    private function inferIntent(string $context, array $domains): string
    {
        $normalized = $this->normalizeText($context);

        foreach ([
            'create_booking' => ['book', 'reserve', 'reservation', 'prendre'],
            'cancel_booking' => ['cancel', 'annul', 'annulation'],
            'reschedule_booking' => ['reschedule', 'move', 'change', 'modifier', 'changer'],
            'reminder_notification' => ['reminder', 'rappel', 'scheduler', 'notification'],
            'authentication_access' => ['login', 'password', 'session', 'access'],
            'payment_processing' => ['payment', 'checkout', 'card', 'invoice'],
        ] as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, $this->normalizeText($keyword))) {
                    return $intent;
                }
            }
        }

        if (in_array('notification', $domains, true)) {
            return 'notification_flow';
        }

        if (in_array('booking', $domains, true)) {
            return 'booking_flow';
        }

        return 'generic_flow';
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
            if ($tokens === []) {
                continue;
            }

            $matches = 0;

            foreach ($tokens as $token) {
                if (str_contains($normalizedChecklist, $token)) {
                    $matches++;
                }
            }

            if ($matches >= max(1, (int) ceil(count($tokens) / 2))) {
                $covered[] = $requirement;
            } else {
                $missing[] = $requirement;
            }
        }

        $total = count($covered) + count($missing);
        $percent = $total === 0 ? 0 : (int) round((count($covered) / $total) * 100);

        return [
            'percent' => $percent,
            'covered_labels' => $covered,
            'missing_labels' => $missing,
        ];
    }

    private function dimensionCoverage(array $requiredDimensions, array $checklistDimensions): array
    {
        $covered = array_values(array_intersect($requiredDimensions, $checklistDimensions));
        $missing = array_values(array_diff($requiredDimensions, $checklistDimensions));
        $total = count($covered) + count($missing);

        return [
            'percent' => $total === 0 ? 0 : (int) round((count($covered) / $total) * 100),
            'covered_labels' => array_map(fn (string $label) => "dimension {$label}", $covered),
            'missing_labels' => array_map(fn (string $label) => "dimension {$label}", $missing),
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

        return count(array_intersect([$storyDomain, 'notification', 'booking', 'payment', 'authentication'], $checklistDomains)) > 0 ? 25 : 0;
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

    private function intentScore(string $storyIntent, string $checklistIntent, string $checklistContext): int
    {
        if ($storyIntent === 'generic_flow') {
            return 40;
        }

        if ($storyIntent === $checklistIntent) {
            return 100;
        }

        $normalized = $this->normalizeText($checklistContext);
        $intentKeywords = match ($storyIntent) {
            'create_booking' => ['book', 'reserve', 'reservation', 'slot'],
            'cancel_booking' => ['cancel', 'annul', 'appointment', 'slot', 'confirmation'],
            'reschedule_booking' => ['reschedule', 'move', 'change', 'appointment'],
            'reminder_notification' => ['reminder', 'notification', 'scheduler', 'appointment'],
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

    private function mismatchPenalty(array $storyContext, array $checklistDomains, string $checklistIntent, int $overallSimilarity): int
    {
        $penalty = 0;
        $storyDomain = $storyContext['primary_domain'] ?? 'general';
        $storyIntent = $storyContext['intent'] ?? 'generic_flow';

        if ($storyDomain !== 'general' && !in_array($storyDomain, $checklistDomains, true)) {
            $penalty += 22;
        }

        if ($storyIntent !== 'generic_flow' && $checklistIntent !== 'generic_flow' && $storyIntent !== $checklistIntent) {
            $penalty += 18;
        }

        if ($overallSimilarity < 35) {
            $penalty += 12;
        }

        return $penalty;
    }

    private function isDisplayableSuggestion(array $suggestion, array $storyContext): bool
    {
        $breakdown = $suggestion['score_breakdown'] ?? [];
        $storyDomain = $storyContext['primary_domain'] ?? 'general';
        $storyIntent = $storyContext['intent'] ?? 'generic_flow';

        if (($suggestion['score'] ?? 0) < self::MINIMUM_SCORE) {
            return false;
        }

        if (($suggestion['coverage_ratio'] ?? 0) < 35) {
            return false;
        }

        if (($breakdown['overall_similarity'] ?? 0) < 35) {
            return false;
        }

        if ($storyDomain !== 'general' && ($breakdown['domain_match'] ?? 0) < 25) {
            return false;
        }

        if (in_array($storyIntent, ['cancel_booking', 'create_booking', 'reschedule_booking', 'reminder_notification'], true)
            && ($breakdown['intent_match'] ?? 0) < 35) {
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

    private function globalCriticality(Checklist $checklist): ?string
    {
        $rank = ['Critical' => 4, 'High' => 3, 'Major' => 3, 'Medium' => 2, 'Low' => 1, 'Minor' => 1];
        $current = null;

        foreach ($checklist->items as $item) {
            $itemCriticality = $item->criticality;
            if (!$itemCriticality) {
                continue;
            }

            if ($current === null || ($rank[$itemCriticality] ?? 0) > ($rank[$current] ?? 0)) {
                $current = $itemCriticality;
            }
        }

        return $current;
    }

    private function buildExplanation(array $storyContext, int $score, array $covered, array $missing, bool $sameProject): string
    {
        $domain = $storyContext['primary_domain'] ?? 'general';
        $intent = $storyContext['intent'] ?? 'generic_flow';
        $coveragePreview = implode(', ', array_slice($covered, 0, 3));
        $missingPreview = implode(', ', array_slice($missing, 0, 3));

        if ($score < self::MINIMUM_SCORE) {
            return "Weak {$domain}/{$intent} fit. Existing checklist coverage remains too limited: {$missingPreview}.";
        }

        $projectHint = $sameProject ? 'same project' : 'shared template';

        return "This {$projectHint} checklist matches the {$domain} domain and {$intent} intent. Covered: {$coveragePreview}. Missing: {$missingPreview}.";
    }
}
