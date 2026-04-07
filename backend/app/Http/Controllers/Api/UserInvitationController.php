<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\UserInvitationMail;
use App\Models\User;
use App\Models\UserAccountEvent;
use App\Models\UserInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class UserInvitationController extends Controller
{
    public function resend(Request $request, User $user)
    {
        if ($user->account_status === 'active') {
            return response()->json(['message' => 'This account is already active.'], 422);
        }

        UserInvitation::where('user_id', $user->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'revoked',
                'revoked_at' => now(),
            ]);

        [$invitation, $token] = $this->issueInvitation($user, $request->user()->id);
        $this->sendInvitationEmail($user, $request->user(), $invitation, $token);

        $this->recordEvent($user->id, $request->user()->id, 'invitation_resent', [
            'invitation_id' => $invitation->id,
            'expires_at' => optional($invitation->expires_at)->toISOString(),
        ], $request);

        return response()->json([
            'message' => 'Invitation resent successfully.',
            'expires_at' => optional($invitation->expires_at)->toISOString(),
        ]);
    }

    public function revoke(Request $request, User $user)
    {
        $updated = UserInvitation::where('user_id', $user->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'revoked',
                'revoked_at' => now(),
            ]);

        if (!$updated) {
            return response()->json(['message' => 'No pending invitation found.'], 422);
        }

        $this->recordEvent($user->id, $request->user()->id, 'invitation_revoked', [], $request);

        return response()->json(['message' => 'Invitation revoked successfully.']);
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
