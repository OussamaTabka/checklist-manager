<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Checklist;
use App\Models\Project;
use App\Models\User;
use App\Models\UserAccountEvent;
use App\Models\UserStory;
use App\Services\NotificationService;
use App\Services\UserPasswordSetupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

class UserController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly UserPasswordSetupService $passwordSetupService
    ) {
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'role' => ['nullable', Rule::in(['admin', 'chef', 'testeur'])],
            'status' => ['nullable', Rule::in(['active', 'archived'])],
        ]);

        $status = $data['status'] ?? 'active';

        $users = User::with([
                'roles:id,name',
                'latestInvitation' => function ($query) {
                    $query->select([
                        'user_invitations.id',
                        'user_invitations.user_id',
                        'user_invitations.status',
                        'user_invitations.expires_at',
                        'user_invitations.last_sent_at',
                        'user_invitations.created_at',
                    ]);
                },
            ])
            ->select(['id', 'name', 'email', 'account_status', 'archived_previous_status', 'invited_at', 'activated_at', 'archived_at', 'created_at'])
            ->withCount([
                'ownedProjects as owned_projects_count' => fn ($query) => $query->withTrashed(),
            ])
            ->when(
                $status === 'archived',
                fn ($query) => $query->where('account_status', 'disabled'),
                fn ($query) => $query->whereIn('account_status', ['active', 'pending'])
            )
            ->when(
                !empty($data['role']),
                fn ($query) => $query->whereHas('roles', fn ($roleQuery) => $roleQuery->where('name', $data['role']))
            )
            ->when(
                $status === 'archived',
                fn ($query) => $query->orderByDesc('archived_at')->orderByDesc('id'),
                fn ($query) => $query->orderByDesc('id')
            );

        $users = $users->paginate(10)->withQueryString();

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'role' => ['required', Rule::in(['admin', 'chef', 'testeur'])],
        ]);

        DB::beginTransaction();

        try {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => null,
                'account_status' => 'pending',
                'invited_by' => Auth::id(),
                'invited_at' => now(),
            ]);

            $user->syncRoles([$data['role']]);

            $passwordSetup = $this->passwordSetupService->sendInitializationEmail($user, $request->user());

            $this->recordEvent($user->id, Auth::id(), 'password_setup_email_sent', [
                'expires_at' => $passwordSetup['expires_at']->toISOString(),
                'delivery_method' => $passwordSetup['delivery_method'],
            ], $request);

            DB::commit();

            try {
                $this->notificationService->notifyUserCreated($user, $request->user());
            } catch (Throwable $notificationException) {
                report($notificationException);
            }

            return response()->json([
                'user' => $user->load(['roles:name', 'latestInvitation']),
                'message' => $passwordSetup['delivery_method'] === 'log'
                    ? "Utilisateur cree avec succes. En mode local, l'email d'initialisation a ete journalise."
                    : "Utilisateur cree avec succes. Un email d'invitation a ete envoye pour l'initialisation du mot de passe.",
                'delivery_method' => $passwordSetup['delivery_method'],
                'delivery_notice' => $passwordSetup['delivery_notice'],
            ], 201);
        } catch (Throwable $exception) {
            DB::rollBack();
            report($exception);

            return response()->json([
                'message' => "La creation de l'utilisateur a echoue. L'email d'initialisation n'a pas pu etre envoye.",
                'error' => app()->isLocal() ? $exception->getMessage() : null,
            ], 500);
        }
    }

    public function show(User $user)
    {
        return response()->json($user->load(['roles:name', 'latestInvitation']));
    }

    public function update(Request $request, User $user)
    {
        $oldRole = $user->roles()->pluck('name')->first() ?? 'testeur';

        $data = $request->validate([
            'role' => ['required', Rule::in(['admin', 'chef', 'testeur'])],
        ]);

        $user->syncRoles([$data['role']]);
        $this->notificationService->notifyUserRoleChanged($user, $oldRole, $data['role'], $request->user());

        return response()->json($user->load(['roles:name', 'latestInvitation']));
    }

    public function destroy(User $user)
    {
        if (Auth::id() === $user->id) {
            return response()->json(['message' => "Vous ne pouvez pas archiver votre propre compte."], 422);
        }

        if ($user->account_status === 'disabled') {
            return response()->json(['message' => 'Cet utilisateur est deja archive.'], 422);
        }

        $user->update([
            'account_status' => 'disabled',
            'archived_previous_status' => $user->account_status,
            'archived_at' => now(),
        ]);

        $user->tokens()->delete();
        $this->passwordSetupService->revoke($user);
        $user->invitations()
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        return response()->json([
            'message' => 'Utilisateur archive avec succes.',
            'user' => $user->load(['roles:name', 'latestInvitation']),
        ]);
    }

    public function restore(User $user)
    {
        if ($user->account_status !== 'disabled') {
            return response()->json(['message' => "Seuls les utilisateurs archives peuvent etre restaures."], 422);
        }

        $restoreStatus = in_array($user->archived_previous_status, ['active', 'pending'], true)
            ? $user->archived_previous_status
            : 'active';

        $user->update([
            'account_status' => $restoreStatus,
            'archived_previous_status' => null,
            'archived_at' => null,
        ]);

        return response()->json([
            'message' => 'Utilisateur restaure avec succes.',
            'user' => $user->load(['roles:name', 'latestInvitation']),
        ]);
    }

    public function permanentDestroy(Request $request, User $user)
    {
        if ($user->account_status !== 'disabled') {
            return response()->json(['message' => "L'utilisateur doit d'abord etre archive avant suppression definitive."], 422);
        }

        $ownership = $this->protectedOwnershipCounts($user);
        $hasOwnedProjects = $ownership['projects'] > 0;

        $data = $request->validate([
            'replacement_owner_id' => [
                Rule::requiredIf($hasOwnedProjects),
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(function ($query) {
                    $query
                        ->where('account_status', 'active')
                        ->whereIn('id', User::role('chef')->select('id'));
                }),
            ],
        ], [
            'replacement_owner_id.required' => 'Un chef de projet remplacant est requis pour reassigner les projets de cet utilisateur.',
            'replacement_owner_id.exists' => 'Le chef de projet remplacant selectionne est invalide ou inactif.',
        ]);

        if (isset($data['replacement_owner_id']) && (int) $data['replacement_owner_id'] === (int) $user->id) {
            return response()->json([
                'message' => "Le chef de projet remplacant doit etre different de l'utilisateur supprime.",
                'errors' => [
                    'replacement_owner_id' => [
                        "Le chef de projet remplacant doit etre different de l'utilisateur supprime.",
                    ],
                ],
            ], 422);
        }

        if ($hasOwnedProjects && empty($data['replacement_owner_id'])) {
            return response()->json([
                'message' => 'Suppression definitive impossible sans reassigner les projets de cet utilisateur.',
                'ownership_counts' => $ownership,
                'requires_replacement_owner' => true,
            ], 422);
        }

        DB::transaction(function () use ($user, $data) {
            $replacementOwnerId = $data['replacement_owner_id'] ?? null;

            if ($replacementOwnerId) {
                Project::withTrashed()
                    ->where('created_by', $user->id)
                    ->update(['created_by' => $replacementOwnerId]);

                UserStory::withTrashed()
                    ->where('created_by', $user->id)
                    ->update(['created_by' => $replacementOwnerId]);
            }

            Checklist::withTrashed()
                ->where('created_by', $user->id)
                ->update(['created_by' => null]);

            if ($user->profile_photo_path) {
                Storage::disk('public')->delete($user->profile_photo_path);
            }

            $user->tokens()->delete();
            $user->delete();
        });

        return response()->json([
            'message' => 'Utilisateur supprime definitivement avec succes.',
        ]);
    }

    public function getAvailableProjectManagers()
    {
        $users = User::with(['roles:id,name'])
            ->where('account_status', 'active')
            ->whereHas('roles', function ($query) {
                $query->where('name', 'chef');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'account_status']);

        return response()->json(['data' => $users]);
    }

    public function getAvailableTesters()
    {
        $users = User::with(['roles:id,name'])
            ->where('account_status', 'active')
            ->whereHas('roles', function ($query) {
                $query->where('name', 'testeur');
            })
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $users]);
    }

    private function recordEvent(int $userId, ?int $actorId, string $eventType, array $payload, Request $request): void
    {
        UserAccountEvent::create([
            'user_id' => $userId,
            'actor_id' => $actorId,
            'event_type' => $eventType,
            'payload' => $payload,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }

    /**
     * @return array{projects:int, checklists:int, user_stories:int}
     */
    private function protectedOwnershipCounts(User $user): array
    {
        return [
            'projects' => Project::withTrashed()->where('created_by', $user->id)->count(),
            'checklists' => Checklist::withTrashed()->where('created_by', $user->id)->count(),
            'user_stories' => UserStory::withTrashed()->where('created_by', $user->id)->count(),
        ];
    }
}
