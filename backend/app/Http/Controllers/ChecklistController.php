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

    /**
     * Export checklists to JSON format
     */
    public function exportJson($id = null)
    {
        if ($id) {
            $checklists = Checklist::with('items')->find($id);
            if (!$checklists) {
                return response()->json(['error' => 'Checklist not found'], 404);
            }
            $checklists = collect([$checklists]);
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
        
        return response()->download(
            storage_path("export_{$filename}.json"),
            "{$filename}.json",
            function() use ($data) {
                echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
        )->deleteFileAfterSend(false);
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
     */
    public function getAvailableItems()
    {
        $items = \App\Models\ChecklistItem::select('id', 'title', 'description', 'priority', 'criticality')
            ->distinct()
            ->get();
        
        return response()->json($items);
    }
}
