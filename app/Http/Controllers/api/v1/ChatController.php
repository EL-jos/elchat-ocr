<?php

namespace App\Http\Controllers\api\v1;

use App\Enums\AnalyticsEventType;
use App\Jobs\UpdateConversationContextJob;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageCTA;
use App\Models\Site;
use App\Services\analytics\ResourceEventLogger;
use App\Services\analytics\AnalyticsEventService;
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
        private ResourceEventLogger $resourceEventLogger, // 🆕
        private AnalyticsEventService $analytics,
    ){}
    public function ask(Request $request)
    {

        $data = $request->validate([
            'site_id' => 'required|exists:sites,id',
            // 🖼️ La question devient optionnelle : un visiteur peut envoyer
            // uniquement une image ("qu'est-ce que c'est ?" implicite).
            'question' => 'nullable|string|max:1000',
            // Le widget peut réserver un UUID local avant le traitement afin
            // de s'abonner à Mercure dès le premier message.
            'conversation_id' => 'nullable|uuid',
            'visitor_id' => 'nullable|exists:visitors,id',
            'session_id' => 'nullable|string|max:100',
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

        // 🔑 Continuité OU nouvelle conversation. Un widget peut fournir un
        // UUID encore inexistant pour s'abonner au topic Mercure avant le
        // début du traitement LLM.
        $requestedConversationId = $data['conversation_id'] ?? null;
        $conversationAlreadyExists = $requestedConversationId !== null
            && Conversation::whereKey($requestedConversationId)->exists();
        $isNewConversation = !$conversationAlreadyExists;

        if (!$isNewConversation) {
            $conversation = Conversation::where('id', $requestedConversationId)
                ->where('site_id', $site->id) // ✅ sécurité supplémentaire
                ->when($userId, fn ($q) => $q->where('user_id', $userId))
                ->when(!$userId && $visitorId, fn ($q) => $q->where('visitor_id', $visitorId))
                ->firstOrFail();
        } else {
            $conversation = new Conversation([
                'site_id' => $site->id,
                'user_id' => $userId,
                'visitor_id' => $visitorId,
                'metadata' => array_filter([
                    'channel' => $visitorId ? 'widget' : 'admin',
                    'session_id' => $data['session_id'] ?? null,
                ]),
            ]);
            // saveQuietly() conserve l'UUID fourni par le widget. Le modèle
            // génère toujours son UUID habituel lorsque ce champ est absent.
            $conversation->id = $requestedConversationId ?? (string) Str::uuid();
            $conversation->saveQuietly();

        }

        $channel = $conversation->metadata['channel'] ?? ($visitorId ? 'widget' : 'admin');
        $sessionId = $data['session_id'] ?? $conversation->metadata['session_id'] ?? null;

        if ($sessionId && empty($conversation->metadata['session_id'])) {
            $conversation->update([
                'metadata' => [...($conversation->metadata ?? []), 'channel' => $channel, 'session_id' => $sessionId],
            ]);
        }

        if ($isNewConversation) {
            $this->analytics->capture(
                $site,
                AnalyticsEventType::CONVERSATION_STARTED,
                [
                    'visitor_id' => $conversation->visitor_id,
                    'conversation_id' => $conversation->id,
                    'session_id' => $sessionId,
                    'correlation_id' => $sessionId ?? $conversation->id,
                    'source' => 'chat',
                    'channel' => $channel,
                ],
                idempotencyKey: $this->analytics->deterministicKey('conversation_started', $conversation->id),
            );
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

        // 🆕 Titre auto pour les conversations authentifiées (admin/copilote interne),
        // dérivé du premier message — jamais écrasé une fois posé.
        if (!$conversation->title && $userId) {
            $conversation->update(['title' => Str::limit($rawQuestion ?: 'Nouvelle conversation', 60)]);
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

        $this->analytics->capture(
            $site,
            AnalyticsEventType::MESSAGE_SENT,
            [
                'visitor_id' => $conversation->visitor_id,
                'conversation_id' => $conversation->id,
                'message_id' => $userMessage->id,
                'session_id' => $sessionId,
                'correlation_id' => $sessionId ?? $conversation->id,
                'source' => 'chat',
                'channel' => $channel,
            ],
            metadata: ['has_image' => $request->hasFile('image')],
            idempotencyKey: $this->analytics->deterministicKey('message_sent', $userMessage->id),
        );


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

        // Le premier message conserve son déclenchement mémoire, mais
        // l'extraction est exécutée après la réponse par le job différé.
        $isFirstConversationMessage = $messageCount === 1;

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

        $this->analytics->capture(
            $site,
            AnalyticsEventType::MESSAGE_RECEIVED,
            [
                'visitor_id' => $conversation->visitor_id,
                'conversation_id' => $conversation->id,
                'message_id' => $botMessage->id,
                'session_id' => $sessionId,
                'correlation_id' => $sessionId ?? $conversation->id,
                'source' => 'chat',
                'channel' => $channel,
            ],
            idempotencyKey: $this->analytics->deterministicKey('message_received', $botMessage->id),
        );

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

        // Les impressions CTA sont enregistrées par le widget lorsqu'elles sont
        // réellement affichées. Les recommandations d'entités sont, elles,
        // observables dès leur inclusion dans la réponse.
        $this->resourceEventLogger->logEntityImpressions($site, $conversation, $botMessage, $chatResponse->entities);


        $this->mercureService->post($topic, [
            'type' => 'bot_message',
            'conversation_id' => $conversation->id,
            // Le widget utilise cet identifiant pour rattacher les impressions
            // et les clics CTA au message réellement enregistré. Sans lui, le
            // widget génère un UUID local qui est rejeté par l'endpoint public
            // de tracking (le message n'existe pas en base).
            'message_id' => $botMessage->id,
            'content' => $chatResponse->message,
            'ctas' => $chatResponse->ctas, // ajout CTA
            'entities' => $chatResponse->entities,
            'suggested_actions' => $chatResponse->suggestedActions, // 🆕
            'created_at' => now()->toISOString(),
        ]);

        $messageCount = $conversation->messages()->count(); // Je recalcule

        $updateMemory = ($messageCount % 5 === 0) || $chatResponse->memoryRefreshRequested;
        $updateSummary = $messageCount % 8 === 0;

        if ($updateMemory || $updateSummary) {
            // Le job est enregistré après l'envoi de la réponse HTTP : les deux
            // appels LLM de contexte ne ralentissent donc pas le visiteur.
            UpdateConversationContextJob::dispatchAfterResponse(
                conversationId: (string) $conversation->id,
                updateMemory: $updateMemory,
                updateSummary: $updateSummary,
                memoryMessageId: $isFirstConversationMessage ? (string) $userMessage->id : null,
            );
        }


        return response()->json([
            'answer' => $chatResponse->message,
            'ctas' => $chatResponse->ctas, // front-end peut directement afficher
            'entities' => $chatResponse->entities,
            'conversation_id' => $conversation->id,
            // Même identifiant que dans Mercure afin que le fallback HTTP et
            // le flux temps réel produisent des événements cohérents.
            'message_id' => $botMessage->id,
            // 🖼️ même format que l'event Mercure, pour un traitement unifié côté front
            'attachment' => $attachmentUrl ? [
                'url' => $attachmentUrl,
                'type' => 'image',
            ] : null,
            // le widget peut afficher immédiatement la vignette envoyée.
            'user_message_attachment_url' => $attachmentUrl,
            // 🆕 non-null uniquement si une action attend une confirmation DU VISITEUR.
            // Si confirm_actor === 'admin', on ne renvoie rien de spécifique ici : le
            // visiteur ne doit pas voir de bouton "confirmer" pour une action qui ne
            // le concerne pas (message d'attente standard déjà dans $chatResponse->message).
            'pending_mcp_confirmation' => $chatResponse->pendingConfirmation,
            'suggested_actions' => $chatResponse->suggestedActions, // 🆕
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

}
