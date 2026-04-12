<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ItemChange;
use App\Models\ProjectVersion;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\VersionItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class TestRunController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'schema_version' => ['nullable', 'string', 'in:1.0'],
            'project_version_id' => ['required', 'integer', 'exists:project_versions,id'],
            'base_url' => ['required', 'url', 'max:2048'],
            'mode' => ['required', 'string', 'max:50'],
            'cases' => ['nullable', 'array'],
            'cases.*.external_id' => ['required_with:cases', 'integer', 'exists:version_items,id'],
            'cases.*.title' => ['required_with:cases', 'string'],
            'cases.*.steps' => ['required_with:cases', 'array'],
            'cases.*.asserts' => ['required_with:cases', 'array'],
        ]);

        $projectVersion = ProjectVersion::with('items:id,project_version_id')->findOrFail($data['project_version_id']);

        if (!empty($data['cases'])) {
            $allowedIds = $projectVersion->items->pluck('id')->all();
            foreach ($data['cases'] as $case) {
                if (!in_array((int) $case['external_id'], $allowedIds, true)) {
                    throw ValidationException::withMessages([
                        'cases' => ["external_id {$case['external_id']} does not belong to project_version_id {$projectVersion->id}."],
                    ]);
                }
            }
        }

        $runId = (string) Str::uuid();

        $testRun = TestRun::create([
            'run_id' => $runId,
            'project_version_id' => $projectVersion->id,
            'schema_version' => $data['schema_version'] ?? '1.0',
            'base_url' => $data['base_url'],
            'mode' => $data['mode'],
            'status' => 'created',
            'requested_by' => Auth::id(),
            'summary_total' => count($data['cases'] ?? []),
            'request_payload' => [
                'schema_version' => $data['schema_version'] ?? '1.0',
                'base_url' => $data['base_url'],
                'cases' => $data['cases'] ?? [],
            ],
        ]);

        return response()->json([
            'run_id' => $testRun->run_id,
            'status' => $testRun->status,
            'schema_version' => $testRun->schema_version,
            'project_version_id' => $testRun->project_version_id,
            'base_url' => $testRun->base_url,
            'mode' => $testRun->mode,
            'summary' => [
                'total' => $testRun->summary_total,
                'passed' => 0,
                'failed' => 0,
                'blocked' => 0,
                'skipped' => 0,
            ],
        ], 201);
    }

    public function storeResults(Request $request, string $run_id)
    {
        $testRun = $this->resolveRun($run_id);

        $data = $request->validate([
            'results' => ['required', 'array', 'min:1'],
            'results.*.external_id' => ['required', 'integer', 'exists:version_items,id'],
            'results.*.status' => ['required', 'string', 'in:passed,failed,blocked,skipped'],
            'results.*.error_type' => ['nullable', 'string', 'max:100'],
            'results.*.error_message' => ['nullable', 'string'],
            'results.*.duration_ms' => ['nullable', 'integer', 'min:0'],
            'results.*.artifacts' => ['nullable', 'array'],
            'results.*.artifacts.trace' => ['nullable', 'array'],
            'results.*.artifacts.screenshot' => ['nullable', 'array'],
            'results.*.artifacts.video' => ['nullable', 'array'],
        ]);

        $statusMap = [
            'passed' => 'Passed',
            'failed' => 'Failed',
            'blocked' => 'Blocked',
            'skipped' => 'Not Tested',
        ];

        DB::transaction(function () use ($data, $testRun, $statusMap) {
            if (!$testRun->started_at) {
                $testRun->update([
                    'status' => 'running',
                    'started_at' => now(),
                ]);
            }

            foreach ($data['results'] as $result) {
                $versionItem = VersionItem::findOrFail($result['external_id']);

                if ((int) $versionItem->project_version_id !== (int) $testRun->project_version_id) {
                    throw ValidationException::withMessages([
                        'results' => [
                            "external_id {$result['external_id']} does not belong to run {$testRun->run_id} project_version.",
                        ],
                    ]);
                }

                $oldStatus = $versionItem->status;
                $mappedStatus = $statusMap[$result['status']];

                if ($mappedStatus === 'Not Tested') {
                    $versionItem->update([
                        'status' => $mappedStatus,
                        'tested_by' => null,
                        'tested_at' => null,
                    ]);
                } else {
                    $versionItem->update([
                        'status' => $mappedStatus,
                        'tested_by' => Auth::id(),
                        'tested_at' => now(),
                    ]);
                }

                if ($oldStatus !== $mappedStatus) {
                    ItemChange::create([
                        'version_item_id' => $versionItem->id,
                        'changed_by' => Auth::id(),
                        'field_name' => 'status',
                        'old_value' => $oldStatus,
                        'new_value' => $mappedStatus,
                        'change_type' => 'status_changed',
                        'notes' => 'Updated from test run ' . $testRun->run_id,
                    ]);
                }

                TestResult::updateOrCreate(
                    [
                        'test_run_id' => $testRun->id,
                        'version_item_id' => $versionItem->id,
                    ],
                    [
                        'status' => $result['status'],
                        'error_type' => $result['error_type'] ?? null,
                        'error_message' => $result['error_message'] ?? null,
                        'duration_ms' => $result['duration_ms'] ?? null,
                        'artifacts' => $result['artifacts'] ?? null,
                        'result_payload' => $result,
                        'executed_at' => now(),
                    ]
                );
            }

            $summary = [
                'total' => $testRun->results()->count(),
                'passed' => $testRun->results()->where('status', 'passed')->count(),
                'failed' => $testRun->results()->where('status', 'failed')->count(),
                'blocked' => $testRun->results()->where('status', 'blocked')->count(),
                'skipped' => $testRun->results()->where('status', 'skipped')->count(),
            ];

            $testRun->update([
                'status' => 'completed',
                'finished_at' => now(),
                'summary_total' => $summary['total'],
                'summary_passed' => $summary['passed'],
                'summary_failed' => $summary['failed'],
                'summary_blocked' => $summary['blocked'],
                'summary_skipped' => $summary['skipped'],
            ]);
        });

        return response()->json($this->buildRunResponse($testRun->fresh('results')));
    }

    public function show(string $run_id)
    {
        $testRun = $this->resolveRun($run_id);

        return response()->json($this->buildRunResponse($testRun->load(['results.versionItem:id,title'])));
    }

    private function resolveRun(string $run_id): TestRun
    {
        $testRun = TestRun::where('run_id', $run_id)->first();

        if (!$testRun) {
            throw new ModelNotFoundException("No query results for model [TestRun] {$run_id}");
        }

        return $testRun;
    }

    private function buildRunResponse(TestRun $testRun): array
    {
        return [
            'run_id' => $testRun->run_id,
            'status' => $testRun->status,
            'schema_version' => $testRun->schema_version,
            'project_version_id' => $testRun->project_version_id,
            'base_url' => $testRun->base_url,
            'mode' => $testRun->mode,
            'started_at' => optional($testRun->started_at)->toISOString(),
            'finished_at' => optional($testRun->finished_at)->toISOString(),
            'summary' => [
                'total' => $testRun->summary_total,
                'passed' => $testRun->summary_passed,
                'failed' => $testRun->summary_failed,
                'blocked' => $testRun->summary_blocked,
                'skipped' => $testRun->summary_skipped,
            ],
            'results' => $testRun->results->map(function (TestResult $result) {
                return [
                    'external_id' => $result->version_item_id,
                    'status' => $result->status,
                    'error_type' => $result->error_type,
                    'error_message' => $result->error_message,
                    'duration_ms' => $result->duration_ms,
                    'artifacts' => $result->artifacts ?? [
                        'trace' => [],
                        'screenshot' => [],
                        'video' => [],
                    ],
                ];
            })->values(),
        ];
    }
}
