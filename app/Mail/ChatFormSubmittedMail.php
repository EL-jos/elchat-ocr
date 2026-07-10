<?php

namespace App\Mail;

use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ChatFormSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Site $site,
        public string $formId,
        public array $values
    )
    { }

    public function build(): self {
        return $this->subject('Nouvelle soumission formulaire ELChat sur [' . $this->site->name . ']')
            ->view('emails.formSubmitted');
    }
}
