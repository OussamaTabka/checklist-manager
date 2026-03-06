<?php

namespace App\Http\Controllers;

use App\Models\Checklist;
use Illuminate\Http\Request;

class ChecklistController extends Controller
{
    public function index()
    {
        $checklists = Checklist::paginate(15);
        return response()->json($checklists);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:100',
            'is_active' => 'boolean'
        ]);

        $validated['created_by'] = auth()->id();
        $validated['is_active'] = $validated['is_active'] ?? true;

        $checklist = Checklist::create($validated);
        return response()->json($checklist, 201);
    }

    public function show(Checklist $checklist)
    {
        $checklist->load('items', 'creator');
        return response()->json($checklist);
    }

    public function update(Request $request, Checklist $checklist)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:100',
            'is_active' => 'sometimes|boolean'
        ]);

        $checklist->update($validated);
        return response()->json($checklist);
    }

    public function destroy(Checklist $checklist)
    {
        $checklist->delete();
        return response()->json(null, 204);
    }

    public function toggle(Checklist $checklist)
    {
        $checklist->update(['is_active' => !$checklist->is_active]);
        return response()->json($checklist);
    }
}
