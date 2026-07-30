<?php

 namespace App\Mail;

 use App\Models\Account;
 use Illuminate\Bus\Queueable;
 use Illuminate\Mail\Mailable;
 use Illuminate\Mail\Mailables\Content;
 use Illuminate\Mail\Mailables\Envelope;
 use Illuminate\Queue\SerializesModels;

 class TrialExpiring extends Mailable
 {
     use Queueable, SerializesModels;

     public function __construct(
         public Account $account,
         public int     $daysLeft
     ) {}

     public function envelope(): Envelope
     {
         $urgency = $this->daysLeft === 1 ? '⏰ Dernier jour' : "⏳ Plus que {$this->daysLeft} jours";
         return new Envelope(
             subject: "{$urgency} — Votre essai ELChat expire bientôt",
         );
     }

     public function content(): Content
     {
         return new Content(
             view: 'emails.trial-expiring',
         );
     }
 }
