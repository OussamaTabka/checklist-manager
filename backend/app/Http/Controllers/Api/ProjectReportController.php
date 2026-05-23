<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\ProjectReportService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProjectReportController extends Controller
{
    public function __construct(
        private readonly ProjectReportService $projectReportService
    ) {
    }

    public function export(Request $request, Project $project)
    {
        $this->authorize('exportReport', $project);

        $data = $request->validate([
            'format' => ['required', 'string', 'in:pdf,json,xlsx'],
            'type' => ['required', 'string', 'in:summary,detailed'],
            'include_user_stories' => ['nullable', 'boolean'],
            'include_checklists' => ['nullable', 'boolean'],
            'include_execution_results' => ['nullable', 'boolean'],
            'include_failed_blocked' => ['nullable', 'boolean'],
            'include_comments' => ['nullable', 'boolean'],
            'include_history' => ['nullable', 'boolean'],
            'include_automation_traces' => ['nullable', 'boolean'],
        ]);

        $format = strtolower((string) $data['format']);
        $type = strtolower((string) $data['type']);

        if ($format === 'pdf' && $type !== 'summary') {
            return response()->json([
                'message' => 'Le PDF detaille n\'est pas disponible pour le moment.',
            ], 422);
        }

        if ($format === 'json' && $type !== 'detailed') {
            return response()->json([
                'message' => 'Le format JSON supporte uniquement le type detailed.',
            ], 422);
        }

        if ($format === 'xlsx') {
            return response()->json([
                'message' => 'Le format Excel n\'est pas encore disponible.',
            ], 501);
        }

        $report = $this->projectReportService->build(
            $project,
            $request->user(),
            $format,
            $type,
            $data
        );

        $filenameBase = Str::slug($project->name ?: 'project-report') . '-report-' . now()->format('Ymd-His');

        return match ($format) {
            'pdf' => $this->pdfResponse($report, $filenameBase . '.pdf'),
            'json' => $this->jsonDownloadResponse($report, $filenameBase . '.json'),
            default => response()->json(['message' => 'Unsupported report format.'], 422),
        };
    }

    private function jsonDownloadResponse(array $report, string $filename)
    {
        return response(
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            200,
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]
        );
    }

    private function pdfResponse(array $report, string $filename)
    {
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml(view('reports.project-summary-pdf', ['report' => $report])->render());
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
