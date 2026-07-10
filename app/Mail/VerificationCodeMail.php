<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;
    public $user;
    public $code;
    public $minutes;
    /**
     * Create a new message instance.
     */
    public function __construct(User $user, string $code, int $minutes = 1)
    {
        $this->user = $user;
        $this->code = $code;
        $this->minutes = $minutes;
    }

    public function build()
    {
        return $this->subject('Code de vérification')
            ->view('emails.verification_code')
            ->with([
                'user' => $this->user,
                'code' => $this->code,
                'minutes' => $this->minutes,
            ]);
    }
}
