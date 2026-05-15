<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\TestRun;
use App\Models\User;
use Illuminate\Support\Collection;

class NotificationService
{
    private const LOW_PROGRESS_THRESHOLD = 50;

    public const PROJECT_TYPES = [
        'project_assigned',
        'project_created',
    ];

    public const TEST_TYPES = [
        'automated_test_completed',
        'pending_tests_reminder',
        'tester_execution_completed',
        'project_pending_tests',
        'project_low_progress',
        'system_execution_error',
    ];

    public const COMMENT_TYPES = [
        'chef_comment_added',
        'tester_comment_added',
    ];

    public const SYSTEM_TYPES = [
        'user_created',
        'user_role_changed',
        'system_execution_error',
    ];

    public function notifyProjectAssigned(Project $project, iterable $testers, ?User $actor = null): void
    {
        $project->loadMissing('testers:id');

        foreach ($this->toUsersCollection($testers) as $tester) {
            if (!$tester->hasRole('testeur')) {
                continue;
            }

            if (!$project->testers->contains('id', $tester->id)) {
                continue;
            }

            $this->createNotification($tester, [
                'type' => 'project_assigned',
                'title' => 'Nouveau projet assigné',
                'message' => 'Vous avez été assigné à un nouveau projet.',
                'priority' => 'medium',
                'link' => sprintf('/projects/%d', $project->id),
                'target_type' => 'project',
                'target_id' => $project->id,
                'project_id' => $project->id,
                'section' => null,
            ]);
        }
    }

    public function notifyProjectCreated(Project $project, ?User $actor = null): void
    {
        // Notifications are reserved for chefs and testers only.
    }

    public function notifyUserCreated(User $user, ?User $actor = null): void
    {
        // Admin users no longer receive notifications.
    }

    public function notifyUserRoleChanged(User $user, string $oldRole, string $newRole, ?User $actor = null): void
    {
        if ($oldRole === $newRole) {
            return;
        }
    }

    public function notifyCommentAdded(Comment $comment): void
    {
        $comment->loadMissing([
            'user.roles',
            'versionItem.version.project.creator.roles',
            'versionItem.version.project.testers.roles',
        ]);

        $author = $comment->user;
        $versionItem = $comment->versionItem;
        $version = $versionItem?->version;
        $project = $version?->project;

        if (!$author || !$versionItem || !$version || !$project) {
            return;
        }

        $link = sprintf(
            '/projects/%d/versions/%d?section=comments&item=%d',
            $project->id,
            $version->id,
            $versionItem->id
        );

        if ($author->hasRole('chef')) {
            foreach ($project->testers as $tester) {
                if ($tester->id === $author->id || !$tester->hasRole('testeur')) {
                    continue;
                }

                $this->createNotification($tester, [
                    'type' => 'chef_comment_added',
                    'title' => 'Nouveau commentaire chef de projet',
                    'message' => 'Le chef de projet a ajouté un commentaire.',
                    'priority' => 'medium',
                    'link' => $link,
                    'target_type' => 'comment',
                    'target_id' => $comment->id,
                    'project_id' => $project->id,
                    'version_id' => $version->id,
                    'test_case_id' => $versionItem->id,
                    'comment_id' => $comment->id,
                    'section' => 'comments',
                ]);
            }

            return;
        }

        if ($author->hasRole('testeur')) {
            $manager = $project->creator;

            if ($manager && $manager->hasRole('chef') && $manager->id !== $author->id) {
                $this->createNotification($manager, [
                    'type' => 'tester_comment_added',
                    'title' => 'Nouveau commentaire testeur',
                    'message' => 'Un testeur a ajouté un commentaire.',
                    'priority' => 'medium',
                    'link' => $link,
                    'target_type' => 'comment',
                    'target_id' => $comment->id,
                    'project_id' => $project->id,
                    'version_id' => $version->id,
                    'test_case_id' => $versionItem->id,
                    'comment_id' => $comment->id,
                    'section' => 'comments',
                ]);
            }
        }
    }

    public function notifyAutomatedExecutionCompleted(TestRun $run): void
    {
        $run->loadMissing([
            'requester.roles',
            'projectVersion.project.creator.roles',
            'projectVersion.project.testers.roles',
        ]);

        $version = $run->projectVersion;
        $project = $version?->project;

        if (!$version || !$project) {
            return;
        }

        $link = sprintf(
            '/projects/%d/versions/%d?section=execution-history',
            $project->id,
            $version->id
        );

        $requester = $run->requester;
        if ($requester && $requester->hasRole('testeur') && $project->testers->contains('id', $requester->id)) {
            $this->createNotification($requester, [
                'type' => 'automated_test_completed',
                'title' => 'Exécution automatique terminée',
                'message' => 'L’exécution automatique d’un test est terminée.',
                'priority' => 'medium',
                'link' => $link,
                'target_type' => 'test_run',
                'target_id' => $run->id,
                'project_id' => $project->id,
                'version_id' => $version->id,
                'section' => 'execution-history',
            ]);
        }

        $manager = $project->creator;
        if ($manager && $manager->hasRole('chef')) {
            $this->createNotification($manager, [
                'type' => 'tester_execution_completed',
                'title' => 'Exécution testeur terminée',
                'message' => 'Un testeur a terminé une exécution automatique.',
                'priority' => 'medium',
                'link' => $link,
                'target_type' => 'test_run',
                'target_id' => $run->id,
                'project_id' => $project->id,
                'version_id' => $version->id,
                'section' => 'execution-history',
            ]);
        }

        $this->syncPendingTestNotifications($version);
    }

    public function notifySystemExecutionError(TestRun $run, string $errorType, string $errorMessage): void
    {
        $run->loadMissing('projectVersion.project');

        $version = $run->projectVersion;
        $project = $version?->project;
        $link = '/dashboard';

        if ($project && $version) {
            $link = sprintf(
                '/projects/%d/versions/%d?section=execution-history',
                $project->id,
                $version->id
            );
        }

        $requester = $run->requester;
        if ($requester && $requester->hasRole('testeur')) {
            $this->createNotification($requester, [
                'type' => 'system_execution_error',
                'title' => 'Erreur d’exécution automatique',
                'message' => 'Une erreur est survenue lors d’une exécution automatique.',
                'priority' => 'critical',
                'link' => $link,
                'target_type' => 'test_run',
                'target_id' => $run->id,
                'project_id' => $project?->id,
                'version_id' => $version?->id,
                'section' => 'execution-history',
            ]);
        }

        $manager = $project?->creator;
        if ($manager && $manager->hasRole('chef')) {
            $this->createNotification($manager, [
                'type' => 'system_execution_error',
                'title' => 'Erreur d’exécution automatique',
                'message' => 'Une erreur est survenue lors d’une exécution automatique.',
                'priority' => 'critical',
                'link' => $link,
                'target_type' => 'test_run',
                'target_id' => $run->id,
                'project_id' => $project?->id,
                'version_id' => $version?->id,
                'section' => 'execution-history',
            ]);
        }
    }

    public function syncPendingTestNotifications(ProjectVersion $version): void
    {
        $version->loadMissing([
            'project.creator.roles',
            'project.testers.roles',
            'items:id,project_version_id,status',
        ]);

        $project = $version->project;
        if (!$project) {
            return;
        }

        $total = $version->items->count();
        $pendingCount = $version->items->where('status', 'Not Tested')->count();
        $testedCount = $version->items->whereIn('status', ['Passed', 'Failed', 'Blocked'])->count();
        $completionPercent = $total > 0 ? round(($testedCount / $total) * 100, 2) : 0;
        $pendingLink = sprintf('/projects/%d/versions/%d?section=pending-tests', $project->id, $version->id);
        $progressLink = sprintf('/projects/%d/versions/%d?section=progress', $project->id, $version->id);

        if ($pendingCount > 0) {
            foreach ($project->testers as $tester) {
                if (!$tester->hasRole('testeur')) {
                    continue;
                }

                $this->upsertReminder($tester, 'pending_tests_reminder', [
                    'title' => 'Tests en attente',
                    'message' => 'Des tests sont encore en attente d’exécution.',
                    'priority' => 'high',
                    'link' => $pendingLink,
                    'target_type' => 'project_version',
                    'target_id' => $version->id,
                    'project_id' => $project->id,
                    'version_id' => $version->id,
                    'section' => 'pending-tests',
                ]);
            }

            $manager = $project->creator;
            if ($manager && $manager->hasRole('chef')) {
                $this->upsertReminder($manager, 'project_pending_tests', [
                    'title' => 'Tests en attente dans le projet',
                    'message' => 'Des tests sont encore en attente d’exécution dans l’un de vos projets.',
                    'priority' => 'high',
                    'link' => $pendingLink,
                    'target_type' => 'project_version',
                    'target_id' => $version->id,
                    'project_id' => $project->id,
                    'version_id' => $version->id,
                    'section' => 'pending-tests',
                ]);
            }
        } else {
            $this->archiveReminderTypeForVersion('pending_tests_reminder', $version->id);
            $this->archiveReminderTypeForVersion('project_pending_tests', $version->id);
        }

        if ($completionPercent < self::LOW_PROGRESS_THRESHOLD && $pendingCount > 0) {
            $manager = $project->creator;

            if ($manager && $manager->hasRole('chef')) {
                $this->upsertReminder($manager, 'project_low_progress', [
                    'title' => 'Progression faible',
                    'message' => 'La progression des tests d’un projet est faible.',
                    'priority' => 'high',
                    'link' => $progressLink,
                    'target_type' => 'project_version',
                    'target_id' => $version->id,
                    'project_id' => $project->id,
                    'version_id' => $version->id,
                    'section' => 'progress',
                ]);
            }
        } else {
            $this->archiveReminderTypeForVersion('project_low_progress', $version->id);
        }
    }

    public static function categoryForType(string $type): string
    {
        if (in_array($type, self::PROJECT_TYPES, true)) {
            return 'projects';
        }

        if (in_array($type, self::COMMENT_TYPES, true)) {
            return 'comments';
        }

        if (in_array($type, self::SYSTEM_TYPES, true)) {
            return 'system';
        }

        return 'tests';
    }

    public function createNotification(User $user, array $attributes): ?Notification
    {
        if (!$this->canReceiveNotifications($user)) {
            return null;
        }

        $payload = [
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => [
                'title' => $attributes['title'],
                'message' => $attributes['message'],
                'link' => $attributes['link'] ?? null,
                'priority' => $attributes['priority'],
                'type' => $attributes['type'],
            ],
            'user_id' => $user->id,
            'role' => $this->resolvePrimaryRole($user),
            'type' => $attributes['type'],
            'title' => $attributes['title'],
            'message' => $attributes['message'],
            'priority' => $attributes['priority'],
            'link' => $attributes['link'] ?? null,
            'target_type' => $attributes['target_type'] ?? null,
            'target_id' => $attributes['target_id'] ?? null,
            'project_id' => $attributes['project_id'] ?? null,
            'version_id' => $attributes['version_id'] ?? null,
            'checklist_id' => $attributes['checklist_id'] ?? null,
            'test_case_id' => $attributes['test_case_id'] ?? null,
            'comment_id' => $attributes['comment_id'] ?? null,
            'section' => $attributes['section'] ?? null,
            'is_read' => false,
            'is_archived' => false,
            'read_at' => null,
        ];

        return Notification::create($payload);
    }

    private function upsertReminder(User $user, string $type, array $attributes): ?Notification
    {
        if (!$this->canReceiveNotifications($user)) {
            return null;
        }

        $notification = Notification::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->where('version_id', $attributes['version_id'] ?? null)
            ->where('is_archived', false)
            ->latest('created_at')
            ->first();

        if ($notification) {
            $notification->update([
                'role' => $this->resolvePrimaryRole($user),
                'title' => $attributes['title'],
                'message' => $attributes['message'],
                'priority' => $attributes['priority'],
                'link' => $attributes['link'] ?? null,
                'target_type' => $attributes['target_type'] ?? null,
                'target_id' => $attributes['target_id'] ?? null,
                'project_id' => $attributes['project_id'] ?? null,
                'version_id' => $attributes['version_id'] ?? null,
                'section' => $attributes['section'] ?? null,
                'is_read' => false,
                'read_at' => null,
            ]);

            return $notification;
        }

        return $this->createNotification($user, [
            ...$attributes,
            'type' => $type,
        ]);
    }

    private function archiveReminderTypeForVersion(string $type, int $versionId): void
    {
        Notification::query()
            ->where('type', $type)
            ->where('version_id', $versionId)
            ->where('is_archived', false)
            ->update([
                'is_archived' => true,
            ]);
    }

    private function toUsersCollection(iterable $users): Collection
    {
        if ($users instanceof Collection) {
            return $users;
        }

        return collect($users)
            ->filter(fn ($user) => $user instanceof User)
            ->values();
    }

    private function resolvePrimaryRole(User $user): string
    {
        if ($user->hasRole('admin')) {
            return 'admin';
        }

        if ($user->hasRole('chef')) {
            return 'chef';
        }

        if ($user->hasRole('testeur')) {
            return 'testeur';
        }

        return 'user';
    }

    private function canReceiveNotifications(User $user): bool
    {
        return $user->hasRole('chef') || $user->hasRole('testeur');
    }
}
