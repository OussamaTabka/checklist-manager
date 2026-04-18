<?php

namespace App\Http\Controllers;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class TestResultController extends Controller
{
    /**
     * Update test results for a checklist
     * Called after tests are executed by the external AI Test Agent
     *
     * Expected payload:
     * {
     *   "checklist_id": 1,
     *   "results": {
     *     "passed": 5,
     *     "failed": 2,
     *     "total": 7,
     *     "tests": [
     *       { "title": "Test 1", "status": "passed" },
     *       { "title": "Test 2", "status": "failed", "error": "..." }
     *     ]
     *   }
     * }
     */
    public function updateResults(Request $request)
    {
        try {
            $validated = $request->validate([
                'checklist_id' => ['required', 'integer'],
                'results' => ['required', 'array'],
                'results.passed' => ['nullable', 'integer', 'min:0'],
                'results.failed' => ['nullable', 'integer', 'min:0'],
                'results.total' => ['nullable', 'integer', 'min:0'],
                'results.tests' => ['nullable', 'array'],
                'results.tests.*.id' => ['nullable', 'integer'],
                'results.tests.*.title' => ['nullable', 'string', 'max:255'],
                'results.tests.*.status' => ['required_with:results.tests', 'string', 'in:pending,passed,failed'],
                'results.tests.*.error' => ['nullable', 'string'],
                'results.timestamp' => ['nullable', 'date'],
            ]);

            $checklistId = $validated['checklist_id'];
            $results = $validated['results'];

            $checklist = Checklist::query()->find($checklistId);
            if (!$checklist) {
                return response()->json([
                    'success' => false,
                    'error' => 'Checklist not found',
                ], 404);
            }

            $items = ChecklistItem::query()->where('checklist_id', $checklistId)->get();

            if ($items->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'error' => 'No test cases found for this checklist',
                    'code' => 'NO_TEST_CASES',
                ], 404);
            }

            $now = now();
            $updatedIds = [];
            $unmatchedTests = [];

            DB::beginTransaction();

            if (!empty($results['tests'])) {
                foreach ($results['tests'] as $testResult) {
                    $item = null;

                    if (!empty($testResult['id'])) {
                        $item = $items->firstWhere('id', (int) $testResult['id']);
                    }

                    if (!$item && !empty($testResult['title'])) {
                        $title = trim((string) $testResult['title']);
                        $item = $items->first(function (ChecklistItem $candidate) use ($title) {
                            return strcasecmp((string) $candidate->title, $title) === 0;
                        });
                    }

                    if ($item) {
                        $incomingStatus = (string) ($testResult['status'] ?? 'failed');
                        $status = in_array($incomingStatus, ['pending', 'passed', 'failed'], true)
                            ? $incomingStatus
                            : 'failed';

                        $item->update([
                            'status' => $status,
                            'last_run_at' => $now,
                            'run_count' => ($item->run_count ?? 0) + 1,
                        ]);

                        $updatedIds[] = $item->id;

                        Log::info('Test result updated', [
                            'item_id' => $item->id,
                            'title' => $item->title,
                            'status' => $item->status,
                        ]);
                    } else {
                        $unmatchedTests[] = [
                            'id' => $testResult['id'] ?? null,
                            'title' => $testResult['title'] ?? null,
                        ];
                    }
                }
            } else {
                $passedTarget = (int) ($results['passed'] ?? 0);

                foreach ($items->values() as $index => $item) {
                    $status = $index < $passedTarget ? 'passed' : 'failed';

                    $item->update([
                        'status' => $status,
                        'last_run_at' => $now,
                        'run_count' => ($item->run_count ?? 0) + 1,
                    ]);

                    $updatedIds[] = $item->id;
                }
            }

            DB::commit();

            $updatedCount = count(array_unique($updatedIds));

            return response()->json([
                'success' => true,
                'message' => 'Test results updated',
                'data' => [
                    'checklist_id' => $checklistId,
                    'items_updated' => $updatedCount,
                    'unmatched_tests' => $unmatchedTests,
                    'results' => [
                        'passed' => $results['passed'] ?? 0,
                        'failed' => $results['failed'] ?? 0,
                        'total' => $results['total'] ?? 0,
                    ],
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::warning('Validation error in updateResults', [
                'errors' => $e->errors(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Validation error',
                'code' => 'VALIDATION_ERROR',
                'details' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::error('Unexpected error in updateResults', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Server error: ' . $e->getMessage(),
                'code' => 'SERVER_ERROR',
            ], 500);
        }
    }

    /**
     * Check if test agent API is healthy
     * This is called before running tests to ensure the external service is available
     */
    public function checkHealth()
    {
        try {
            $testAgentUrl = config('services.test_agent.url', 'http://localhost:8000');

            $response = @file_get_contents("{$testAgentUrl}/health", false);

            if ($response === false) {
                return response()->json([
                    'status' => 'unhealthy',
                    'message' => 'Test Agent API is not responding',
                    'url' => $testAgentUrl,
                ], 503);
            }

            $data = json_decode($response, true);

            return response()->json([
                'status' => $data['status'] ?? 'unknown',
                'healthy' => ($data['status'] ?? null) === 'healthy',
                'url' => $testAgentUrl,
            ]);
        } catch (\Exception $e) {
            Log::error('Health check error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
