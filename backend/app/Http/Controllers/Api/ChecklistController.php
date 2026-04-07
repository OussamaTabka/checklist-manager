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
            ->where('is_active', true)
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

    /**
     * Export checklists to JSON format
     */
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
                'items' => $checklist->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'title' => $item->title,
                        'description' => $item->description,
                        'priority' => $item->priority,
                        'criticality' => $item->criticality,
                    ];
                }),
            ];
        });

        $filename = $id ? "checklist_{$id}_" . date('Y-m-d_H-i-s') : 'checklists_' . date('Y-m-d_H-i-s');
        
        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => "attachment; filename={$filename}.json",
        ];

        $callback = function() use ($data) {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Export checklists to CSV format
     */
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

        $callback = function() use ($checklists) {
            $file = fopen('php://output', 'w');
            
            // Add BOM for Excel UTF-8 compatibility
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // CSV header
            fputcsv($file, ['Checklist Name', 'Category', 'Status', 'Item Title', 'Item Description', 'Priority', 'Criticality']);

            foreach ($checklists as $checklist) {
                if ($checklist->items->isEmpty()) {
                    fputcsv($file, [
                        $checklist->name,
                        $checklist->category ?: '',
                        $checklist->is_active ? 'Active' : 'Inactive',
                        '',
                        '',
                        '',
                        ''
                    ]);
                } else {
                    foreach ($checklist->items as $item) {
                        fputcsv($file, [
                            $checklist->name,
                            $checklist->category ?: '',
                            $checklist->is_active ? 'Active' : 'Inactive',
                            $item->title,
                            $item->description ?: '',
                            $item->priority,
                            $item->criticality,
                        ]);
                    }
                }
            }
            
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Export checklists to Excel format (XLS/XLSX)
     */
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
        
        // Create a simple XLSX using CSV format wrapped in Excel XML (basic implementation)
        $headers = [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename={$filename}.xlsx",
        ];

        $callback = function() use ($checklists) {
            echo $this->generateExcelXml($checklists);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Generate Excel XML format
     */
    private function generateExcelXml($checklists)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet" xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";
        $xml .= '<Styles>' . "\n";
        $xml .= '<Style ss:ID="Header"><Font ss:Bold="1"/><Interior ss:Color="#4472C4" ss:Pattern="Solid"/><Font ss:Color="#FFFFFF"/></Style>' . "\n";
        $xml .= '</Styles>' . "\n";
        $xml .= '<Worksheet ss:Name="Checklists">' . "\n";
        $xml .= '<Table>' . "\n";
        
        // Header row
        $xml .= '<Row ss:StyleID="Header">' . "\n";
        $headers = ['Checklist Name', 'Category', 'Status', 'Item Title', 'Item Description', 'Priority', 'Criticality'];
        foreach ($headers as $header) {
            $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($header) . '</Data></Cell>' . "\n";
        }
        $xml .= '</Row>' . "\n";

        // Data rows
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
                $xml .= '</Row>' . "\n";
            } else {
                foreach ($checklist->items as $item) {
                    $xml .= '<Row>' . "\n";
                    $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($checklist->name) . '</Data></Cell>' . "\n";
                    $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($checklist->category ?: '') . '</Data></Cell>' . "\n";
                    $xml .= '<Cell><Data ss:Type="String">' . ($checklist->is_active ? 'Active' : 'Inactive') . '</Data></Cell>' . "\n";
                    $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($item->title) . '</Data></Cell>' . "\n";
                    $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($item->description ?: '') . '</Data></Cell>' . "\n";
                    $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($item->priority) . '</Data></Cell>' . "\n";
                    $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($item->criticality) . '</Data></Cell>' . "\n";
                    $xml .= '</Row>' . "\n";
                }
            }
        }

        $xml .= '</Table>' . "\n";
        $xml .= '</Worksheet>' . "\n";
        $xml .= '</Workbook>' . "\n";

        return $xml;
    }

    /**
     * Get all unique items for autocomplete
     * GET /api/checklists/items/available
     */
    public function getAvailableItems()
    {
        $items = ChecklistItem::selectRaw('MIN(id) as id, title, description, priority, criticality')
            ->groupBy('title', 'description', 'priority', 'criticality')
            ->orderBy('title')
            ->get();
        
        return response()->json($items);
    }
}