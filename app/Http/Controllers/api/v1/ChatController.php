<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMemory;
use App\Models\Message;
use App\Models\MessageCTA;
use App\Models\Site;
use App\Services\ia\ChatService;
use App\Services\ia\EmbeddingService;
use App\Services\MercureService;
use App\Services\vector\VectorCreationService;
use App\Services\vector\VectorIndexService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChatController extends Controller
{

    public function __construct(
        private ChatService $chatService,
        private MercureService $mercureService,
        private VectorCreationService $vectorCreationService,
        private VectorIndexService $vectorIndexService,
        private EmbeddingService $embeddingService,

    ){}
    public function ask(Request $request)
    {

        $data = $request->validate([
            'site_id' => 'required|exists:sites,id',
            'question' => 'required|string|max:1000',
            'conversation_id' => 'nullable|exists:conversations,id',
            'visitor_id' => 'nullable|exists:visitors,id',
        ]);

        $userId = auth()->id();
        $visitorId = $data['visitor_id'] ?? null;

        if (!$userId && !$visitorId) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 401);
        }

        $site = Site::where('id', $data['site_id'])
            ->firstOrFail();

        // 🔑 Continuité OU nouvelle conversation
        if (!empty($data['conversation_id'])) {
            $conversation = Conversation::where('id', $data['conversation_id'])
                ->where('site_id', $site->id) // ✅ sécurité supplémentaire
                ->when($userId, fn ($q) => $q->where('user_id', $userId))
                ->when(!$userId && $visitorId, fn ($q) => $q->where('visitor_id', $visitorId))
                ->firstOrFail();
        } else {
            $conversation = Conversation::create([
                'site_id' => $site->id,
                'user_id' => $userId,
                'visitor_id' => $visitorId,
            ]);

           /* $isCreated = $this->vectorCreationService->createSiteCollection(
                siteId: $site->id,
                collection: "conversations_{$conversation->id}"
            );

            if ($isCreated) {
                Log::info("Création de la collection réussit", [
                    'collection' => "conversations_{$conversation->id}",
                ]);
            }*/
        }

        // Sauvegarder la question
        $userMessage = Message::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'user_id' => $userId,
            'role' => 'user',
            'content' => $data['question'],
        ]);

        // ────────────────
        // 1️⃣ Mémoire structurée
        // ────────────────
        //$messageCount = Message::where('conversation_id', $conversation->id)->count();
        $messageCount = $conversation->messages()->count();

        Log::info("Nombre de message", [
            "MessageCount" => $messageCount,
            "Conversation Message Count" => $conversation->messages->count()
        ]);

        if ($messageCount === 1) {
            // Premier message => extraction immédiate
            $memory = $this->chatService->extractStructuredMemoryFromMessage($userMessage);

            //dd($memory);
            if (!empty($memory)) {
                DB::table('conversation_memories')->updateOrInsert(
                    ['conversation_id' => $conversation->id],
                    [
                        'id' => (string) Str::uuid(),
                        'memory' => json_encode($memory),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }

        //dd("On verifie", ConversationMemory::where('conversation_id', $conversation->id)->get());

        Log::info("Avant mercure");

        $topic = "/sites/{$site->id}/conversations/{$conversation->id}";

        $this->mercureService->post($topic, [
            'type' => 'user_message',
            'conversation_id' => $conversation->id,
            'content' => $data['question'],
            'created_at' => now()->toISOString(),
        ]);


        // Générer la réponse (🧠 avec mémoire)
        $chatResponse = $this->chatService->answer(
            site: $site,
            question: $data['question'],
            conversation: $conversation
        );

        // Sauvegarder la réponse
        /**
         * @var Message $botMessage
         */
        $botMessage = Message::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'user_id' => $userId,
            'role' => 'bot',
            'content' => $chatResponse->message, // texte LLM uniquement
            'entities' => $chatResponse->entities,
        ]);

        Log::info("LES CTA'S", [
            "ctas" => $chatResponse->ctas
        ]);

        Log::info("LES ENTITIES", [
            "ctas" => $chatResponse->entities
        ]);

        foreach ($chatResponse->ctas as $index => $cta) {

            MessageCta::create([
                'id' => (string) Str::uuid(),
                'message_id' => $botMessage->id,
                'cta_id' => $cta['id'],
                'position' => $index,

                // snapshot
                'label' => $cta['label'],
                'action' => $cta['action'],
                'value' => $cta['value'] ?? null,
                'style' => $cta['style'] ?? null,
            ]);

        }


        $this->mercureService->post($topic, [
            'type' => 'bot_message',
            'conversation_id' => $conversation->id,
            'content' => $chatResponse->message,
            'ctas' => $chatResponse->ctas, // ajout CTA
            'entities' => $chatResponse->entities,
            'created_at' => now()->toISOString(),
        ]);

        $messageCount = $conversation->messages()->count(); // Je recalcule

        if ($messageCount % 5 === 0) {
            // ✅ Ici, après l’indexation et avant d’envoyer la réponse
            $this->chatService->updateConversationMemory($conversation); // ✅ mémoire structurée
        }

        if ($messageCount % 8 === 0){
            $this->chatService->updateConversationSummary($conversation);
        }


        return response()->json([
            'answer' => $chatResponse->message,
            'ctas' => $chatResponse->ctas, // front-end peut directement afficher
            'entities' => $chatResponse->entities,
            'conversation_id' => $conversation->id,
        ]);
    }
}
