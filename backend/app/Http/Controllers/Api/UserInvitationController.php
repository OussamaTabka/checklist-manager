<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserAccountEvent;
use App\Models\UserInvitation;
use App\Services\UserPasswordSetupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class UserInvitationController extends Controller
{
    public function __construct(
        private readonly UserPasswordSetupService $passwordSetupService
    ) {
    }

    public function resend(Request $request, User $user)
    {
        if ($user->account_status === 'active') {
            return response()->json(['message' => 'Ce compte est deja actif.'], 422);
        }

        if ($user->account_status === 'disabled') {
            return response()->json(['message' => "Impossible de renvoyer l'invitation pour un compte archive."], 422);
        }

        DB::beginTransaction();

        try {
            $this->passwordSetupService->revoke($user);

            UserInvitation::where('user_id', $user->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'revoked',
                    'revoked_at' => now(),
                ]);

            $passwordSetup = $this->passwordSetupService->sendInitializationEmail($user, $request->user());

            $this->recordEvent($user->id, $request->user()->id, 'password_setup_email_resent', [
                'expires_at' => $passwordSetup['expires_at']->toISOString(),
                'delivery_method' => $passwordSetup['delivery_method'],
            ], $request);

            DB::commit();

            return response()->json([
                'message' => $passwordSetup['delivery_method'] === 'log'
                    ? "En mode local, l'email d'initialisation a ete journalise."
                    : "L'email d'initialisation du mot de passe a ete renvoye avec succes.",
                'expires_at' => $passwordSetup['expires_at']->toISOString(),
                'delivery_method' => $passwordSetup['delivery_method'],
                'delivery_notice' => $passwordSetup['delivery_notice'],
            ]);
        } catch (Throwable $exception) {
            DB::rollBack();
            report($exception);

            return response()->json([
                'message' => "Le renvoi de l'email d'initialisation a echoue.",
                'error' => app()->isLocal() ? $exception->getMessage() : null,
            ], 500);
        }
    }

    public function revoke(Request $request, User $user)
    {
        if ($user->account_status === 'active') {
            return response()->json(['message' => 'Ce compte est deja actif.'], 422);
        }

        $this->passwordSetupService->revoke($user);

        UserInvitation::where('user_id', $user->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'revoked',
                'revoked_at' => now(),
            ]);

        $this->recordEvent($user->id, $request->user()->id, 'password_setup_email_revoked', [], $request);

        return response()->json(['message' => "L'invitation a ete revoquee avec succes."]);
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
