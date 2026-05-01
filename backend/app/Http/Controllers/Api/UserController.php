<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\UserInvitationMail;
use App\Models\User;
use App\Models\UserAccountEvent;
use App\Models\UserInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
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
            ->select(['id', 'name', 'email', 'account_status', 'invited_at', 'activated_at', 'created_at'])
            ->orderByDesc('id')
            ->paginate(10);

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'role' => ['required', Rule::in(['admin', 'chef', 'testeur'])],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => null,
            'account_status' => 'pending',
            'invited_by' => Auth::id(),
            'invited_at' => now(),
        ]);

        [$invitation, $token] = $this->issueInvitation($user, Auth::id());
        $this->sendInvitationEmail($user, $request->user(), $invitation, $token);
        $this->recordEvent($user->id, Auth::id(), 'invitation_sent', [
            'invitation_id' => $invitation->id,
            'expires_at' => optional($invitation->expires_at)->toISOString(),
        ], $request);

        return response()->json([
            'user' => $user->load(['roles:name', 'latestInvitation']),
            'message' => 'User created and invitation email sent.',
        ], 201);
    }

    public function show(User $user)
    {
        return response()->json($user->load(['roles:name', 'latestInvitation']));
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', Rule::unique('users')->ignore($user->id)],
            'role' => ['sometimes', Rule::in(['admin', 'chef', 'testeur'])],
        ]);

        if (isset($data['name'])) {
            $user->name = $data['name'];
        }

        if (isset($data['email'])) {
            $user->email = $data['email'];
        }

        $user->save();

        if (isset($data['role'])) {
            $user->syncRoles([$data['role']]);
        }

        return response()->json($user->load(['roles:name', 'latestInvitation']));
    }

    public function destroy(User $user)
    {
        if (Auth::id() === $user->id) {
            return response()->json(['message' => 'You cannot delete yourself'], 422);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted']);
    }

    public function getAvailableTesters()
    {
        $users = User::with(['roles:id,name'])
            ->whereHas('roles', function ($query) {
                $query->where('name', 'testeur');
            })
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $users]);
    }

    private function issueInvitation(User $user, int $inviterId): array
    {
        $selector = Str::random(32);
        $token = Str::random(64);
        $expiresAt = now()->addHours(config('invitations.expires_hours', 24));

        $invitation = UserInvitation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'invited_by' => $inviterId,
            'selector' => $selector,
            'token_hash' => hash('sha256', $token),
            'status' => 'pending',
            'expires_at' => $expiresAt,
            'last_sent_at' => now(),
            'send_count' => 1,
        ]);

        return [$invitation, $token];
    }

    private function sendInvitationEmail(User $user, User $inviter, UserInvitation $invitation, string $token): void
    {
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $setupLink = sprintf(
            '%s/accept-invitation?selector=%s&token=%s',
            $frontendUrl,
            urlencode($invitation->selector),
            urlencode($token)
        );

        Mail::to($user->email)->send(new UserInvitationMail(
            user: $user,
            setupLink: $setupLink,
            role: $user->roles->first()?->name ?? 'testeur',
            inviterName: $inviter->name,
            expiresAt: $invitation->expires_at->toDayDateTimeString()
        ));
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
}
