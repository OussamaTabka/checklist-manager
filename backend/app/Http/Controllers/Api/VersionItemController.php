<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VersionItem;
use App\Models\ItemChange;
use App\Models\TestRun;
use Illuminate\Http\Request;

class VersionItemController extends Controller
{
    public function updateStatus(Request $request, VersionItem $versionItem)
    {
        $data = $request->validate([
            'status' => ['required', 'in:Not Tested,Passed,Failed,Blocked'],
        ]);

        $oldStatus = $versionItem->status;

        if ($data['status'] === 'Not Tested') {
            $versionItem->update([
                'status' => 'Not Tested',
                'tested_by' => null,
                'tested_at' => null,
            ]);
        } else {
            $versionItem->update([
                'status' => $data['status'],
                'tested_by' => auth()->id(),
                'tested_at' => now(),
            ]);
        }

        // Record the change for traceability
        ItemChange::create([
            'version_item_id' => $versionItem->id,
            'changed_by' => auth()->id(),
            'field_name' => 'status',
            'old_value' => $oldStatus,
            'new_value' => $data['status'],
            'change_type' => 'status_changed',
        ]);

        return response()->json([
            'message' => 'Status updated',
            'item' => $versionItem->fresh(), // pour renvoyer les valeurs mises à jour
        ]);
    }

    /**
     * Get comprehensive change history for a specific item
     * Returns unified timeline: changes, comments, and testing info
     */
    public function getHistory(VersionItem $versionItem)
    {
        // Get all changes with user info
        $changes = $versionItem->changes()
            ->with('changedBy:id,name,email')
            ->get()
            ->map(function ($change) {
                return [
                    'id' => 'change-' . $change->id,
                    'type' => 'change',
                    'timestamp' => $change->created_at,
                    'user' => $change->changedBy,
                    'user_name' => $change->changedBy?->name,
                    'title' => ucfirst($change->field_name) . ' changed',
                    'field_name' => $change->field_name,
                    'old_value' => $change->old_value,
                    'new_value' => $change->new_value,
                    'change_type' => $change->change_type,
                ];
            });

        // Get all comments with file info
        $comments = $versionItem->comments()
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($comment) {
                return [
                    'id' => 'comment-' . $comment->id,
                    'type' => 'comment',
                    'timestamp' => $comment->created_at,
                    'user' => $comment->user,
                    'user_name' => $comment->user?->name,
                    'content' => $comment->content,
                    'file_path' => $comment->file_path,
                    'file_name' => $comment->file_name,
                    'file_size' => $comment->file_size,
                ];
            });

        // Get failed execution events from persisted test results
        $failedExecutions = $versionItem->testResults()
            ->with(['testRun:id,run_id,status'])
            ->where('status', 'failed')
            ->orderByDesc('executed_at')
            ->orderByDesc('id')
            ->get()
            ->map(function ($result) {
                return [
                    'id' => 'execution-' . $result->id,
                    'type' => 'execution',
                    'timestamp' => $result->executed_at ?? $result->created_at,
                    'title' => 'Test execution failed',
                    'status' => $result->status,
                    'run_id' => $result->testRun?->run_id,
                    'error_type' => $result->error_type,
                    'error_message' => $result->error_message ?: 'Execution failed without an explicit error message.',
                    'duration_ms' => $result->duration_ms,
                ];
            });

        // Capture failed runs that have payload errors but no failed test_result row yet
        $failedExecutionRunIds = $failedExecutions
            ->pluck('run_id')
            ->filter(static fn ($runId) => is_string($runId) && $runId !== '')
            ->values()
            ->all();

        $failedRunPayloadErrors = TestRun::query()
            ->where('request_payload->test_case_id', $versionItem->id)
            ->where('status', 'failed')
            ->with('requester:id,name,email')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->get()
            ->filter(function ($run) use ($failedExecutionRunIds) {
                if (in_array($run->run_id, $failedExecutionRunIds, true)) {
                    return false;
                }

                $payload = is_array($run->request_payload) ? $run->request_payload : [];
                return isset($payload['last_error']) && is_string($payload['last_error']) && trim($payload['last_error']) !== '';
            })
            ->map(function ($run) {
                $payload = is_array($run->request_payload) ? $run->request_payload : [];

                return [
                    'id' => 'execution-run-' . $run->id,
                    'type' => 'execution',
                    'timestamp' => $run->finished_at ?? $run->updated_at ?? $run->created_at,
                    'title' => 'Test execution failed',
                    'status' => 'failed',
                    'run_id' => $run->run_id,
                    'user' => $run->requester,
                    'user_name' => $run->requester?->name,
                    'error_type' => 'runtime_error',
                    'error_message' => (string) $payload['last_error'],
                    'duration_ms' => null,
                ];
            });

        // Add testing info if tested
        $timeline = collect();

        if ($versionItem->tested_at && $versionItem->tested_by) {
            $tester = $versionItem->tester;
            $timeline->push([
                'id' => 'test-' . $versionItem->id . '-' . $versionItem->tested_by,
                'type' => 'test',
                'timestamp' => $versionItem->tested_at,
                'user' => $tester,
                'user_name' => $tester?->name,
                'status' => $versionItem->status,
                'title' => 'Item tested: ' . $versionItem->status,
            ]);
        }

        // Merge all timeline events
        $timeline = $timeline
            ->merge($changes)
            ->merge($comments)
            ->merge($failedExecutions)
            ->merge($failedRunPayloadErrors)
            ->sortByDesc('timestamp')
            ->values()
            ->toArray();

        return response()->json([
            'item_id' => $versionItem->id,
            'item_title' => $versionItem->title,
            'timeline' => $timeline,
            'total_events' => count($timeline),
            'tested_by' => $versionItem->tester ? [
                'id' => $versionItem->tester->id,
                'name' => $versionItem->tester->name,
                'email' => $versionItem->tester->email,
            ] : null,
            'tested_at' => $versionItem->tested_at,
            'current_status' => $versionItem->status,
        ]);
    }
}