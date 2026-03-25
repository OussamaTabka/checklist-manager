<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectVersion;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function exportProject(Project $project, string $format = 'json')
    {
        $report = $this->buildProjectExportData($project);

        return $this->formatResponse($format, $report, 'project_' . $project->id);
    }

    public function exportProjectVersion(ProjectVersion $projectVersion, string $format = 'json')
    {
        $report = $this->buildVersionExportData($projectVersion);

        return $this->formatResponse($format, $report, 'project_version_' . $projectVersion->id);
    }

    private function buildProjectExportData(Project $project): array
    {
        $project->load([
            'versions.items.comments.user',
            'versions.items.tester',
            'versions.checklist',
            'creator'
        ]);

        $allItems = [];
        $versionsSummary = [];

        foreach ($project->versions as $version) {
            $versionItems = [];

            foreach ($version->items as $item) {
                $commentPreview = $item->comments
                    ->take(2)
                    ->map(fn ($comment) => '[' . ($comment->user?->name ?? 'Unknown') . '] ' . str_replace(["\r", "\n"], ' ', (string) $comment->content))
                    ->implode(' || ');

                $row = [
                    'project' => (string) $project->name,
                    'version' => (string) $version->version_number,
                    'checklist' => (string) ($version->checklist?->name ?? ''),
                    'item_order' => (string) (($item->order ?? 0) + 1),
                    'item_title' => (string) $item->title,
                    'item_description' => (string) ($item->description ?? ''),
                    'priority' => (string) ($item->priority ?? ''),
                    'criticality' => (string) ($item->criticality ?? ''),
                    'status' => (string) ($item->status ?? 'Not Tested'),
                    'tested_by' => (string) ($item->tester?->name ?? ''),
                    'tested_at' => (string) ($item->tested_at ?? ''),
                    'comments_count' => (int) $item->comments->count(),
                    'comments_preview' => $commentPreview,
                ];

                $versionItems[] = $row;
                $allItems[] = $row;
            }

            $versionSummary = $this->buildSummary($versionItems);
            $versionsSummary[] = [
                'version' => (string) $version->version_number,
                'checklist' => (string) ($version->checklist?->name ?? ''),
                'total_items' => $versionSummary['total_items'],
                'execution_rate_percent' => $versionSummary['execution_rate_percent'],
                'pass_rate_percent' => $versionSummary['pass_rate_percent'],
                'passed' => $versionSummary['passed'],
                'failed' => $versionSummary['failed'],
                'blocked' => $versionSummary['blocked'],
                'not_tested' => $versionSummary['not_tested'],
            ];
        }

        return [
            'report_metadata' => [
                'report_title' => 'Project Test Execution Report',
                'generated_at' => now()->toDateTimeString(),
                'report_scope' => 'Project Level',
                'format_version' => '1.0',
            ],
            'scope' => [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'project_description' => $project->description,
                'project_owner' => $project->creator?->name,
                'project_created_at' => (string) $project->created_at,
                'total_versions' => count($versionsSummary),
            ],
            'executive_summary' => $this->buildSummary($allItems),
            'status_breakdown' => $this->buildBreakdown($allItems, 'status'),
            'priority_breakdown' => $this->buildBreakdown($allItems, 'priority'),
            'criticality_breakdown' => $this->buildBreakdown($allItems, 'criticality'),
            'versions_summary' => $versionsSummary,
            'items_details' => $allItems,
        ];
    }

    private function buildVersionExportData(ProjectVersion $projectVersion): array
    {
        $projectVersion->load([
            'items.comments.user',
            'items.tester',
            'checklist',
            'project.creator'
        ]);

        $items = [];
        foreach ($projectVersion->items as $item) {
            $commentPreview = $item->comments
                ->take(3)
                ->map(fn ($comment) => '[' . ($comment->user?->name ?? 'Unknown') . '] ' . str_replace(["\r", "\n"], ' ', (string) $comment->content))
                ->implode(' || ');

            $items[] = [
                'project' => (string) ($projectVersion->project?->name ?? ''),
                'version' => (string) $projectVersion->version_number,
                'checklist' => (string) ($projectVersion->checklist?->name ?? ''),
                'item_order' => (string) (($item->order ?? 0) + 1),
                'item_title' => (string) $item->title,
                'item_description' => (string) ($item->description ?? ''),
                'priority' => (string) ($item->priority ?? ''),
                'criticality' => (string) ($item->criticality ?? ''),
                'status' => (string) ($item->status ?? 'Not Tested'),
                'tested_by' => (string) ($item->tester?->name ?? ''),
                'tested_at' => (string) ($item->tested_at ?? ''),
                'comments_count' => (int) $item->comments->count(),
                'comments_preview' => $commentPreview,
            ];
        }

        return [
            'report_metadata' => [
                'report_title' => 'Version Test Execution Report',
                'generated_at' => now()->toDateTimeString(),
                'report_scope' => 'Version Level',
                'format_version' => '1.0',
            ],
            'scope' => [
                'project_name' => $projectVersion->project?->name,
                'project_owner' => $projectVersion->project?->creator?->name,
                'version_number' => $projectVersion->version_number,
                'checklist_name' => $projectVersion->checklist?->name,
                'version_created_at' => (string) $projectVersion->created_at,
            ],
            'executive_summary' => $this->buildSummary($items),
            'status_breakdown' => $this->buildBreakdown($items, 'status'),
            'priority_breakdown' => $this->buildBreakdown($items, 'priority'),
            'criticality_breakdown' => $this->buildBreakdown($items, 'criticality'),
            'items_details' => $items,
        ];
    }

    private function buildSummary(array $items): array
    {
        $total = count($items);
        $passed = 0;
        $failed = 0;
        $blocked = 0;
        $notTested = 0;
        $comments = 0;
        $criticalFailed = 0;

        foreach ($items as $item) {
            $status = (string) ($item['status'] ?? 'Not Tested');
            $criticality = (string) ($item['criticality'] ?? '');
            $comments += (int) ($item['comments_count'] ?? 0);

            if ($status === 'Passed') {
                $passed++;
            } elseif ($status === 'Failed') {
                $failed++;
                if ($criticality === 'Critical') {
                    $criticalFailed++;
                }
            } elseif ($status === 'Blocked') {
                $blocked++;
            } else {
                $notTested++;
            }
        }

        $executed = $total - $notTested;

        return [
            'total_items' => $total,
            'executed_items' => $executed,
            'passed' => $passed,
            'failed' => $failed,
            'blocked' => $blocked,
            'not_tested' => $notTested,
            'pass_rate_percent' => $total > 0 ? round(($passed / $total) * 100, 2) : 0,
            'failure_rate_percent' => $total > 0 ? round(($failed / $total) * 100, 2) : 0,
            'execution_rate_percent' => $total > 0 ? round(($executed / $total) * 100, 2) : 0,
            'total_comments' => $comments,
            'critical_failed_items' => $criticalFailed,
        ];
    }

    private function buildBreakdown(array $items, string $field): array
    {
        $counts = [];
        foreach ($items as $item) {
            $key = (string) ($item[$field] ?? 'Unknown');
            if ($key === '') {
                $key = 'Unknown';
            }
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        ksort($counts);
        return $counts;
    }

    private function formatResponse(string $format, array $report, string $filenameBase)
    {
        $normalized = strtolower($format);

        if ($normalized === 'json') {
            return response()->json($report);
        }

        if ($normalized === 'csv') {
            return $this->csvResponse($report, $filenameBase . '.csv');
        }

        if ($normalized === 'xls') {
            return $this->xlsResponse($report, $filenameBase . '.xls');
        }

        if ($normalized === 'pdf') {
            return $this->pdfResponse($report, $filenameBase . '.pdf');
        }

        return response()->json([
            'message' => 'Unsupported export format. Use csv, pdf, xls, or json.',
        ], 422);
    }

    private function csvResponse(array $report, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($report) {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                return;
            }

            fputcsv($output, ['REPORT INFORMATION']);
            foreach (($report['report_metadata'] ?? []) as $key => $value) {
                fputcsv($output, [$key, (string) $value]);
            }

            fputcsv($output, []);
            fputcsv($output, ['SCOPE']);
            foreach (($report['scope'] ?? []) as $key => $value) {
                fputcsv($output, [$key, (string) $value]);
            }

            fputcsv($output, []);
            fputcsv($output, ['EXECUTIVE SUMMARY']);
            foreach (($report['executive_summary'] ?? []) as $key => $value) {
                fputcsv($output, [$key, (string) $value]);
            }

            $this->writeBreakdownCsv($output, 'STATUS BREAKDOWN', $report['status_breakdown'] ?? []);
            $this->writeBreakdownCsv($output, 'PRIORITY BREAKDOWN', $report['priority_breakdown'] ?? []);
            $this->writeBreakdownCsv($output, 'CRITICALITY BREAKDOWN', $report['criticality_breakdown'] ?? []);

            if (!empty($report['versions_summary'])) {
                fputcsv($output, []);
                fputcsv($output, ['VERSIONS SUMMARY']);
                fputcsv($output, array_keys($report['versions_summary'][0]));
                foreach ($report['versions_summary'] as $row) {
                    fputcsv($output, array_values($row));
                }
            }

            fputcsv($output, []);
            fputcsv($output, ['ITEM DETAILS']);
            $detailRows = $report['items_details'] ?? [];
            if (!empty($detailRows)) {
                fputcsv($output, array_keys($detailRows[0]));
                foreach ($detailRows as $row) {
                    fputcsv($output, array_values($row));
                }
            } else {
                fputcsv($output, ['No item details']);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function writeBreakdownCsv($output, string $title, array $rows): void
    {
        fputcsv($output, []);
        fputcsv($output, [$title]);
        fputcsv($output, ['category', 'count']);
        foreach ($rows as $key => $value) {
            fputcsv($output, [(string) $key, (string) $value]);
        }
    }

    private function xlsResponse(array $report, string $filename)
    {
        $html = '<html><body style="font-family: Arial, sans-serif">';
        $html .= '<h2>' . e((string) ($report['report_metadata']['report_title'] ?? 'Test Execution Report')) . '</h2>';
        $html .= '<p><strong>Generated At:</strong> ' . e((string) ($report['report_metadata']['generated_at'] ?? '')) . '</p>';

        $html .= $this->toHtmlKeyValueTable('Scope', $report['scope'] ?? []);
        $html .= $this->toHtmlKeyValueTable('Executive Summary', $report['executive_summary'] ?? []);
        $html .= $this->toHtmlBreakdownTable('Status Breakdown', $report['status_breakdown'] ?? []);
        $html .= $this->toHtmlBreakdownTable('Priority Breakdown', $report['priority_breakdown'] ?? []);
        $html .= $this->toHtmlBreakdownTable('Criticality Breakdown', $report['criticality_breakdown'] ?? []);

        if (!empty($report['versions_summary'])) {
            $html .= $this->toHtmlRowsTable('Versions Summary', $report['versions_summary']);
        }

        $html .= $this->toHtmlRowsTable('Item Details', $report['items_details'] ?? []);
        $html .= '</body></html>';

        return response($html, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename=' . $filename,
        ]);
    }

    private function toHtmlKeyValueTable(string $title, array $data): string
    {
        $html = '<h3>' . e($title) . '</h3>';
        $html .= '<table border="1" cellpadding="6" cellspacing="0">';
        foreach ($data as $key => $value) {
            $html .= '<tr><th align="left">' . e((string) $key) . '</th><td>' . e((string) $value) . '</td></tr>';
        }
        $html .= '</table>';

        return $html;
    }

    private function toHtmlBreakdownTable(string $title, array $breakdown): string
    {
        $rows = [];
        foreach ($breakdown as $category => $count) {
            $rows[] = [
                'category' => (string) $category,
                'count' => (string) $count,
            ];
        }

        return $this->toHtmlRowsTable($title, $rows);
    }

    private function toHtmlRowsTable(string $title, array $rows): string
    {
        $html = '<h3>' . e($title) . '</h3>';
        if (empty($rows)) {
            return $html . '<p>No data</p>';
        }

        $html .= '<table border="1" cellpadding="6" cellspacing="0"><thead><tr>';
        foreach (array_keys($rows[0]) as $header) {
            $html .= '<th>' . e((string) $header) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $value) {
                $html .= '<td>' . e((string) $value) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    private function pdfResponse(array $report, string $filename)
    {
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->loadHtml($this->buildPdfHtml($report));
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename=' . $filename,
        ]);
    }

    private function buildPdfHtml(array $report): string
    {
        $details = array_slice($report['items_details'] ?? [], 0, 300);

        $html = '<html><head><meta charset="UTF-8"><style>'
            . 'body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#111827;}'
            . 'h1{font-size:18px;margin:0 0 8px 0;}h2{font-size:14px;margin:18px 0 8px 0;}'
            . 'table{width:100%;border-collapse:collapse;margin-bottom:12px;}'
            . 'th,td{border:1px solid #d1d5db;padding:6px;text-align:left;vertical-align:top;}'
            . 'th{background:#f3f4f6;font-weight:600;}'
            . '.muted{color:#6b7280;font-size:10px;}'
            . '</style></head><body>';

        $html .= '<h1>' . e((string) ($report['report_metadata']['report_title'] ?? 'Test Execution Report')) . '</h1>';
        $html .= '<div class="muted">Generated at: ' . e((string) ($report['report_metadata']['generated_at'] ?? '')) . '</div>';

        $html .= $this->toHtmlKeyValueTable('Scope', $report['scope'] ?? []);
        $html .= $this->toHtmlKeyValueTable('Executive Summary', $report['executive_summary'] ?? []);
        $html .= $this->toHtmlBreakdownTable('Status Breakdown', $report['status_breakdown'] ?? []);
        $html .= $this->toHtmlBreakdownTable('Priority Breakdown', $report['priority_breakdown'] ?? []);
        $html .= $this->toHtmlBreakdownTable('Criticality Breakdown', $report['criticality_breakdown'] ?? []);

        if (!empty($report['versions_summary'])) {
            $html .= $this->toHtmlRowsTable('Versions Summary', $report['versions_summary']);
        }

        $html .= $this->toHtmlRowsTable('Item Details (first 300 rows)', $details);
        $html .= '</body></html>';

        return $html;
    }
}
