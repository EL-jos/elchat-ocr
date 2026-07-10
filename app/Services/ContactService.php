<?php

namespace App\Services;

use App\Mail\ContactMail;
use App\Models\ContactMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class ContactService
{
    /**
     * Enregistre le message puis envoie les emails.
     */
    public function send(array $data): ContactMessage
    {
        return DB::transaction(function () use ($data) {

            $message = ContactMessage::create([

                'name' => $data['name'],

                'email' => $data['email'],

                'phone' => $data['phone'],

                'message' => $data['message'],

                'ip_address' => $data['ip'],

                'user_agent' => $data['user_agent'],

            ]);

            Mail::to('contact@elchat.io')->cc('elongajosue22@gmail.com')->send(new ContactMail([
                'id' => $message->id,

                'name' => $message->name,

                'email' => $message->email,

                'phone' => $message->phone,

                'message' => $message->message,

                'ip' => $message->ip_address,

                'user_agent' => $message->user_agent,

                'date' => $message->created_at,
            ]));

            return $message;

        });
    }

    /**
     * Marquer comme lu.
     */
    public function markAsRead(ContactMessage $message): ContactMessage
    {
        $message->update([

            'is_read' => true,

            'read_at' => now(),

        ]);

        return $message;
    }

    /**
     * Marquer comme non lu.
     */
    public function markAsUnread(ContactMessage $message): ContactMessage
    {
        $message->update([

            'is_read' => false,

            'read_at' => null,

        ]);

        return $message;
    }
}
