<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\ChecklistItemHistory;
use App\Models\Project;
use App\Models\UserStory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ChecklistController extends Controller
{
    public function toggle(Checklist $checklist)
    {
        $this->authorizeChecklistDesign($checklist);

        $checklist->is_active = !$checklist->is_active;
        $checklist->save();

        return response()->json([
            'message' => 'Checklist status updated',
            'is_active' => $checklist->is_active,
        ]);
    }

    public function index(Request $request)
    {
        $query = Checklist::with('items')
            ->where('is_active', true)
            ->orderByDesc('id');

        $projectId = $request->integer('project_id');

        if ($projectId) {
            $query->where(function ($builder) use ($projectId) {
                $builder
                    ->where('project_id', $projectId)
                    ->orWhere('template_scope', 'global')
                    ->orWhereNull('project_id')
                    ->orWhereHas('userStories', fn ($storyQuery) => $storyQuery->where('project_id', $projectId))
                    ->orWhereHas('sourceUserStory', fn ($storyQuery) => $storyQuery->where('project_id', $projectId));
            });
        } elseif (Auth::user()?->hasRole('testeur')) {
            $assignedProjectIds = Project::whereHas('testers', fn ($testerQuery) => $testerQuery->where('users.id', Auth::id()))
                ->pluck('id');

            $query->where(function ($builder) use ($assignedProjectIds) {
                $builder
                    ->where('created_by', Auth::id())
                    ->orWhere('template_scope', 'global')
                    ->orWhereNull('project_id')
                    ->orWhereIn('project_id', $assignedProjectIds)
                    ->orWhereHas('userStories', fn ($storyQuery) => $storyQuery->whereIn('project_id', $assignedProjectIds))
                    ->orWhereHas('sourceUserStory', fn ($storyQuery) => $storyQuery->whereIn('project_id', $assignedProjectIds));
            });
        }

        $perPage = min(max($request->integer('per_page', 10), 1), 100);

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'project_id' => ['nullable', 'exists:projects,id'],
            'as_a' => ['nullable', 'string'],
            'i_want_that' => ['nullable', 'string'],
            'so_that' => ['nullable', 'string'],
            'acceptance_criteria' => ['nullable', 'string'],
            'business_rules' => ['nullable', 'array'],
            'business_rules.*' => ['string'],
            'priority' => ['nullable', 'in:low,medium,high,critical'],
            'status' => ['nullable', 'in:backlog,in_progress,ready_for_test,completed'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'category' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'template_scope' => ['nullable', 'in:global,project'],
            'lifecycle_status' => ['nullable', 'in:draft,approved,archived'],
            'generated_from' => ['nullable', 'in:manual,ai,reuse'],
            'source_user_story_id' => ['nullable', 'exists:user_stories,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.title' => ['required', 'string'],
            'items.*.description' => ['nullable', 'string'],
            'items.*.priority' => ['required', 'in:Low,Medium,High'],
            'items.*.criticality' => ['required', 'in:Minor,Major,Critical'],
            'items.*.status' => ['nullable', 'in:pending,passed,failed,blocked'],
        ]);

        $project = !empty($data['project_id']) ? Project::findOrFail($data['project_id']) : null;

        if ($project) {
            $this->ensureTesterOwnsProject($project);
        }

        if (!empty($data['source_user_story_id'])) {
            $story = UserStory::findOrFail($data['source_user_story_id']);
            abort_unless($project && (int) $story->project_id === (int) $project->id, 422, 'User story does not belong to this project.');
        }

        DB::beginTransaction();

        try {
            $checklist = Checklist::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'project_id' => $project?->id,
                'as_a' => $data['as_a'] ?? null,
                'i_want_that' => $data['i_want_that'] ?? null,
                'so_that' => $data['so_that'] ?? null,
                'acceptance_criteria' => $data['acceptance_criteria'] ?? null,
                'business_rules' => $data['business_rules'] ?? null,
                'priority' => $data['priority'] ?? 'medium',
                'status' => $data['status'] ?? 'ready_for_test',
                'assigned_to' => $data['assigned_to'] ?? null,
                'category' => $data['category'] ?? 'Execution',
                'created_by' => Auth::id(),
                'is_active' => $data['is_active'] ?? true,
                'template_scope' => $data['template_scope'] ?? ($project ? 'project' : 'global'),
                'lifecycle_status' => $data['lifecycle_status'] ?? ($project ? 'draft' : 'approved'),
                'generated_from' => $data['generated_from'] ?? 'manual',
                'source_user_story_id' => $data['source_user_story_id'] ?? null,
            ]);

            foreach ($data['items'] as $index => $item) {
                ChecklistItem::create([
                    'checklist_id' => $checklist->id,
                    'title' => $item['title'],
                    'description' => $item['description'] ?? null,
                    'priority' => $item['priority'],
                    'criticality' => $item['criticality'],
                    'status' => $item['status'] ?? 'pending',
                    'order' => $index,
                ]);
            }

            DB::commit();

            return response()->json($checklist->load('items'), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error creating checklist'], 500);
        }
    }

    public function show(Checklist $checklist)
    {
        if ($checklist->project_id) {
            $this->ensureChecklistAccessible(Project::findOrFail($checklist->project_id));
        }

        return response()->json($checklist->load(['items.tester']));
    }

    public function update(Request $request, Checklist $checklist)
    {
        $this->authorizeChecklistDesign($checklist);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string'],
            'description' => ['nullable', 'string'],
            'project_id' => ['nullable', 'exists:projects,id'],
            'as_a' => ['nullable', 'string'],
            'i_want_that' => ['nullable', 'string'],
            'so_that' => ['nullable', 'string'],
            'acceptance_criteria' => ['nullable', 'string'],
            'business_rules' => ['nullable', 'array'],
            'business_rules.*' => ['string'],
            'priority' => ['nullable', 'in:low,medium,high,critical'],
            'status' => ['nullable', 'in:backlog,in_progress,ready_for_test,completed'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'category' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'template_scope' => ['nullable', 'in:global,project'],
            'lifecycle_status' => ['nullable', 'in:draft,approved,archived'],
            'generated_from' => ['nullable', 'in:manual,ai,reuse'],
            'source_user_story_id' => ['nullable', 'exists:user_stories,id'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.id' => ['sometimes', 'integer', 'exists:checklist_items,id'],
            'items.*.title' => ['required_with:items', 'string'],
            'items.*.description' => ['nullable', 'string'],
            'items.*.priority' => ['required_with:items', 'in:Low,Medium,High'],
            'items.*.criticality' => ['required_with:items', 'in:Minor,Major,Critical'],
            'items.*.status' => ['nullable', 'in:pending,passed,failed,blocked'],
        ]);

        if (!empty($data['project_id'])) {
            $project = Project::findOrFail($data['project_id']);
            $this->ensureTesterOwnsProject($project);
        }

        if (!empty($data['source_user_story_id'])) {
            $story = UserStory::findOrFail($data['source_user_story_id']);
            $expectedProjectId = $data['project_id'] ?? $checklist->project_id;
            abort_unless((int) $story->project_id === (int) $expectedProjectId, 422, 'User story does not belong to this project.');
        }

        DB::beginTransaction();

        try {
            $checklist->update([
                'name' => $data['name'] ?? $checklist->name,
                'description' => array_key_exists('description', $data) ? $data['description'] : $checklist->description,
                'project_id' => array_key_exists('project_id', $data) ? $data['project_id'] : $checklist->project_id,
                'as_a' => array_key_exists('as_a', $data) ? $data['as_a'] : $checklist->as_a,
                'i_want_that' => array_key_exists('i_want_that', $data) ? $data['i_want_that'] : $checklist->i_want_that,
                'so_that' => array_key_exists('so_that', $data) ? $data['so_that'] : $checklist->so_that,
                'acceptance_criteria' => array_key_exists('acceptance_criteria', $data) ? $data['acceptance_criteria'] : $checklist->acceptance_criteria,
                'business_rules' => array_key_exists('business_rules', $data) ? $data['business_rules'] : $checklist->business_rules,
                'priority' => $data['priority'] ?? $checklist->priority,
                'status' => $data['status'] ?? $checklist->status,
                'assigned_to' => array_key_exists('assigned_to', $data) ? $data['assigned_to'] : $checklist->assigned_to,
                'category' => $data['category'] ?? $checklist->category,
                'is_active' => $data['is_active'] ?? $checklist->is_active,
                'template_scope' => $data['template_scope'] ?? $checklist->template_scope,
                'lifecycle_status' => $data['lifecycle_status'] ?? $checklist->lifecycle_status,
                'generated_from' => $data['generated_from'] ?? $checklist->generated_from,
                'source_user_story_id' => array_key_exists('source_user_story_id', $data) ? $data['source_user_story_id'] : $checklist->source_user_story_id,
            ]);

            if (array_key_exists('items', $data)) {
                $existingIds = $checklist->items()->pluck('id')->toArray();
                $incomingIds = [];

                foreach ($data['items'] as $index => $itemData) {
                    if (!empty($itemData['id'])) {
                        $item = $checklist->items()->where('id', $itemData['id'])->first();

                        if (!$item) {
                            DB::rollBack();
                            return response()->json(['message' => 'Invalid item id for this checklist'], 422);
                        }

                        $item->update([
                            'title' => $itemData['title'],
                            'description' => $itemData['description'] ?? null,
                            'priority' => $itemData['priority'],
                            'criticality' => $itemData['criticality'],
                            'status' => $itemData['status'] ?? $item->status ?? 'pending',
                            'order' => $index,
                        ]);

                        $incomingIds[] = $item->id;
                    } else {
                        $newItem = $checklist->items()->create([
                            'title' => $itemData['title'],
                            'description' => $itemData['description'] ?? null,
                            'priority' => $itemData['priority'],
                            'criticality' => $itemData['criticality'],
                            'status' => $itemData['status'] ?? 'pending',
                            'order' => $index,
                        ]);

                        $incomingIds[] = $newItem->id;
                    }
                }

                $toDelete = array_diff($existingIds, $incomingIds);

                if (!empty($toDelete)) {
                    $checklist->items()->whereIn('id', $toDelete)->delete();
                }
            }

            DB::commit();

            return response()->json($checklist->load('items'));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error updating checklist'], 500);
        }
    }

    public function destroy(Checklist $checklist)
    {
        $this->authorizeChecklistDesign($checklist);

        $checklist->delete();

        return response()->json(['message' => 'Checklist deleted']);
    }

    public function exportJson($id = null)
    {
        if ($id) {
            $checklist = Checklist::with('items')->find($id);
            if (!$checklist) {
                return response()->json(['error' => 'Checklist not found'], 404);
            }
            $checklists = collect([$checklist]);
        } else {
            $checklists = Checklist::with('items')->get();
        }

        $data = $checklists->map(function ($checklist) {
            return [
                'id' => $checklist->id,
                'name' => $checklist->name,
                'description' => $checklist->description,
                'category' => $checklist->category,
                'is_active' => $checklist->is_active,
                'priority' => $checklist->priority,
                'status' => $checklist->status,
                'template_scope' => $checklist->template_scope,
                'lifecycle_status' => $checklist->lifecycle_status,
                'generated_from' => $checklist->generated_from,
                'acceptance_criteria' => $checklist->acceptance_criteria,
                'items' => $checklist->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'title' => $item->title,
                        'description' => $item->description,
                        'priority' => $item->priority,
                        'criticality' => $item->criticality,
                        'status' => $item->status,
                    ];
                }),
            ];
        });

        $filename = $id ? "checklist_{$id}_" . date('Y-m-d_H-i-s') : 'checklists_' . date('Y-m-d_H-i-s');
        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => "attachment; filename={$filename}.json",
        ];

        $callback = function () use ($data) {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function exportCsv($id = null)
    {
        if ($id) {
            $checklist = Checklist::with('items')->find($id);
            if (!$checklist) {
                return response()->json(['error' => 'Checklist not found'], 404);
            }
            $checklists = collect([$checklist]);
        } else {
            $checklists = Checklist::with('items')->get();
        }

        $filename = $id ? "checklist_{$id}_" . date('Y-m-d_H-i-s') : 'checklists_' . date('Y-m-d_H-i-s');
        $headers = [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename={$filename}.csv",
        ];

        $callback = function () use ($checklists) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($file, ['Checklist Name', 'Category', 'Checklist Status', 'Item Title', 'Item Description', 'Priority', 'Criticality', 'Item Status']);

            foreach ($checklists as $checklist) {
                if ($checklist->items->isEmpty()) {
                    fputcsv($file, [
                        $checklist->name,
                        $checklist->category ?: '',
                        $checklist->is_active ? 'Active' : 'Inactive',
                        '',
                        '',
                        '',
                        '',
                        '',
                    ]);
                    continue;
                }

                foreach ($checklist->items as $item) {
                    fputcsv($file, [
                        $checklist->name,
                        $checklist->category ?: '',
                        $checklist->is_active ? 'Active' : 'Inactive',
                        $item->title,
                        $item->description ?: '',
                        $item->priority,
                        $item->criticality,
                        ucfirst($item->status ?? 'pending'),
                    ]);
                }
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function exportExcel($id = null)
    {
        if ($id) {
            $checklist = Checklist::with('items')->find($id);
            if (!$checklist) {
                return response()->json(['error' => 'Checklist not found'], 404);
            }
            $checklists = collect([$checklist]);
        } else {
            $checklists = Checklist::with('items')->get();
        }

        $filename = $id ? "checklist_{$id}_" . date('Y-m-d_H-i-s') : 'checklists_' . date('Y-m-d_H-i-s');
        $headers = [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename={$filename}.xlsx",
        ];

        $callback = function () use ($checklists) {
            echo $this->generateExcelXml($checklists);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function generateExcelXml($checklists)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet" xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";
        $xml .= '<Styles>' . "\n";
        $xml .= '<Style ss:ID="Header"><Font ss:Bold="1"/><Interior ss:Color="#4472C4" ss:Pattern="Solid"/><Font ss:Color="#FFFFFF"/></Style>' . "\n";
        $xml .= '</Styles>' . "\n";
        $xml .= '<Worksheet ss:Name="Checklists">' . "\n";
        $xml .= '<Table>' . "\n";
        $xml .= '<Row ss:StyleID="Header">' . "\n";

        foreach (['Checklist Name', 'Category', 'Checklist Status', 'Item Title', 'Item Description', 'Priority', 'Criticality', 'Item Status'] as $header) {
            $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($header) . '</Data></Cell>' . "\n";
        }

        $xml .= '</Row>' . "\n";

        foreach ($checklists as $checklist) {
            if ($checklist->items->isEmpty()) {
                $xml .= '<Row>' . "\n";
                $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($checklist->name) . '</Data></Cell>' . "\n";
                $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($checklist->category ?: '') . '</Data></Cell>' . "\n";
                $xml .= '<Cell><Data ss:Type="String">' . ($checklist->is_active ? 'Active' : 'Inactive') . '</Data></Cell>' . "\n";
                $xml .= '<Cell><Data ss:Type="String"></Data></Cell>' . "\n";
                $xml .= '<Cell><Data ss:Type="String"></Data></Cell>' . "\n";
                $xml .= '<Cell><Data ss:Type="String"></Data></Cell>' . "\n";
                $xml .= '<Cell><Data ss:Type="String"></Data></Cell>' . "\n";
                $xml .= '<Cell><Data ss:Type="String"></Data></Cell>' . "\n";
                $xml .= '</Row>' . "\n";
                continue;
            }

            foreach ($checklist->items as $item) {
                $xml .= '<Row>' . "\n";
                $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($checklist->name) . '</Data></Cell>' . "\n";
                $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($checklist->category ?: '') . '</Data></Cell>' . "\n";
                $xml .= '<Cell><Data ss:Type="String">' . ($checklist->is_active ? 'Active' : 'Inactive') . '</Data></Cell>' . "\n";
                $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($item->title) . '</Data></Cell>' . "\n";
                $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($item->description ?: '') . '</Data></Cell>' . "\n";
                $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($item->priority) . '</Data></Cell>' . "\n";
                $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($item->criticality) . '</Data></Cell>' . "\n";
                $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars(ucfirst($item->status ?? 'pending')) . '</Data></Cell>' . "\n";
                $xml .= '</Row>' . "\n";
            }
        }

        $xml .= '</Table>' . "\n";
        $xml .= '</Worksheet>' . "\n";
        $xml .= '</Workbook>' . "\n";

        return $xml;
    }

    public function getAvailableItems()
    {
        $items = ChecklistItem::selectRaw('MIN(id) as id, title, description, priority, criticality')
            ->groupBy('title', 'description', 'priority', 'criticality')
            ->orderBy('title')
            ->get();

        return response()->json($items);
    }

    public function updateItemStatus(Request $request, Checklist $checklist, ChecklistItem $item)
    {
        $this->ensureChecklistItemBelongsToChecklist($checklist, $item);

        $data = $request->validate([
            'status' => ['required', 'in:Not Tested,Passed,Failed,Blocked'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $oldStatus = $this->displayItemStatus($item->status);
        $newStatus = $data['status'];

        $item->update([
            'status' => $this->normalizeItemStatus($newStatus),
            'tested_by' => $newStatus === 'Not Tested' ? null : Auth::id(),
            'tested_at' => $newStatus === 'Not Tested' ? null : now(),
        ]);

        if ($oldStatus !== $newStatus) {
            ChecklistItemHistory::create([
                'checklist_item_id' => $item->id,
                'changed_by' => Auth::id(),
                'field_name' => 'status',
                'old_value' => $oldStatus,
                'new_value' => $newStatus,
                'change_type' => 'status_changed',
                'notes' => $data['notes'] ?? null,
            ]);
        }

        return response()->json([
            'message' => 'Checklist item status updated.',
            'status' => $newStatus,
            'qa_comment' => $item->qa_comment,
            'tested_by' => $item->tester()->first(['id', 'name', 'email']),
            'tested_at' => optional($item->tested_at)->toISOString(),
            'history' => $this->formatChecklistItemHistoryCollection($item),
        ]);
    }

    public function getItemHistory(Checklist $checklist, ChecklistItem $item)
    {
        $this->ensureChecklistItemBelongsToChecklist($checklist, $item);

        return response()->json($this->formatChecklistItemHistoryCollection($item));
    }

    public function getItemComment(Checklist $checklist, ChecklistItem $item)
    {
        $this->ensureChecklistItemBelongsToChecklist($checklist, $item);

        $latestCommentHistory = $item->history()
            ->whereIn('change_type', ['comment_added', 'comment_updated'])
            ->with('changedBy:id,name,email')
            ->latest()
            ->first();

        return response()->json(
            [
                'comment' => $item->qa_comment,
                'updated_at' => optional($latestCommentHistory?->created_at)->toISOString(),
                'updated_by' => $latestCommentHistory?->changedBy,
            ]
        );
    }

    public function updateItemComment(Request $request, Checklist $checklist, ChecklistItem $item)
    {
        $this->ensureChecklistItemBelongsToChecklist($checklist, $item);

        $data = $request->validate([
            'comment' => ['required', 'string', 'min:1', 'max:2000'],
        ]);

        $newComment = trim($data['comment']);
        $oldComment = $item->qa_comment;
        $changeType = blank($oldComment) ? 'comment_added' : 'comment_updated';

        $item->update([
            'qa_comment' => $newComment,
        ]);

        ChecklistItemHistory::create([
            'checklist_item_id' => $item->id,
            'changed_by' => Auth::id(),
            'field_name' => 'qa_comment',
            'old_value' => $oldComment,
            'new_value' => $newComment,
            'change_type' => $changeType,
            'notes' => null,
        ]);

        return response()->json([
            'message' => 'Checklist item comment updated.',
            'comment' => $item->qa_comment,
            'history' => $this->formatChecklistItemHistoryCollection($item),
        ]);
    }

    private function authorizeChecklistDesign(Checklist $checklist): void
    {
        if (!$checklist->project_id) {
            abort_unless(
                Auth::id() && (int) $checklist->created_by === (int) Auth::id(),
                403,
                'Only the creator can edit this system checklist.'
            );
            return;
        }

        $this->ensureTesterOwnsProject(Project::findOrFail($checklist->project_id));
    }

    private function ensureChecklistAccessible(Project $project): void
    {
        $user = Auth::user();

        abort_unless(
            $user && (
                $user->hasRole('admin') ||
                $user->hasRole('chef') ||
                ($user->hasRole('testeur') && $project->testers()->where('users.id', $user->id)->exists())
            ),
            403,
            'Only assigned testers, project managers, or admins can view this execution checklist.'
        );
    }

    private function ensureTesterOwnsProject(Project $project): void
    {
        $user = Auth::user();

        abort_unless(
            $user && $user->hasRole('testeur') && $project->testers()->where('users.id', $user->id)->exists(),
            403,
            'Only assigned testers can manage execution checklists for this project.'
        );
    }

    private function ensureChecklistItemBelongsToChecklist(Checklist $checklist, ChecklistItem $item): void
    {
        if ((int) $item->checklist_id !== (int) $checklist->id) {
            abort(422, 'Checklist item does not belong to this checklist.');
        }
    }

    private function formatChecklistItemHistoryCollection(ChecklistItem $item)
    {
        return $item->history()
            ->with('changedBy:id,name,email')
            ->get()
            ->map(fn (ChecklistItemHistory $history) => $this->formatChecklistItemHistoryEntry($history))
            ->values();
    }

    private function formatChecklistItemHistoryEntry(ChecklistItemHistory $history): array
    {
        return [
            'id' => $history->id,
            'field_name' => $history->field_name,
            'old_value' => $history->old_value,
            'new_value' => $history->new_value,
            'change_type' => $history->change_type,
            'action_type' => $history->change_type,
            'notes' => $history->notes,
            'created_at' => optional($history->created_at)->toISOString(),
            'changed_by' => $history->changedBy,
        ];
    }

    private function normalizeItemStatus(string $status): string
    {
        return match ($status) {
            'Not Tested' => 'pending',
            'Passed' => 'passed',
            'Failed' => 'failed',
            'Blocked' => 'blocked',
            default => 'pending',
        };
    }

    private function displayItemStatus(?string $status): string
    {
        return match ($status) {
            'passed' => 'Passed',
            'failed' => 'Failed',
            'blocked' => 'Blocked',
            'Passed', 'Failed', 'Blocked' => $status,
            default => 'Not Tested',
        };
    }
}
