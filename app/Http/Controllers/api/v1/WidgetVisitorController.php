<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Visitor;
use App\Models\WidgetSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WidgetVisitorController extends Controller
{
    public function init(Request $request)
    {
        $request->validate([
            'site_id' => 'required|uuid',
            'visitor_uuid' => 'required|uuid'
        ]);


        $visitor = Visitor::where('site_id', $request->site_id)
            ->where('uuid', $request->visitor_uuid)
            ->first();

        if (!$visitor) {

            $visitor = Visitor::create([
                'id' => (string) Str::uuid(),
                'site_id' => $request->site_id,
                'uuid' => $request->visitor_uuid,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'device' => $this->detectDevice($request->userAgent())
            ]);
        }

        return response()->json([
            'visitor_id' => $visitor->id
        ]);
    }

    public function chat(Request $request)
    {

        $data = $request->validate([
            'site_id' => 'required|exists:sites,id',
            'visitor_uuid' => 'required|uuid',
            'question' => 'required|string|max:1000',
            'conversation_id' => 'nullable|uuid'
        ]);

        $site = Site::findOrFail($data['site_id']);

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
            'conversation_id' => $data['conversation_id'] ?? null
        ]);

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

        // =====================================
        // 🚫 3. Vérifier si le widget est activé
        // =====================================
        /*if (!$settings->widget_enabled) {
            return response()->json([
                'success' => false,
                'error'   => 'WIDGET_DISABLED',
            ], 403);
        }*/

        // =====================
        // ✅ 4. Retourner la config
        // =====================
        return response()->json([
            'success' => true,
            'config' => $settings,
        ]);
    }
}
