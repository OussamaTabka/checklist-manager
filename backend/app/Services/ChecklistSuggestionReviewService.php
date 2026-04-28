<?php

namespace App\Services;

use App\Models\Checklist;
use App\Models\UserStory;

class ChecklistSuggestionReviewService
{
    public function buildPreview(UserStory $userStory, Checklist $checklist, array $suggestion): array
    {
        return [
            'user_story_id' => $userStory->story_id ?: "US-{$userStory->id}",
            'project_id' => $userStory->project_id,
            'action' => 'VIEW_DETAILS',
            'source_checklist_id' => $checklist->id,
            'suggestion' => $suggestion,
            'checklist' => [
                'id' => $checklist->id,
                'title' => $checklist->name,
                'description' => $checklist->description,
                'category' => $checklist->category,
                'status' => $checklist->is_active ? 'Active' : 'Inactive',
                'lifecycle_status' => $checklist->lifecycle_status,
                'items' => $checklist->items()
                    ->orderBy('order')
                    ->get()
                    ->map(fn ($item, $index) => [
                        'id' => $item->id ?: sprintf('TC-%03d', $index + 1),
                        'title' => $item->title,
                        'description' => $item->description,
                        'priority' => $item->priority,
                        'criticality' => $item->criticality,
                        'status' => ucfirst($item->status ?? 'pending'),
                    ])
                    ->values()
                    ->all(),
            ],
            'allowed_actions' => ['VIEW_DETAILS', 'ADAPT_COPY', 'ATTACH_DIRECTLY'],
            'policy' => [
                'review_required' => true,
                'allow_edit_original' => false,
                'adaptation_mode' => 'CREATE_INSTANCE',
            ],
        ];
    }
}
