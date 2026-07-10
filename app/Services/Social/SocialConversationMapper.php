<?php

namespace App\Services\Social;

use App\Models\Conversation;
use App\Models\Social\SocialConversation;
use App\Models\Social\SocialConversationLink;
use Illuminate\Support\Facades\Log;

class SocialConversationMapper
{
    public function resolveConversation(
        SocialConversation $socialConversation
    ): Conversation {

        Log::info("DANS SOCIAL CONVERSATION MAPPING");

        $link = SocialConversationLink::where(
            'social_conversation_id',
            $socialConversation->id
        )->first();

        if ($link) {

            return Conversation::findOrFail(
                $link->conversation_id
            );
        }

        $conversation = Conversation::create([
            'site_id' => $socialConversation->site_id,
            'user_id' => null,
            'visitor_id' => null,
        ]);

        SocialConversationLink::create([
            'social_conversation_id' => $socialConversation->id,
            'conversation_id' => $conversation->id,
        ]);

        Log::info("CREATION D'UNE NOUVELLE CONVERSATION MAPPER", $conversation->toArray());

        return $conversation;
    }
}
