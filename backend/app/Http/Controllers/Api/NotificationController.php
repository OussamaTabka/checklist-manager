<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401, 'Unauthenticated');
        abort_unless($user->hasRole('chef') || $user->hasRole('testeur'), 403, 'Notifications are not available for admin users.');

        $data = $request->validate([
            'filter' => ['nullable', 'in:all,unread,projects,tests,comments,system,archived'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $filter = $data['filter'] ?? 'all';
        $limit = $data['limit'] ?? 20;

        $query = $user->roleNotifications()->limit($limit);
        $query = $this->applyFilter($query, $filter);
        $notifications = $query->get()->map(fn (Notification $notification) => $this->serialize($notification));

        return response()->json([
            'items' => $notifications,
            'unread_count' => $user->roleNotifications()->where('is_read', false)->where('is_archived', false)->count(),
        ]);
    }

    public function unreadCount(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401, 'Unauthenticated');
        abort_unless($user->hasRole('chef') || $user->hasRole('testeur'), 403, 'Notifications are not available for admin users.');

        return response()->json([
            'unread_count' => $user->roleNotifications()
                ->where('is_read', false)
                ->where('is_archived', false)
                ->count(),
        ]);
    }

    public function markRead(Request $request, string $id)
    {
        $user = $request->user();
        abort_unless($user, 401, 'Unauthenticated');
        abort_unless($user->hasRole('chef') || $user->hasRole('testeur'), 403, 'Notifications are not available for admin users.');

        $notification = $user->roleNotifications()->whereKey($id)->firstOrFail();
        $this->markNotificationRead($notification);

        return response()->json([
            'message' => 'Notification marquée comme lue.',
            'item' => $this->serialize($notification->fresh()),
        ]);
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401, 'Unauthenticated');
        abort_unless($user->hasRole('chef') || $user->hasRole('testeur'), 403, 'Notifications are not available for admin users.');

        $user->roleNotifications()
            ->where('is_read', false)
            ->where('is_archived', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return response()->json([
            'message' => 'Toutes les notifications ont été marquées comme lues.',
            'unread_count' => 0,
        ]);
    }

    public function archive(Request $request, string $id)
    {
        $user = $request->user();
        abort_unless($user, 401, 'Unauthenticated');
        abort_unless($user->hasRole('chef') || $user->hasRole('testeur'), 403, 'Notifications are not available for admin users.');

        $notification = $user->roleNotifications()->whereKey($id)->firstOrFail();

        if (!$notification->is_read) {
            $this->markNotificationRead($notification);
        }

        $notification->update([
            'is_archived' => true,
        ]);

        return response()->json([
            'message' => 'Notification archivée.',
            'item' => $this->serialize($notification->fresh()),
        ]);
    }

    private function applyFilter($query, string $filter)
    {
        return match ($filter) {
            'unread' => $query->where('is_archived', false)->where('is_read', false),
            'projects' => $query->where('is_archived', false)->whereIn('type', NotificationService::PROJECT_TYPES),
            'tests' => $query->where('is_archived', false)->whereIn('type', NotificationService::TEST_TYPES),
            'comments' => $query->where('is_archived', false)->whereIn('type', NotificationService::COMMENT_TYPES),
            'system' => $query->where('is_archived', false)->whereIn('type', NotificationService::SYSTEM_TYPES),
            'archived' => $query->where('is_archived', true),
            default => $query->where('is_archived', false),
        };
    }

    private function markNotificationRead(Notification $notification): void
    {
        $notification->update([
            'is_read' => true,
            'read_at' => $notification->read_at ?? now(),
        ]);
    }

    private function serialize(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'role' => $notification->role,
            'type' => $notification->type,
            'title' => $notification->title,
            'message' => $notification->message,
            'priority' => $notification->priority,
            'link' => $notification->link,
            'target_type' => $notification->target_type,
            'target_id' => $notification->target_id,
            'project_id' => $notification->project_id,
            'version_id' => $notification->version_id,
            'checklist_id' => $notification->checklist_id,
            'test_case_id' => $notification->test_case_id,
            'comment_id' => $notification->comment_id,
            'section' => $notification->section,
            'category' => NotificationService::categoryForType($notification->type),
            'is_read' => $notification->is_read,
            'is_archived' => $notification->is_archived,
            'read_at' => optional($notification->read_at)?->toIso8601String(),
            'created_at' => optional($notification->created_at)?->toIso8601String(),
            'updated_at' => optional($notification->updated_at)?->toIso8601String(),
        ];
    }
}
