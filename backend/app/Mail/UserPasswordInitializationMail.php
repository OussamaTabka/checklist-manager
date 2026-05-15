<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserPasswordInitializationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $setupLink,
        public ?string $inviterName,
        public string $expiresAt
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Initialisation de votre mot de passe - IntelliTest',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.user-password-initialization',
            with: [
                'user' => $this->user,
                'setupLink' => $this->setupLink,
                'inviterName' => $this->inviterName,
                'expiresAt' => $this->expiresAt,
            ]
        );
    }
}
