<?php

namespace App\Http\Controllers\api\v5;

use App\Http\Controllers\api\v1\ChatController;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Site;
use Illuminate\Http\Request;

class AdminCopilotController extends Controller
{
    public function __construct(private readonly ChatController $chatController)
    {
    }

    public function conversations(Request $request, Site $site)
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $conversations = Conversation::where('site_id', $site->id)
            ->where('user_id', $request->user()->id)
            ->whereNull('visitor_id')
            ->orderByDesc('updated_at')
            ->get(['id', 'title', 'created_at', 'updated_at']);

        return response()->json(['data' => $conversations]);
    }

    public function show(Request $request, Site $site, Conversation $conversation)
    {
        abort_unless($request->user()?->isAdmin(), 403);
        abort_unless($conversation->site_id === $site->id && $conversation->user_id === $request->user()->id, 404);

        $messages = $conversation->messages()->reorder('created_at', 'asc')->get(['id', 'role', 'content', 'created_at']);

        return response()->json(['data' => ['id' => $conversation->id, 'title' => $conversation->title, 'messages' => $messages]]);
    }

    public function rename(Request $request, Site $site, Conversation $conversation)
    {
        abort_unless($request->user()?->isAdmin(), 403);
        abort_unless($conversation->site_id === $site->id && $conversation->user_id === $request->user()->id, 404);

        $validated = $request->validate(['title' => ['required', 'string', 'max:120']]);
        $conversation->update(['title' => $validated['title']]);

        return response()->json(['data' => $conversation]);
    }

    public function destroy(Request $request, Site $site, Conversation $conversation)
    {
        abort_unless($request->user()?->isAdmin(), 403);
        abort_unless($conversation->site_id === $site->id && $conversation->user_id === $request->user()->id, 404);

        $conversation->delete();
        return response()->json(['status' => 'deleted']);
    }

    /**
     * Délègue à ChatController::ask() — aucune divergence de comportement
     * (mémoire structurée, CTA, Mercure) entre le widget public et ce
     * copilote interne. Seule différence : garde d'accès admin explicite.
     */
    public function ask(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403);
        return $this->chatController->ask($request);
    }
}
