<?php

namespace App\Services;

use App\Mail\UserInvitationMail;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\MailDeliveryInspector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Throwable;

class UserPasswordSetupService
{
    /**
     * @return array{setup_link: string, expires_at: Carbon, delivery_method: string, delivery_notice: ?string}
     */
    public function sendInitializationEmail(User $user, ?User $inviter = null): array
    {
        $token = Str::random(64);
        $selector = Str::random(40);
        $expiresAt = now()->addMinutes($this->passwordResetExpiryMinutes());
        $setupLink = $this->buildFrontendLink($selector, $token);

        UserInvitation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'invited_by' => $inviter?->id ?? $user->invited_by ?? $user->id,
            'selector' => $selector,
            'token_hash' => hash('sha256', $token),
            'status' => 'pending',
            'expires_at' => $expiresAt,
            'last_sent_at' => now(),
            'send_count' => 1,
        ]);

        $mailable = new UserInvitationMail(
            user: $user,
            setupLink: $setupLink,
            role: $user->roles()->pluck('name')->first() ?? 'testeur',
            inviterName: $inviter?->name,
            expiresAt: $expiresAt->format('d/m/Y H:i')
        );

        $inspection = MailDeliveryInspector::inspect();
        $deliveryMethod = 'email';
        $deliveryNotice = null;

        try {
            if ($inspection['deliverable']) {
                Mail::to($user->email)->send($mailable);
            } elseif (app()->isLocal()) {
                Mail::mailer('log')->to($user->email)->send($mailable);
                $deliveryMethod = 'log';
                $deliveryNotice = "Mode local : l'email d'initialisation a ete journalise dans backend/storage/logs/laravel.log.";
            } else {
                throw $this->buildInvalidMailConfigurationException($inspection['message']);
            }
        } catch (Throwable $exception) {
            if (!app()->isLocal()) {
                throw $exception;
            }

            report($exception);
            Mail::mailer('log')->to($user->email)->send($mailable);
            $deliveryMethod = 'log';
            $deliveryNotice = "Mode local : l'envoi email reel a echoue. Une copie a ete journalisee dans backend/storage/logs/laravel.log.";
        }

        return [
            'setup_link' => $setupLink,
            'expires_at' => $expiresAt,
            'delivery_method' => $deliveryMethod,
            'delivery_notice' => $deliveryNotice,
        ];
    }

    public function revoke(User $user): void
    {
        $user->invitations()
            ->where('status', 'pending')
            ->update([
                'status' => 'revoked',
                'revoked_at' => now(),
            ]);
    }

    public function buildFrontendLink(string $selector, string $token): string
    {
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

        return "{$frontendUrl}/accept-invitation?selector={$selector}&token={$token}";
    }

    public function passwordResetExpiryMinutes(): int
    {
        $broker = (string) config('auth.defaults.passwords', 'users');

        return (int) config("auth.passwords.{$broker}.expire", 60);
    }

    private function buildInvalidMailConfigurationException(?string $details): ValidationException
    {
        $suffix = $details ? " Detail: {$details}" : '';

        return ValidationException::withMessages([
            'email' => [
                "La configuration d'envoi d'emails n'est pas valide. Verifiez le compte expediteur de l'application.{$suffix}",
            ],
        ]);
    }
}
