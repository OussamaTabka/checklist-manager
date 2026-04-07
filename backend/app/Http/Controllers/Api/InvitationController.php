<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserAccountEvent;
use App\Models\UserInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class InvitationController extends Controller
{
    public function validateInvitation(Request $request)
    {
        $data = $request->validate([
            'selector' => ['required', 'string'],
            'token' => ['required', 'string'],
        ]);

        $invitation = UserInvitation::with(['user.roles'])->where('selector', $data['selector'])->first();

        if (!$invitation || !$invitation->tokenMatches($data['token'])) {
            return response()->json(['message' => 'Invalid invitation link.'], 422);
        }

        if ($invitation->status !== 'pending') {
            return response()->json(['message' => 'This invitation is no longer valid.'], 410);
        }

        if ($invitation->isExpired()) {
            $invitation->update(['status' => 'expired']);
            $this->recordEvent($invitation->user_id, null, 'invitation_expired', [
                'invitation_id' => $invitation->id,
            ], $request);

            return response()->json(['message' => 'This invitation has expired.'], 410);
        }

        return response()->json([
            'valid' => true,
            'user' => [
                'name' => $invitation->user->name,
                'email' => $invitation->user->email,
                'role' => $invitation->user->roles->first()?->name,
            ],
            'expires_at' => optional($invitation->expires_at)->toISOString(),
        ]);
    }

    public function acceptInvitation(Request $request)
    {
        $data = $request->validate([
            'selector' => ['required', 'string'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'min:8'],
        ]);

        DB::transaction(function () use ($data, $request) {
            /** @var UserInvitation|null $invitation */
            $invitation = UserInvitation::where('selector', $data['selector'])->lockForUpdate()->first();

            if (!$invitation || !$invitation->tokenMatches($data['token'])) {
                abort(response()->json(['message' => 'Invalid invitation link.'], 422));
            }

            if ($invitation->status !== 'pending') {
                abort(response()->json(['message' => 'This invitation is no longer valid.'], 410));
            }

            if ($invitation->isExpired()) {
                $invitation->update(['status' => 'expired']);
                $this->recordEvent($invitation->user_id, null, 'invitation_expired', [
                    'invitation_id' => $invitation->id,
                ], $request);

                abort(response()->json(['message' => 'This invitation has expired.'], 410));
            }

            /** @var User $user */
            $user = User::whereKey($invitation->user_id)->lockForUpdate()->firstOrFail();

            $user->forceFill([
                'password' => Hash::make($data['password']),
                'account_status' => 'active',
                'activated_at' => now(),
                'email_verified_at' => now(),
                'remember_token' => Str::random(60),
            ])->save();

            $user->tokens()->delete();

            $invitation->update([
                'status' => 'accepted',
                'accepted_at' => now(),
                'accepted_ip' => $request->ip(),
                'accepted_user_agent' => $request->userAgent(),
            ]);

            UserInvitation::where('user_id', $user->id)
                ->where('status', 'pending')
                ->where('id', '!=', $invitation->id)
                ->update([
                    'status' => 'revoked',
                    'revoked_at' => now(),
                ]);

            $this->recordEvent($user->id, null, 'account_activated', [
                'invitation_id' => $invitation->id,
            ], $request);
        });

        return response()->json([
            'message' => 'Account activated successfully. You can now sign in.',
        ]);
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
