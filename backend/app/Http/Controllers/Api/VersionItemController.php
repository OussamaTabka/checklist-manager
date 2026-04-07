<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VersionItem;
use App\Models\ItemChange;
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