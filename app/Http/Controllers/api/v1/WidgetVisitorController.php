<?php

namespace App\Http\Controllers\api\v1;

use App\Enums\AnalyticsEventType;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Site;
use App\Models\Visitor;
use App\Models\WidgetSetting;
use App\Services\analytics\AnalyticsEventService;
use App\Services\MercureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WidgetVisitorController extends Controller
{
    public function __construct(
        private readonly AnalyticsEventService $analytics,
        private readonly MercureService $mercure,
    )
    {
    }

    public function init(Request $request)
    {
        $request->validate([
            'site_id' => 'required|uuid',
            'visitor_uuid' => 'required|uuid',
            'session_id' => 'required|string|max:100',
        ]);

        $site = Site::findOrFail($request->site_id);

        if ($response = $this->widgetDisabledResponse($site)) {
            return $response;
        }

        $visitor = Visitor::where('site_id', $site->id)
            ->where('uuid', $request->visitor_uuid)
            ->first();

        if (!$visitor) {

            $visitor = Visitor::create([
                'id' => (string) Str::uuid(),
                'site_id' => $site->id,
                'uuid' => $request->visitor_uuid,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'device' => $this->detectDevice($request->userAgent())
            ]);
        }

        $this->analytics->capture(
            $site,
            AnalyticsEventType::WIDGET_OPENED,
            [
                'visitor_id' => $visitor->id,
                'session_id' => $request->string('session_id')->toString(),
                'correlation_id' => $request->string('session_id')->toString(),
                'source' => 'widget',
                'channel' => 'widget',
            ],
            metadata: ['device' => $visitor->device],
            idempotencyKey: $this->analytics->deterministicKey(
                'widget_opened', $site->id, $visitor->id, $request->string('session_id')->toString(),
            ),
        );

        return response()->json([
            'visitor_id' => $visitor->id
        ]);
    }

    public function chat(Request $request)
    {

        $data = $request->validate([
            'site_id' => 'required|exists:sites,id',
            'visitor_uuid' => 'required|uuid',
            'question' => 'nullable|string|max:1000',
            'conversation_id' => 'nullable|uuid',
            'session_id' => 'nullable|string|max:100',
            // 🖼️ Upload d'image pendant la conversation (visiteur widget)
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp,gif|max:8192',
        ]);

        if (empty($data['question']) && !$request->hasFile('image')) {
            return response()->json([
                'message' => 'La question ou une image est requise.'
            ], 422);
        }

        $site = Site::findOrFail($data['site_id']);

        // Le script public masque déjà le widget côté navigateur. Ce garde-fou
        // serveur évite néanmoins qu'un ancien iframe ou un appel direct puisse
        // continuer à envoyer des messages après désactivation.
        if ($response = $this->widgetDisabledResponse($site)) {
            return $response;
        }

        // 1️⃣ récupérer visitor
        $visitor = Visitor::where('site_id', $site->id)
            ->where('uuid', $data['visitor_uuid'])
            ->first();

        // 2️⃣ créer visitor si inexistant
        if (!$visitor) {

            $visitor = Visitor::create([
                'id' => (string) Str::uuid(),
                'site_id' => $site->id,
                'uuid' => $data['visitor_uuid'],
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'device' => $this->detectDevice($request->userAgent())
            ]);
        }

        //dd($site->id, $visitor->id, $visitor->uuid, $data['question'], $data['conversation_id'] ?? null);

        // 3️⃣ appeler endpoint interne
        $internalRequest = new Request([
            'site_id' => $site->id,
            'visitor_id' => $visitor->id,
            'question' => $data['question'],
            'conversation_id' => $data['conversation_id'] ?? null,
            'session_id' => $data['session_id'] ?? null,
        ]);

        // 🖼️ Transmettre le fichier image (FileBag) à la requête interne —
        // `new Request([...])` ne prend que les champs simples, les fichiers
        // uploadés doivent être transférés explicitement.
        if ($request->hasFile('image')) {
            $internalRequest->files->set('image', $request->file('image'));
        }

        $chatController = app(ChatController::class);

        //dd($chatController->ask($internalRequest));
        return $chatController->ask($internalRequest);
    }
    private function detectDevice($userAgent)
    {
        if (!$userAgent) {
            return null;
        }

        if (str_contains(strtolower($userAgent), 'mobile')) {
            return 'mobile';
        }

        if (str_contains(strtolower($userAgent), 'tablet')) {
            return 'tablet';
        }

        return 'desktop';
    }

    private function widgetDisabledResponse(Site $site): ?JsonResponse
    {
        $enabled = WidgetSetting::query()
            ->where('site_id', $site->id)
            ->value('widget_enabled');

        // Une configuration absente reste compatible avec les sites existants
        // et est donc considérée comme activée.
        if (!in_array($enabled, [false, 0, '0'], true)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'error' => 'WIDGET_DISABLED',
            'message' => 'Le widget est désactivé pour ce site.',
        ], 403);
    }

    public function visitorMessages(Request $request, string $conversationId, string $siteId)
    {
        $data = $request->validate([
            //'site_id' => 'required|exists:sites,id',
            'visitor_uuid' => 'required|string',
        ]);

        $site = Site::findOrFail($siteId);

        $visitor = Visitor::where('uuid', $data['visitor_uuid'])
            ->where('site_id', $site->id)
            ->firstOrFail();

        $conversation = Conversation::where('id', $conversationId)
            ->where('visitor_id', $visitor->id)
            ->where('site_id', $site->id)
            ->with(['messages', 'messages.displayedCtas'])
            ->firstOrFail();

        return response()->json($conversation);
    }

    public function visitorConversations(Request $request, string $siteId)
    {
        $data = $request->validate([
            //'site_id' => 'required|exists:sites,id',
            'visitor_uuid' => 'required|uuid',
        ]);

        $site = Site::findOrFail($siteId);


        $visitor = Visitor::where('uuid', $data['visitor_uuid'])
            ->where('site_id', $site->id)
            ->firstOrFail();

        $conversations = Conversation::with(['messages', 'messages.displayedCtas'])
            ->where('site_id', $site->id)
            ->where('visitor_id', $visitor->id)
            ->get();

        return response()->json($conversations);
    }

    /**
     * POST /widget/conversations/{conversationId}/{siteId}/read
     *
     * Marque comme lus tous les messages lorsque le visiteur ouvre la
     * conversation. L'identité du visiteur est vérifiée avant toute mise à
     * jour.
     */
    public function markMessagesRead(Request $request, string $conversationId, string $siteId): JsonResponse
    {
        $data = $request->validate([
            'visitor_uuid' => ['required', 'uuid'],
        ]);

        $site = Site::findOrFail($siteId);
        $visitor = Visitor::query()
            ->where('site_id', $site->id)
            ->where('uuid', $data['visitor_uuid'])
            ->firstOrFail();

        $conversation = Conversation::query()
            ->whereKey($conversationId)
            ->where('site_id', $site->id)
            ->where('visitor_id', $visitor->id)
            ->firstOrFail();

        $readAt = now();
        $readMessageIds = Message::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('visitor_read_at')
            ->pluck('id')
            ->values()
            ->all();

        if ($readMessageIds === []) {
            return response()->json([
                'success' => true,
                'conversation_id' => $conversation->id,
                'message_ids' => [],
                'read_at' => null,
            ]);
        }

        Message::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('id', $readMessageIds)
            ->whereNull('visitor_read_at')
            ->update(['visitor_read_at' => $readAt]);

        return $this->publishReadState($conversation, 'visitor_conversation_read', $readMessageIds, $readAt);
    }

    private function publishReadState(
        Conversation $conversation,
        string $eventType,
        array $readMessageIds,
        \Illuminate\Support\Carbon $readAt,
    ): JsonResponse
    {
        $readAtIso = $readAt->toISOString();
        $this->mercure->post("/sites/{$conversation->site_id}/conversations/{$conversation->id}", [
            'type' => $eventType,
            'conversation_id' => $conversation->id,
            'message_ids' => $readMessageIds,
            'read_at' => $readAtIso,
        ]);

        return response()->json([
            'success' => true,
            'conversation_id' => $conversation->id,
            'message_ids' => $readMessageIds,
            'read_at' => $readAtIso,
        ]);
    }

    public function widgetConfig(string $site_id): JsonResponse
    {
        // =====================
        // 🔍 1. Vérifier le site
        // =====================
        $site = Site::query()
            ->where('id', $site_id)
            ->first();

        if (!$site) {
            return response()->json([
                'success' => false,
                'error'   => 'SITE_NOT_FOUND',
            ], 404);
        }

        // ======================================================
        // ⚙️ 2. Créer les settings s'ils n'existent pas (Option B)
        // ======================================================
        $settings = WidgetSetting::query()->firstOrCreate(
            ['site_id' => $site->id],
            [
                'id' => Str::uuid(),
                'site_id' => $site->id,
            ]
        );

        $settings->refresh();

        // =====================
        // ✅ 3. Retourner la config
        // =====================
        return response()->json([
            'success' => true,
            'config' => $settings,
        ]);
    }
}
