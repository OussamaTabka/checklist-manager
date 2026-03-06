<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\VersionItem;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function index(VersionItem $versionItem)
    {
        $comments = $versionItem->comments()->with('user')->orderBy('created_at', 'desc')->get();
        return response()->json($comments);
    }

    public function store(Request $request, VersionItem $versionItem)
    {
        $validated = $request->validate([
            'content' => 'required|string|min:1'
        ]);

        $comment = $versionItem->comments()->create([
            'user_id' => auth()->id(),
            'content' => $validated['content']
        ]);

        $comment->load('user');
        return response()->json($comment, 201);
    }

    public function update(Request $request, Comment $comment)
    {
        // Vérifier que l'utilisateur est propriétaire ou admin
        if ($comment->user_id !== auth()->id() && !auth()->user()->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'content' => 'required|string|min:1'
        ]);

        $comment->update($validated);
        return response()->json($comment);
    }

    public function destroy(Comment $comment)
    {
        // Vérifier que l'utilisateur est propriétaire ou admin
        if ($comment->user_id !== auth()->id() && !auth()->user()->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $comment->delete();
        return response()->json(null, 204);
    }
}
