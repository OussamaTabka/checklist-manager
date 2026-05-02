<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        abort_unless($user, 401, 'Unauthenticated');

        $notifications = $user->notifications()
            ->latest()
            ->limit(12)
            ->get()
            ->map(fn ($notification) => [
                'id' => $notification->id,
                'type' => class_basename($notification->type),
                'data' => $notification->data,
                'read_at' => optional($notification->read_at)?->toIso8601String(),
                'created_at' => optional($notification->created_at)?->toIso8601String(),
            ]);

        return response()->json([
            'items' => $notifications,
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();

        abort_unless($user, 401, 'Unauthenticated');

        $user->unreadNotifications->markAsRead();

        return response()->json([
            'message' => 'Notifications marked as read.',
            'unread_count' => 0,
        ]);
    }
}
