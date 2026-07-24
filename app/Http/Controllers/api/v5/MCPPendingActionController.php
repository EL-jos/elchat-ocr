<?php

namespace App\Http\Controllers\api\v5;

use App\Domain\MCP\Orchestration\MCPGateResult;
use App\Http\Controllers\Controller;
use App\Models\Mcp\McpPendingAction;
use App\Models\Message;
use App\Models\Site;
use App\Services\cta\ChatResponse;
use App\Services\mcp\MCPActionGateService;
use App\Services\MercureService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MCPPendingActionController extends Controller
{
    public function __construct(
        private readonly MCPActionGateService $gate,
        private readonly MercureService $mercureService,
    ) {
    }

    /**
     * File d'attente back-office : actions nécessitant une validation admin,
     * pour CE site. Protégez cette route avec votre middleware admin habituel.
     */
    public function index(Request $request, Site $site)
    {
        if (!$request->user()?->isAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $pending = McpPendingAction::where('site_id', $site->id)
            ->where('confirm_actor', 'admin')
            ->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('created_at')
            ->with('conversation')
            ->get()
            ->map(fn (McpPendingAction $a) => [
                'id' => $a->id,
                'connector' => $a->connector_slug,
                'tool' => $a->tool_name,
                'params' => $a->params,
                'conversation_id' => $a->conversation_id,
                'created_at' => $a->created_at,
            ]);

        return response()->json(['data' => $pending]);
    }

    /**
     * Résout une action en attente. Autorisation :
     * - confirm_actor === 'admin' -> requiert un User admin authentifié.
     * - confirm_actor === 'visitor' -> requiert que la requête provienne bien
     *   du visiteur/utilisateur propriétaire de la conversation concernée
     *   (même vérification que ChatController::ask).
     */
    public function resolve(Request $request, McpPendingAction $pendingAction)
    {
        $validated = $request->validate(['approved' => ['required', 'boolean']]);

        if ($pendingAction->status !== 'pending') {
            return response()->json(['message' => 'Cette action a déjà été traitée.'], 409);
        }

        $userId = auth()->id();
        $visitorId = $request->input('visitor_id');

        if ($pendingAction->confirm_actor === 'admin') {
            if (!$request->user()?->isAdmin()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        } else {
            $conversation = $pendingAction->conversation;
            $owns = ($userId && $conversation->user_id === $userId) || ($visitorId && $conversation->visitor_id === $visitorId);
            if (!$owns) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        $pendingAction->update([
            'status' => $validated['approved'] ? 'approved' : 'rejected',
            'resolved_by_user_id' => $userId,
            'resolved_at' => now(),
        ]);

        $site = $pendingAction->site;
        /** @var MCPGateResult $result */
        $result = $this->gate->resumeAfterConfirmation($site, $pendingAction, $validated['approved']);

        $chatResponse = $result->response ?? new ChatResponse(message: "D'accord, cette action a été annulée.", ctas: [], entities: []);

        Message::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $pendingAction->conversation_id,
            'role' => 'bot',
            'content' => $chatResponse->message,
        ]);

        $this->mercureService->post("/sites/{$site->id}/conversations/{$pendingAction->conversation_id}", [
            'type' => 'bot_message',
            'conversation_id' => $pendingAction->conversation_id,
            'content' => $chatResponse->message,
            'ctas' => [], 'entities' => [],
            'suggested_actions' => $chatResponse->suggestedActions ?? [], // 🆕
            'created_at' => now()->toISOString(),
        ]);

        return response()->json(['answer' => $chatResponse->message]);
    }
}
