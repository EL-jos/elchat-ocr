<?php

namespace App\Http\Controllers\web\v4;

use App\Enums\Social\ReplyStatus;
use App\Http\Controllers\Controller;
use App\Jobs\social\FacebookReplyJob;
use App\Models\Site;
use App\Models\Social\SocialConversation;
use App\Models\Social\SocialReplyQueue;
use Illuminate\Http\Request;

class SocialInboxController extends Controller
{
    public function index(
        Request $request,
        string $siteId
    )
    {
        $site = Site::query()
            ->where('id', $siteId)
            ->firstOrFail();

        return SocialConversation::query()
            ->where('site_id', $site->id)
            ->with([
                'socialAccount',
                'messages'
            ])
            ->latest('last_message_at')
            ->paginate(20);
    }

    public function show(
        string $siteId,
        string $conversationId
    )
    {
        $conversation = SocialConversation::query()
            ->where('site_id', $siteId)
            ->with([
                'messages',
                'messages.replyQueue'
            ])
            ->findOrFail($conversationId);

        return response()->json($conversation);
    }

    public function approve(
        string $replyId
    )
    {
        $reply = SocialReplyQueue::findOrFail(
            $replyId
        );

        if (
            $reply->status !== ReplyStatus::PENDING
        ) {
            abort(422);
        }

        $reply->update([
            'status' => ReplyStatus::APPROVED,
            'approved_at' => now(),
        ]);

        FacebookReplyJob::dispatch(
            $reply->id
        );

        return response()->json([
            'success' => true
        ]);
    }

    public function reject(
        string $replyId
    )
    {
        $reply = SocialReplyQueue::findOrFail(
            $replyId
        );

        $reply->update([
            'status' => ReplyStatus::REJECTED,
        ]);

        return response()->json([
            'success' => true
        ]);
    }

    public function update(
        Request $request,
        string $replyId
    )
    {
        $data = $request->validate([
            'content' => [
                'required',
                'string',
                'max:5000'
            ]
        ]);

        $reply = SocialReplyQueue::findOrFail(
            $replyId
        );

        $reply->socialMessage->update([
            'content' => $data['content']
        ]);

        return response()->json([
            'success' => true
        ]);
    }
}
