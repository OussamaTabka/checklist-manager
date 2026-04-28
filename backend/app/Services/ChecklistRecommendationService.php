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
        'notification' => ['notification', 'email', 'alert', 'message'],
        'dashboard' => ['dashboard', 'kpi', 'report', 'metric'],
    ];

    private const ENTITY_KEYWORDS = [
        'user' => ['user', 'customer', 'client', 'tester'],
        'admin' => ['admin', 'manager', 'owner'],
        'project' => ['project', 'workspace'],
        'checklist' => ['checklist', 'template', 'scenario'],
        'data' => ['data', 'record', 'payload', 'field'],
    ];

    private const ACTION_KEYWORDS = [
        'create' => ['create', 'add', 'insert'],
        'read' => ['view', 'display', 'read', 'show'],
        'update' => ['update', 'edit', 'change', 'modify'],
        'delete' => ['delete', 'remove', 'detach'],
        'login' => ['login', 'sign in', 'authenticate'],
        'submit' => ['submit', 'send', 'confirm'],
    ];

    private const RISK_KEYWORDS = [
        'security' => ['security', 'xss', 'csrf', 'sql', 'permission', 'authorization', 'access'],
        'validation' => ['validation', 'error', 'invalid', 'required', 'boundary'],
        'performance' => ['performance', 'load', 'speed', 'latency', 'timeout'],
        'usability' => ['ux', 'ui', 'responsive', 'accessibility', 'focus'],
        'data_integrity' => ['duplicate', 'consistency', 'integrity', 'persistence'],
    ];

    public function suggestForUserStory(UserStory $userStory, int $limit = 5): array
    {
        $storyContext = implode(' ', [
            $userStory->title,
            $userStory->description,
            $userStory->acceptance_criteria,
            $userStory->as_a,
            $userStory->i_want_that,
            $userStory->so_that,
        ]);
        $storyTokens = $this->tokenize($storyContext);
        $storyConcepts = $this->extractConcepts($storyContext);

        $checklists = Checklist::query()
            ->with(['items:id,checklist_id,title,description,priority,criticality', 'userStories:id,title,description'])
            ->where('is_active', true)
            ->where('lifecycle_status', 'approved')
            ->orderByDesc('updated_at')
            ->get();

        $suggestions = $checklists
            ->map(fn (Checklist $checklist) => $this->scoreChecklist($checklist, $storyTokens, $storyConcepts))
            ->filter(fn (array $suggestion) => $suggestion['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();

        $bestScore = $suggestions[0]['score'] ?? 0;

        return [
            'user_story_id' => $userStory->story_id ?: "US-{$userStory->id}",
            'summary' => [
                'best_score' => $bestScore,
                'recommended_action' => $this->recommendedAction($bestScore),
                'story_id' => $userStory->id,
                'story_title' => $userStory->title,
                'review_flow' => ['REVIEW', 'ADAPT', 'ATTACH', 'EXECUTE'],
            ],
            'suggestions' => $suggestions,
        ];
    }

    public function findSuggestionForChecklist(UserStory $userStory, Checklist $checklist): ?array
    {
        $storyContext = implode(' ', [
            $userStory->title,
            $userStory->description,
            $userStory->acceptance_criteria,
            $userStory->as_a,
            $userStory->i_want_that,
            $userStory->so_that,
        ]);

        $suggestion = $this->scoreChecklist(
            $checklist,
            $this->tokenize($storyContext),
            $this->extractConcepts($storyContext)
        );

        return $suggestion['score'] > 0 ? $suggestion : null;
    }

    private function scoreChecklist(Checklist $checklist, array $storyTokens, array $storyConcepts): array
    {
        $checklistContext = implode(' ', [
            $checklist->name,
            $checklist->description,
            $checklist->category,
            $checklist->userStories->pluck('title')->implode(' '),
            $checklist->items->pluck('title')->implode(' '),
            $checklist->items->pluck('description')->implode(' '),
        ]);
        $checklistTokens = $this->tokenize($checklistContext);
        $checklistConcepts = $this->extractConcepts($checklistContext);

        $overlap = array_values(array_intersect($storyTokens, $checklistTokens));
        $conceptCoverage = $this->conceptCoverage($storyConcepts, $checklistConcepts);
        $tokenScore = count($overlap) === 0
            ? 0
            : (count($overlap) / max(count($storyTokens), 1)) * 45;
        $conceptScore = ($conceptCoverage['coverage_percent'] / 100) * 55;
        $score = min(100, (int) round($tokenScore + $conceptScore));
        $coverage = $conceptCoverage['coverage'];
        $missing = $conceptCoverage['missing'];
        $matchingKeywords = array_values(array_unique(array_merge($overlap, $coverage)));

        return [
            'checklist_id' => sprintf('CL-%03d', $checklist->id),
            'source_checklist_id' => $checklist->id,
            'title' => $checklist->name,
            'description' => $checklist->description,
            'category' => $checklist->category,
            'score' => $score,
            'coverage' => array_slice($coverage, 0, 8),
            'missing' => array_slice($missing, 0, 8),
            'matching_keywords' => array_slice($matchingKeywords, 0, 8),
            'recommendation' => $this->recommendedAction($score),
            'items_count' => $checklist->items->count(),
            'status' => $checklist->is_active ? 'Active' : 'Inactive',
            'template_scope' => $checklist->template_scope,
            'generated_from' => $checklist->generated_from,
            'lifecycle_status' => $checklist->lifecycle_status,
            'actions' => ['VIEW_DETAILS', 'ADAPT_COPY', 'ATTACH_DIRECTLY'],
            'explanation' => $this->buildExplanation($coverage, $missing, $score),

            // Backward compatibility for existing UI
            'id' => $checklist->id,
            'name' => $checklist->name,
            'matched_terms' => array_slice($matchingKeywords, 0, 8),
        ];
    }

    private function recommendedAction(int $bestScore): string
    {
        if ($bestScore > 80) {
            return 'REUSE';
        }

        if ($bestScore > 50) {
            return 'ADAPT_EXISTING';
        }

        return 'GENERATE_NEW';
    }

    private function tokenize(string $value): array
    {
        $normalized = strtolower($value);
        $parts = preg_split('/[^a-z0-9]+/', $normalized) ?: [];

        return Collection::make($parts)
            ->filter(fn (string $part) => strlen($part) >= 4)
            ->unique()
            ->values()
            ->all();
    }

    private function extractConcepts(string $context): array
    {
        return [
            ...$this->matchConcepts($context, self::FEATURE_KEYWORDS),
            ...$this->matchConcepts($context, self::ENTITY_KEYWORDS),
            ...$this->matchConcepts($context, self::ACTION_KEYWORDS),
            ...$this->matchConcepts($context, self::RISK_KEYWORDS),
        ];
    }

    private function matchConcepts(string $context, array $map): array
    {
        $normalized = strtolower($context);
        $matches = [];

        foreach ($map as $concept => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, strtolower($keyword))) {
                    $matches[] = str_replace('_', ' ', $concept);
                    break;
                }
            }
        }

        return array_values(array_unique($matches));
    }

    private function conceptCoverage(array $storyConcepts, array $checklistConcepts): array
    {
        $coverage = array_values(array_intersect($storyConcepts, $checklistConcepts));
        $missing = array_values(array_diff($storyConcepts, $checklistConcepts));
        $coveragePercent = empty($storyConcepts)
            ? 0
            : (int) round((count($coverage) / count($storyConcepts)) * 100);

        return [
            'coverage' => $coverage,
            'missing' => $missing,
            'coverage_percent' => $coveragePercent,
        ];
    }

    private function buildExplanation(array $coverage, array $missing, int $score): string
    {
        if ($score <= 50) {
            return 'Low reuse fit. Review gaps and generate a new checklist if required.';
        }

        if (!empty($missing)) {
            return 'Partial coverage detected. Review the checklist and adapt a project copy before attaching.';
        }

        return 'Strong match with the user story. Review details, then reuse or attach after confirmation.';
    }
}
