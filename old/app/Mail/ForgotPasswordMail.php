<?php

namespace App\Mail;

 use App\Models\User;
 use Illuminate\Bus\Queueable;
 use Illuminate\Mail\Mailable;
 use Illuminate\Mail\Mailables\Content;
 use Illuminate\Mail\Mailables\Envelope;
 use Illuminate\Queue\SerializesModels;

 class ForgotPasswordMail extends Mailable
 {
     use Queueable, SerializesModels;

     public function __construct(
         public User   $user,
         public string $code
     ) {}

     public function envelope(): Envelope
     {
         return new Envelope(
             subject: '🔐 Réinitialisation de votre mot de passe — ELChat',
         );
     }

     public function content(): Content
     {
         return new Content(
             view: 'emails.forgot-password',
         );
     }
 }
