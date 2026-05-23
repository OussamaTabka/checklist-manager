<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\VersionItem;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService
    ) {
    }

    public function index(VersionItem $versionItem)
    {
        $this->authorizeVersionItemAccess($versionItem);

        $comments = $versionItem->comments()->with('user')->orderBy('created_at', 'desc')->get();
        return response()->json($comments);
    }

    public function store(Request $request, VersionItem $versionItem)
    {
        $this->authorizeVersionItemAccess($versionItem);

        $validated = $request->validate([
            'content' => 'required|string|min:1',
            'file' => 'nullable|file|max:10240',
        ]);

        $commentData = [
            'user_id' => auth()->id(),
            'content' => $validated['content'],
        ];

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store('comments', 'local');

            $commentData['file_path'] = $path;
            $commentData['file_name'] = $file->getClientOriginalName();
            $commentData['file_size'] = $file->getSize();
        }

        $comment = $versionItem->comments()->create($commentData);

        $comment->load('user');
        $this->notificationService->notifyCommentAdded($comment);

        return response()->json($comment, 201);
    }

    public function update(Request $request, Comment $comment)
    {
        $comment->loadMissing('versionItem.version.project');
        $this->authorizeVersionItemAccess($comment->versionItem);

        if ($comment->user_id !== auth()->id() && !auth()->user()->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'content' => 'required|string|min:1',
        ]);

        $comment->update($validated);
        return response()->json($comment);
    }

    public function destroy(Comment $comment)
    {
        $comment->loadMissing('versionItem.version.project');
        $this->authorizeVersionItemAccess($comment->versionItem);

        if ($comment->user_id !== auth()->id() && !auth()->user()->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $comment->delete();
        return response()->json(null, 204);
    }

    private function authorizeVersionItemAccess(?VersionItem $versionItem): void
    {
        abort_unless($versionItem, 404, 'Version item not found.');

        $versionItem->loadMissing('version.project');
        $project = $versionItem->version?->project;
        abort_unless($project, 404, 'Project not found for this version item.');

        $this->authorize('view', $project);
    }
}
