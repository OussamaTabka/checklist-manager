<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ChecklistController extends Controller

{
    public function toggle(Checklist $checklist)
{
    $checklist->is_active = !$checklist->is_active;
    $checklist->save();

    return response()->json([
        'message' => 'Checklist status updated',
        'is_active' => $checklist->is_active
    ]);
}
    // GET /api/checklists
    public function index()
    {
        $checklists = Checklist::with('items')
            ->orderByDesc('id')
            ->paginate(10);

        return response()->json($checklists);
    }

    // POST /api/checklists
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.title' => ['required', 'string'],
            'items.*.description' => ['nullable', 'string'],
            'items.*.priority' => ['required', 'in:Low,Medium,High'],
            'items.*.criticality' => ['required', 'in:Minor,Major,Critical'],
        ]);

        DB::beginTransaction();

        try {
            $checklist = Checklist::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? 'General',
                'created_by' => Auth::id(),
                'is_active' => true,
            ]);

            foreach ($data['items'] as $index => $item) {
                ChecklistItem::create([
                    'checklist_id' => $checklist->id,
                    'title' => $item['title'],
                    'description' => $item['description'] ?? null,
                    'priority' => $item['priority'],
                    'criticality' => $item['criticality'],
                    'order' => $index,
                ]);
            }

            DB::commit();

            return response()->json(
                $checklist->load('items'),
                201
            );

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error creating checklist'], 500);
        }
    }

    // GET /api/checklists/{checklist}
    public function show(Checklist $checklist)
    {
        return response()->json($checklist->load('items'));
    }

    // PUT /api/checklists/{checklist}
   public function update(Request $request, Checklist $checklist)
{
    $data = $request->validate([
        'name' => ['sometimes', 'required', 'string'],
        'description' => ['nullable', 'string'],
        'category' => ['nullable', 'string'],
        'is_active' => ['sometimes', 'boolean'],

        'items' => ['sometimes', 'array', 'min:1'],
        'items.*.id' => ['sometimes', 'integer', 'exists:checklist_items,id'],
        'items.*.title' => ['required_with:items', 'string'],
        'items.*.description' => ['nullable', 'string'],
        'items.*.priority' => ['required_with:items', 'in:Low,Medium,High'],
        'items.*.criticality' => ['required_with:items', 'in:Minor,Major,Critical'],
    ]);

    DB::beginTransaction();

    try {
        // update checklist fields
        $checklist->update([
            'name' => $data['name'] ?? $checklist->name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $checklist->description,
            'category' => $data['category'] ?? $checklist->category,
            'is_active' => $data['is_active'] ?? $checklist->is_active,
        ]);

        // items update (if provided)
        if (array_key_exists('items', $data)) {
            $existingIds = $checklist->items()->pluck('id')->toArray();
            $incomingIds = [];

            foreach ($data['items'] as $index => $itemData) {
                // Security: ensure item belongs to this checklist if id provided
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
                        'order' => $index,
                    ]);

                    $incomingIds[] = $item->id;
                } else {
                    $newItem = $checklist->items()->create([
                        'title' => $itemData['title'],
                        'description' => $itemData['description'] ?? null,
                        'priority' => $itemData['priority'],
                        'criticality' => $itemData['criticality'],
                        'order' => $index,
                    ]);

                    $incomingIds[] = $newItem->id;
                }
            }

            // delete removed items
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

    // DELETE /api/checklists/{checklist}
    public function destroy(Checklist $checklist)
    {
        $checklist->delete(); // soft delete

        return response()->json(['message' => 'Checklist deleted']);
    }
}