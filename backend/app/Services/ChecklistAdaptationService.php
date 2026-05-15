<?php

namespace App\Services;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Project;
use App\Models\UserStory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ChecklistAdaptationService
{
    public function createDraftForStory(
        Project $project,
        UserStory $userStory,
        Checklist $sourceChecklist,
        array $payload = []
    ): Checklist {
        return DB::transaction(function () use ($project, $userStory, $sourceChecklist, $payload) {
            $items = collect($payload['items'] ?? $sourceChecklist->items()->orderBy('order')->get()->toArray())
                ->map(fn (array $item, int $index) => [
                    'title' => trim((string) ($item['title'] ?? '')),
                    'description' => $item['description'] ?? null,
                    'priority' => in_array(($item['priority'] ?? null), ['Low', 'Medium', 'High'], true) ? $item['priority'] : 'Medium',
                    'criticality' => in_array(($item['criticality'] ?? null), ['Minor', 'Major', 'Critical'], true) ? $item['criticality'] : 'Major',
                    'status' => in_array(($item['status'] ?? null), ['pending', 'passed', 'failed'], true) ? $item['status'] : 'pending',
                    'order' => $index + 1,
                ])
                ->filter(fn (array $item) => $item['title'] !== '')
                ->values();

            $instance = Checklist::create([
                'name' => $payload['name'] ?? "{$sourceChecklist->name} - Story Draft",
                'description' => $payload['description'] ?? $sourceChecklist->description,
                'project_id' => $project->id,
                'as_a' => $payload['as_a'] ?? $sourceChecklist->as_a,
                'i_want_that' => $payload['i_want_that'] ?? $sourceChecklist->i_want_that,
                'so_that' => $payload['so_that'] ?? $sourceChecklist->so_that,
                'acceptance_criteria' => $payload['acceptance_criteria'] ?? $sourceChecklist->acceptance_criteria,
                'business_rules' => $payload['business_rules'] ?? $sourceChecklist->business_rules,
                'priority' => $payload['priority'] ?? $sourceChecklist->priority ?? 'medium',
                'status' => $payload['status'] ?? 'ready_for_test',
                'assigned_to' => $payload['assigned_to'] ?? null,
                'category' => $payload['category'] ?? $sourceChecklist->category,
                'is_active' => true,
                'created_by' => Auth::id(),
                'template_scope' => 'project',
                'lifecycle_status' => 'draft',
                'generated_from' => 'reuse',
                'source_user_story_id' => $userStory->id,
            ]);

            foreach ($items as $item) {
                ChecklistItem::create([
                    'checklist_id' => $instance->id,
                    'title' => $item['title'],
                    'description' => $item['description'],
                    'priority' => $item['priority'],
                    'criticality' => $item['criticality'],
                    'status' => $item['status'],
                    'order' => $item['order'],
                    'source_type' => 'reused',
                    'source_checklist_id' => $sourceChecklist->id,
                    'source_checklist_name' => $sourceChecklist->name,
                ]);
            }

            return $instance->load('items');
        });
    }
}
