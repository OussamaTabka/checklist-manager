<?php

namespace App\Services;

use App\Models\Checklist;
use App\Models\UserStory;
use Illuminate\Support\Collection;

class ChecklistRecommendationService
{
    public function suggestForUserStory(UserStory $userStory, int $limit = 5): array
    {
        $storyTokens = $this->tokenize(implode(' ', [
            $userStory->title,
            $userStory->description,
            $userStory->acceptance_criteria,
        ]));

        $checklists = Checklist::query()
            ->with(['items:id,checklist_id,title,description,priority,criticality', 'userStories:id,title,description'])
            ->where('is_active', true)
            ->where('lifecycle_status', 'approved')
            ->orderByDesc('updated_at')
            ->get();

        $suggestions = $checklists
            ->map(fn (Checklist $checklist) => $this->scoreChecklist($checklist, $storyTokens))
            ->filter(fn (array $suggestion) => $suggestion['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();

        $bestScore = $suggestions[0]['score'] ?? 0;

        return [
            'summary' => [
                'best_score' => $bestScore,
                'recommended_action' => $this->recommendedAction($bestScore),
                'story_id' => $userStory->id,
                'story_title' => $userStory->title,
            ],
            'suggestions' => $suggestions,
        ];
    }

    private function scoreChecklist(Checklist $checklist, array $storyTokens): array
    {
        $checklistTokens = $this->tokenize(implode(' ', [
            $checklist->name,
            $checklist->description,
            $checklist->category,
            $checklist->userStories->pluck('title')->implode(' '),
            $checklist->items->pluck('title')->implode(' '),
            $checklist->items->pluck('description')->implode(' '),
        ]));

        $overlap = array_values(array_intersect($storyTokens, $checklistTokens));
        $score = count($overlap) === 0
            ? 0
            : min(100, (int) round((count($overlap) / max(count($storyTokens), 1)) * 100));

        return [
            'id' => $checklist->id,
            'name' => $checklist->name,
            'description' => $checklist->description,
            'category' => $checklist->category,
            'template_scope' => $checklist->template_scope,
            'generated_from' => $checklist->generated_from,
            'lifecycle_status' => $checklist->lifecycle_status,
            'items_count' => $checklist->items->count(),
            'score' => $score,
            'matched_terms' => array_slice($overlap, 0, 8),
        ];
    }

    private function recommendedAction(int $bestScore): string
    {
        if ($bestScore >= 60) {
            return 'attach_existing';
        }

        if ($bestScore >= 30) {
            return 'adapt_existing';
        }

        return 'create_new_draft';
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
}
