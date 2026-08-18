<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class ConversationListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $lastMessage = $this->relationLoaded('messages')
            ? $this->messages->sortByDesc('created_at')->first()
            : $this->messages()->latest()->first();

        $contactName = $this->contactName();

        return [
            'id' => $this->id,
            'status' => $this->status,
            'contactName' => $contactName,
            'contactAvatarInitial' => Str::of($contactName)->substr(0, 1)->upper()->toString(),
            'isRegistered' => (bool) $this->user_id,
            'messagesCount' => $this->messages_count ?? $this->messages()->count(),
            'lastMessagePreview' => $lastMessage?->content ? Str::limit($lastMessage->content, 90) : null,
            'lastMessageAt' => optional($lastMessage?->created_at ?? $this->updated_at)->toIso8601String(),
            'createdAt' => optional($this->created_at)->toIso8601String(),
        ];
    }

    private function contactName(): string
    {
        if ($this->relationLoaded('user') && $this->user) {
            $full = trim("{$this->user->firstname} {$this->user->lastname}");
            return $full !== '' ? $full : $this->user->email;
        }

        return 'Visiteur ' . Str::substr($this->visitor_id ?? $this->id, 0, 8);
    }
}
