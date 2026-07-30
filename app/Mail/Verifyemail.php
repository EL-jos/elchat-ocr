<?php

// ============================================================
// FICHIER 1 : app/Mail/VerifyEmail.php
// ============================================================
// Utilisé par AuthController (backend existant) pour envoyer
// le code de vérification après inscription.
// Variables passées à la vue : $user, $code

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerifyEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User   $user,
        public string $code
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '✉️ Vérifiez votre adresse email — ELChat',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verify-code',
        // Variables disponibles dans la vue : $user, $code
        );
    }
}
