{{--
  ROUTES CONFIGURATION
  File Location: routes/api.php OR routes/web.php
--}}

{{-- 
  Add this route to your api.php or web.php file
  This endpoint receives test results from the Vue component
  and updates test case statuses in the database
--}}

// POST /api/update-test-results
// Handles test result updates from the Vue component
Route::post('/api/update-test-results', 'TestResultController@updateResults')
    ->middleware('auth') // Add authentication if needed
    ->name('api.update-test-results');

// Optional: Health check route to verify test agent connectivity
Route::get('/api/test-agent-health', 'TestResultController@checkHealth')
    ->name('api.test-agent-health');


{{--
  ===================================================
  CONTROLLER CODE
  File Location: app/Http/Controllers/TestResultController.php
  ===================================================
--}}

<?php

namespace App\Http\Controllers;

use App\Models\Checklist;
use App\Models\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class TestResultController extends Controller
{
    /**
     * Update test results in database
     * 
     * Receives results from Vue component after tests complete
     * Updates test case statuses and last_run_at timestamps
     * 
     * @param Request $request JSON payload with test results
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateResults(Request $request)
    {
        try {
            // Validate input
            $validated = $request->validate([
                'checklist_id' => 'required|integer|exists:checklists,id',
                'results' => 'required|array',
                'results.passed' => 'required|integer|min:0',
                'results.failed' => 'required|integer|min:0',
                'results.tests' => 'required|array',
                'results.tests.*.id' => 'required|integer',
                'results.tests.*.status' => 'required|in:passed,failed,pending',
                'results.tests.*.title' => 'required|string',
            ]);

            $checklistId = $validated['checklist_id'];
            $results = $validated['results'];
            $now = Carbon::now();

            // Find the checklist
            $checklist = Checklist::findOrFail($checklistId);

            // Update each test case
            $updatedCount = 0;
            foreach ($results['tests'] as $testResult) {
                $testCase = TestCase::where('id', $testResult['id'])
                    ->where('checklist_id', $checklistId)
                    ->first();

                if ($testCase) {
                    // Update test case
                    $testCase->update([
                        'status' => $testResult['status'],
                        'last_run_at' => $now,
                        'run_count' => ($testCase->run_count ?? 0) + 1,
                    ]);

                    $updatedCount++;
                }
            }

            // Optional: Update checklist summary
            $this->updateChecklistSummary($checklist);

            \Log::info("Test results updated for checklist {$checklistId}", [
                'updated_count' => $updatedCount,
                'passed' => $results['passed'],
                'failed' => $results['failed'],
                'timestamp' => $now,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Test results updated successfully ({$updatedCount} test cases)",
                'updated_count' => $updatedCount,
                'timestamp' => $now->toIso8601String(),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::warning('Test results validation failed', [
                'errors' => $e->errors(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'details' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            \Log::error('Test results update error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage() ?: 'Failed to update test results',
            ], 500);
        }
    }

    /**
     * Check test agent API health
     * Verifies that the test agent is running and accessible
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkHealth()
    {
        try {
            $testAgentUrl = env('VITE_TEST_AGENT_URL', 'http://localhost:8000');

            $response = Http::timeout(5)->get("{$testAgentUrl}/health");

            if ($response->ok()) {
                return response()->json([
                    'healthy' => true,
                    'api_url' => $testAgentUrl,
                    'status' => $response->json('status'),
                    'timestamp' => now()->toIso8601String(),
                ]);
            }

            return response()->json([
                'healthy' => false,
                'api_url' => $testAgentUrl,
                'error' => "HTTP {$response->status()}",
            ], 503);
        } catch (Exception $e) {
            \Log::error('Test agent health check failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'healthy' => false,
                'error' => 'Cannot reach test agent. Is it running?',
                'details' => $e->getMessage(),
            ], 503);
        }
    }

    /**
     * Update checklist summary statistics
     * Calculates and stores pass/fail counts for the checklist
     * 
     * @param Checklist $checklist
     * @return void
     */
    private function updateChecklistSummary(Checklist $checklist)
    {
        try {
            $stats = $checklist->testCases()
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status');

            // Optional: Add summary columns to checklist if they exist
            if (schema()->hasColumn('checklists', 'tests_passed')) {
                $checklist->update([
                    'tests_passed' => $stats['passed'] ?? 0,
                    'tests_failed' => $stats['failed'] ?? 0,
                    'tests_pending' => $stats['pending'] ?? 0,
                    'last_test_run_at' => now(),
                ]);
            }
        } catch (Exception $e) {
            \Log::debug('Could not update checklist summary', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
