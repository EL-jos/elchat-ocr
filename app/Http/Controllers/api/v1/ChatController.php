<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageCTA;
use App\Models\Site;
use App\Services\ia\ChatService;
use App\Services\ia\EmbeddingService;
use App\Services\mcp\MCPActionGateService;
use App\Services\MercureService;
use App\Services\vector\VectorCreationService;
use App\Services\vector\VectorIndexService;
use App\Services\vision\ImageVisionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        private ImageVisionService $imageVisionService,
        private MCPActionGateService $mcpActionGateService, // 🆕
    ){}
    public function ask(Request $request)
    {

        $data = $request->validate([
            'site_id' => 'required|exists:sites,id',
            // 🖼️ La question devient optionnelle : un visiteur peut envoyer
            // uniquement une image ("qu'est-ce que c'est ?" implicite).
            'question' => 'nullable|string|max:1000',
            'conversation_id' => 'nullable|exists:conversations,id',
            'visitor_id' => 'nullable|exists:visitors,id',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp,gif|max:8192', // 8 Mo, cohérent avec vision.max_image_bytes
        ]);

        if (empty($data['question']) && !$request->hasFile('image')) {
            return response()->json([
                'message' => 'La question ou une image est requise.'
            ], 422);
        }

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

        }

        // ─────────────────────────────
        // 🖼️ Pièce jointe image (upload visiteur pendant la conversation)
        // ─────────────────────────────
        $attachmentUrl = null;
        $visionResult = null;
        //$attachement = new MessageAttachment();

        if ($request->hasFile('image')) {

            $files = $request->file('image');

            $bytes = file_get_contents($files->getRealPath());

            // Même service, même cache global (par hash d'octets) que le
            // crawl / les documents / les produits : si le visiteur envoie
            // la photo d'un produit déjà indexé, aucun appel vision n'est
            // refait, la description existante est réutilisée instantanément.
            $visionResult = $this->imageVisionService->analyzeBytes(
                $bytes,
                alt: null,
                context: $data['question'] ?? null,
                logRef: "visitor-upload:{$conversation->id}",
            );

            /*$document = $this->saveDocument($files, $attachement, 'image');
            $attachmentUrl = $document->url;*/
        }

        // 🧠 Requête enrichie envoyée au pipeline RAG : le texte du visiteur
        // + la description/OCR de l'image, pour que le retrieval (produits,
        // pages, documents, images déjà indexées) et la génération LLM
        // "voient" le contenu visuel sans que le LLM principal ait besoin
        // d'être multimodal.
        $rawQuestion = trim($data['question'] ?? '');
        $enrichedQuestion = $rawQuestion;

        if ($visionResult) {
            $visionText = trim(implode("\n", array_filter([
                !empty($visionResult['description']) ? "Description de l'image envoyée : {$visionResult['description']}" : null,
                !empty($visionResult['ocr_text']) ? "Texte visible sur l'image : {$visionResult['ocr_text']}" : null,
            ])));

            if ($visionText !== '') {
                $enrichedQuestion = $rawQuestion !== ''
                    ? "{$rawQuestion}\n\n[Image jointe par le visiteur]\n{$visionText}"
                    : "Le visiteur a envoyé une image sans texte. Voici ce qu'elle contient :\n{$visionText}\n\nDécris ce que tu peux en dire, et indique si un produit ou une information de notre catalogue y correspond.";
            }
        }

        if ($enrichedQuestion === '') {
            // Image envoyée mais non analysable (décorative/illisible) et pas de texte
            $enrichedQuestion = "Le visiteur a envoyé une image, mais son contenu n'a pas pu être analysé. Demande-lui de préciser sa question.";
        }

        // Sauvegarder la question
        $userMessage = Message::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'user_id' => $userId,
            'role' => 'user',
            // 🖼️ On garde le contenu affiché à l'utilisateur PROPRE (son texte
            // brut, pas la description/OCR) : l'historique visuel du chat ne
            // doit pas s'encombrer du texte extrait de l'image.
            'content' => $rawQuestion !== '' ? $rawQuestion : '📷 Image envoyée',
        ]);


        if ($request->hasFile('image')) {

            $attachement = MessageAttachment::create([
                'id' => (string) Str::uuid(),
                'message_id' => $userMessage->id,
                'type' => 'image',
                'url' => "unknown",
                'content_hash' => $visionResult['content_hash'] ?? null,
                'description' => $visionResult['description'] ?? null,
                'ocr_text' => $visionResult['ocr_text'] ?? null,
            ]);

            if($attachement){
                $document = $this->saveDocument($files, $attachement, 'image');
                $attachmentUrl = asset($document->path);
                $attachement->update(['url' => $attachmentUrl]);
            }
        }

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
            'content' => $userMessage->content,
            // 🖼️ objet minimal (pas besoin du MessageAttachment complet côté front)
            'attachment' => $attachmentUrl ? [
                'url' => $attachmentUrl,
                'type' => 'image',
            ] : null,
            'created_at' => now()->toISOString(),
        ]);


        // Générer la réponse (🧠 avec mémoire)
        $chatResponse = $this->chatService->answer(
            site: $site,
            question: $enrichedQuestion,
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
            // 🖼️ même format que l'event Mercure, pour un traitement unifié côté front
            'attachment' => $attachmentUrl ? [
                'url' => $attachmentUrl,
                'type' => 'image',
            ] : null,
            // le widget peut afficher immédiatement la vignette envoyée.
            'user_message_attachment_url' => $attachmentUrl,
            // 🆕 non-null seulement si une action nécessite une confirmation
            'pending_mcp_confirmation' => $chatResponse->pendingConfirmation,
        ]);
    }

    private function moveImage($file)
    {
        $currentDateTime = Carbon::now();
        $formattedDateTime = $currentDateTime->format('Ymd_His');

        $path_file = (string) Str::uuid() . '.' . $file->getClientOriginalExtension();
        $file->move(public_path('assets/resources/chats/'), $path_file);

        return "assets/resources/chats/" . $path_file;
    }
    // Méthode pour supprimer une image
    private function deleteImage($path)
    {
        if ( file_exists( public_path($path) ) ) {
            unlink(public_path($path));
        }
    }
    private function saveDocument($files, MessageAttachment $attachement, string $type){

        $document = null;
        if (is_array($files)) {

            foreach ($files as $file) {
                $documentPath = $this->moveImage($file);
                $extension = $files->getClientOriginalExtension();
                $document = new Document([ 'id' => (string) Str::uuid(), 'path' => $documentPath, 'type' => $type, 'extension' => $extension]);
                $document = $attachement->documents()->save($document);
            }

        } else {

            $documentPath = $this->moveImage($files);
            $extension = $files->getClientOriginalExtension();
            $document = new Document([ 'id' => (string) Str::uuid(), 'path' => $documentPath, 'type' => $type, 'extension' => $extension]);
            $document = $attachement->documents()->save($document);

        }

        return $document;
    }

    public function confirmMcpAction(Request $request, Conversation $conversation)
    {
        $validated = $request->validate([
            'approved' => ['required', 'boolean'],
            'connector' => ['required', 'string'],
            'tool' => ['required', 'string'],
            'params' => ['array'],
            'tool_call_id' => ['required', 'string'],
            'messages' => ['required', 'array'], // renvoyé tel quel depuis pending_mcp_confirmation
        ]);

        $site = $conversation->site;

        $result = $this->mcpActionGateService->resumeAfterConfirmation(
            site: $site,
            conversation: $conversation,
            pendingMessages: $validated['messages'],
            connectorSlug: $validated['connector'],
            toolName: $validated['tool'],
            params: $validated['params'] ?? [],
            toolCallId: $validated['tool_call_id'],
            approved: $validated['approved'],
        );

        $chatResponse = $result->response ?? new \App\Services\cta\ChatResponse(
            message: "D'accord, cette action a été annulée.",
            ctas: [],
            entities: [],
        );

        $botMessage = Message::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'role' => 'bot',
            'content' => $chatResponse->message,
        ]);

        $this->mercureService->post("/sites/{$site->id}/conversations/{$conversation->id}", [
            'type' => 'bot_message',
            'conversation_id' => $conversation->id,
            'content' => $chatResponse->message,
            'ctas' => [],
            'entities' => [],
            'created_at' => now()->toISOString(),
        ]);

        return response()->json(['answer' => $chatResponse->message]);
    }
}
