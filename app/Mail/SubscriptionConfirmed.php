<?php

 namespace App\Mail;

 use App\Models\Account;
 use Illuminate\Bus\Queueable;
 use Illuminate\Mail\Mailable;
 use Illuminate\Mail\Mailables\Content;
 use Illuminate\Mail\Mailables\Envelope;
 use Illuminate\Queue\SerializesModels;

 class SubscriptionConfirmed extends Mailable
 {
     use Queueable, SerializesModels;

     public function __construct(public Account $account) {}

     public function envelope(): Envelope
     {
         $planName = $this->account->subscription?->plan?->name ?? 'Starter';
         return new Envelope(
             subject: "✅ Votre abonnement ELChat {$planName} est activé",
         );
     }

     public function content(): Content
     {
         return new Content(
             view: 'emails.subscription-confirmed',
         );
     }
 }
