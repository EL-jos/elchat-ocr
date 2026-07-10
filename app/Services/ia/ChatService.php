<?php
namespace App\Services\ia;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Site;
use App\Models\WidgetSetting;
use App\Services\cta\ChatResponse;
use App\Services\hops\HopResponse;
use App\Services\hops\MultiHopPipelineService;
use App\Services\hops\MultiHopPipelineServiceV2;
use App\Services\hops\SingleHopPipelineService;
use App\Services\MercureService;
use App\Services\queryAnalyzer\IntentRouter;
use App\Services\queryAnalyzer\LeadService;
use App\Services\queryAnalyzer\NavigationService;
use App\Services\queryAnalyzer\QueryAnalyzer;
use App\Services\queryAnalyzer\TransactionService;
use App\Services\validator\AnswerValidatorService;
use App\Traits\TextNormalizer;
use Exception;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatService
{

    use TextNormalizer;

    protected array $handlers = [

        'lead_capture' => LeadService::class,
        'navigation' => NavigationService::class,
        'transaction_flow' => TransactionService::class,
    ];

    private array $entityLabels = [
        'product' => [
            'singular' => 'produit',
            'plural' => 'produits',
            'priority' => 1,
        ],
        'page' => [
            'singular' => 'page',
            'plural' => 'pages',
            'priority' => 2,
        ],
        'document' => [
            'singular' => 'document',
            'plural' => 'documents',
            'priority' => 3,
        ],
    ];
    protected HopResponse $results;

    public function __construct(

        protected IntentClassifier $intentClassifier,
        protected ConversationStateManager $conversationStateManager,
        protected ResponseGuard $responseGuard,

        protected QueryAnalyzer $queryAnalyzer,
        protected IntentRouter $intentRouter,

        protected MultiHopPipelineService $multiHopPipelineService,
        protected MultiHopPipelineServiceV2 $multiHopPipelineServiceV2,
        protected SingleHopPipelineService $singleHopPipelineService,

        protected AnswerValidatorService $answerValidatorService,
        protected ConversationRewriterService $conversationRewriterService,

        protected FollowUpDetector $followUpDetector,
        protected RetrievalQueryExpander $retrievalQueryExpander,

        protected MercureService $mercureService, // 🔹 ajouté
    )
    {}

    /**
     * Réponse commerciale incarnée (mode production)
     */
    public function answer(Site $site, string $question, Conversation $conversation): ChatResponse
    {

        // ─────────────────────────────
        // 0️⃣ Intent Classification
        // ─────────────────────────────
        $intent = $this->intentClassifier->classify($question);
        $earlyResponse = $this->conversationStateManager
            ->handle($intent, $conversation);

        if ($earlyResponse !== null) {
            return new ChatResponse(
                message: $earlyResponse,
                ctas: []
            );
        }

        $this->notifyThinking($site, $conversation, 'Analyse de votre demande...');

        // ─────────────────────────────
        // 1️⃣ Context Resolution (ONE TIME ONLY)
        // ─────────────────────────────
        $resolvedQuestion = $question;

        $followUp = $this->followUpDetector->isFollowUp(question: $question, conversation: $conversation);
        if($followUp){
            $resolvedQuestion = $this->conversationRewriterService->rewrite(question: $question, conversation: $conversation);
        }

        /*Log::info('Resolved Question', [
            'original' => $question,
            'resolved' => $resolvedQuestion,
            'follow_up' => $followUp,
        ]);*/

        // ─────────────────────────────
        // 2️⃣ Query Analysis
        // ─────────────────────────────
        $baseQueryPlan  = $this->queryAnalyzer->analyze(question: $resolvedQuestion, conversation: $conversation);

        // ─────────────────────────────
        // 3️⃣ Retrieval Expansion
        // ONLY used if previous attempt hallucinated
        // ─────────────────────────────
        $queries = array_values(array_unique([
            $resolvedQuestion,
            ...$this->retrievalQueryExpander->expand(query: $resolvedQuestion)
        ]));

        // ─────────────────────────────
        // 4️⃣ Short History
        // ─────────────────────────────
        $history = Message::where('conversation_id', $conversation->id)
            ->orderBy('created_at', 'desc')
            //->skip(1)
            ->take(6)
            ->get()
            ->reverse()
            ->map(function ($m) {
                if ($m->role === 'bot') {
                    return [
                        'role' => 'assistant',
                        //'content' => '[Résumé interne: réponse déjà fournie, informations factuelles uniquement, sans nouveaux produits ni promesses]',
                        'content' => $m->content,
                    ];
                }

                return [
                    'role' => 'user',
                    'content' => $m->content,
                ];
            })
            ->toArray();

        // ─────────────────────────────
        // Runtime State
        // ─────────────────────────────
        $bestScore = 0;

        $bestResponse = null;

        $bestValidation = null;

        $bestResults = null;

        $bestValidatedResponse = null;

        $previousHallucination = true;

        Log::info('Resolved Question', [
            'original' => $question,
            'resolved' => $resolvedQuestion,
            'follow_up' => $followUp,
            'baseQueryPlan' => $baseQueryPlan,
            'queries' => $queries

        ]);

        // ─────────────────────────────
        // 5️⃣ Retrieval Attempts
        // ─────────────────────────────

        foreach ($queries as $attemptIndex => $currentQuestion) {

            // IMPORTANT:
            // only continue attempts if previous failed
            // due to hallucination / grounding issue
            if (
                $attemptIndex > 0
                && $previousHallucination === false
            ) {
                break;
            }

            Log::info('🧠 Retrieval Attempt', [
                'attempt' => $attemptIndex + 1,
                'question' => $currentQuestion,
            ]);

            // Re-analyze current retrieval variant
            $queryPlan = $attemptIndex === 0
                ? $baseQueryPlan
                : $this->queryAnalyzer->analyze(question: $currentQuestion, conversation: $conversation);

            if ($attemptIndex === 0) {
                $this->notifyThinking($site, $conversation, 'Recherche dans la base de connaissances...');
            }

            // ─────────────────────────────
            // 6️⃣ Retrieval
            // ─────────────────────────────

            $results = $this->multiHopPipelineService->shouldUseMultiHop(queryPlan: $queryPlan)
                ? $this->multiHopPipelineServiceV2->handle(
                    question: $currentQuestion,
                    plan: $queryPlan,
                    site: $site,
                    conversation: $conversation,
                    history: $history
                )
                : $this->singleHopPipelineService->handle(
                    question: $currentQuestion,
                    queryPlan: $queryPlan,
                    site: $site,
                    conversation: $conversation,
                    history: $history
                );

            if (is_null($results->prompt) || (!is_null($results->message))) {
                continue;
            }

            if ($attemptIndex === 0) {
                $this->notifyThinking($site, $conversation, 'Synthèse de la réponse...');
            }

            // ─────────────────────────────
            // 7️⃣ Generation
            // ─────────────────────────────
            $response = $this->callLLM(
                site: $site,
                prompt: $results->prompt,
                question: $resolvedQuestion
            );

            // ─────────────────────────────
            // 8️⃣ Response Guard
            // ─────────────────────────────
            $validatedResponse = $this->responseGuard->validate(response: $response, conversation: $conversation);

            // ─────────────────────────────
            // 9️⃣ Validation
            // IMPORTANT:
            // validate FINAL guarded response
            // ─────────────────────────────
            $validation = $this->answerValidatorService->validate(
                question: $resolvedQuestion,
                answer: $response,
                context: $results->context
            );

            $score = $validation['final_score'] ?? 0;
            $hallucination = $validation['hallucination_risk'] ?? 1;
            $grounding = $validation['grounding'] ?? 0;
            $relevance = $validation['relevance'] ?? 0;

            Log::info('Validation Result', [
                'attempt' => $attemptIndex + 1,
                'score' => $score,
                'hallucination' => $hallucination,
                'grounding' => $grounding,
                'relevance' => $relevance,
                'response' => $response
            ]);

            // Track best candidate
            if ($score > $bestScore) {

                $bestScore = $score;

                $bestResponse = $response;

                $bestValidation = $validation;

                $bestResults = $results;

                $bestValidatedResponse = $validatedResponse;
            }

            // ─────────────────────────────
            // Determine retry condition
            // ─────────────────────────────
            $previousHallucination =
                $hallucination >= 0.3
                || $grounding < 0.4
                || $relevance < 0.5;

            /*if ($validation['grounding'] < 0.4) {
                // réponse plausible mais pas supportée
            }
            if ($validation['relevance'] < 0.5) {
                // hors sujet
            }
            if ($validation['hallucination_risk'] > 0.6) {
                Log::warning("⚠️ Hallucination détectée", $validation);
            }

            $hallucination = $validation['hallucination_risk'];

            if ($hallucination < 0.3 && $score >= $site->settings->min_similarity_score) {
                break;
            }*/

            // ─────────────────────────────
            // SUCCESS EXIT
            // ─────────────────────────────
            if (
                $hallucination < 0.3
                && $score >= $site->settings->min_similarity_score
            ) {

                Log::info('✅ Successful response selected', [
                    'attempt' => $attemptIndex + 1,
                ]);

                $bestResponse = $response;

                $bestValidation = $validation;

                $bestResults = $results;

                $bestValidatedResponse = $validatedResponse;

                break;
            }

        }
        // ─────────────────────────────
        // 🔟 FINAL DECISION
        // ─────────────────────────────
        if ($bestScore >= $site->settings->min_similarity_score) {

            if ($validatedResponse === "Cette information n’est pas disponible dans nos documents internes.") {
                // Ajoute un texte introductif pour contextualiser les entities

                if (!empty($bestResults->entities)) {
                    $fallbackMessage = $this->buildEntitiesFallbackMessage($bestResults->entities);

                    if ($fallbackMessage) {
                        $validatedResponse = $fallbackMessage;
                    }
                }

            }/* elseif (!empty($bestResults->entities)) {

                $validatedResponse .= "\n\n---\n\n **Ressources utiles :**";
            }*/

            return new ChatResponse(
                message: $validatedResponse,
                ctas: $bestResults->ctas,
                entities: $bestResults->entities
            );
        }

        // 🔥 fallback intelligent
        return new ChatResponse(
            message: "Je n’ai pas trouvé une réponse suffisamment fiable. Pouvez-vous préciser votre demande ?",
            ctas: [],
            entities: []
        );
    }
    /**
     * Appel LLM avec PERSONA EMPLOYÉ INTERNE
     */
    public function callLLM(Site $site, array $prompt, string $question): string
    {
        $companyName = $site->name ?? parse_url($site->url, PHP_URL_HOST);
        /**
         * @var WidgetSetting $settings
         */
        $settings = $site->settings;

        $messages = [
            ['role' => 'system', 'content' => $prompt['system']],
            ...$prompt['messages'],
        ];

        // --- DÉBUT DE LA LOGIQUE DE RETRY ---
        $maxRetries = 5;
        $delaySeconds = 1; // Délai de base pour le backoff exponentiel
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {

                Log::info("Appel à l'API LLM (tentative {$attempt})", ['site_id' => $site->id, 'question' => substr($question, 0, 100)]);

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
                    'Content-Type' => 'application/json', // Bonne pratique
                ])->post('https://openrouter.ai/api/v1/chat/completions', [
                    'model' => 'openai/gpt-4.1-mini',
                    'messages' => $messages,
                    'temperature' => floatval($settings->ai_temperature),
                    'max_tokens' => 350//$settings->ai_max_tokens,
                ]);

                // Vérifier si la requête HTTP a échoué (statut 4xx, 5xx)
                if (!$response->successful()) {
                    $errorMessage = "Erreur HTTP API LLM (tentative {$attempt}): " . $response->status() . " - " . $response->body();
                    Log::warning($errorMessage);
                    // Si ce n'est pas la dernière tentative, attendre avant de réessayer
                    if ($attempt < $maxRetries) {
                        $newAttempt = $attempt + 1;
                        Log::info("Attente de {$delaySeconds}s avant la tentative {$newAttempt}...");
                        sleep($delaySeconds);
                        $delaySeconds *= 2; // Backoff exponentiel
                        continue; // Passer à la prochaine itération de la boucle (réessayer)
                    } else {
                        // C'est la dernière tentative, sortir de la boucle pour lever l'exception ou retourner le fallback
                        break; // Sortir de la boucle pour gérer l'échec final
                    }
                }

                // La requête a réussi, vérifier la structure de la réponse
                $responseData = $response->json();

                // Vérifier si la structure attendue est présente
                if (isset($responseData['choices']) && is_array($responseData['choices']) && count($responseData['choices']) > 0) {
                    $choice = $responseData['choices'][0];
                    if (isset($choice['message']) && isset($choice['message']['content'])) {
                        $content = $choice['message']['content'];
                        Log::info("Réponse API LLM reçue (tentative {$attempt})", ['content_length' => strlen($content)]);
                        return $content;
                    } else {
                        $errorMessage = "Structure de réponse API LLM invalide (tentative {$attempt}): 'choices.0.message.content' manquant";
                        Log::warning($errorMessage, ['response_data' => $responseData]);
                    }
                } else {
                    $errorMessage = "Structure de réponse API LLM invalide (tentative {$attempt}): 'choices' manquant ou vide";
                    Log::warning($errorMessage, ['response_data' => $responseData]);
                }

                // Si on arrive ici, c'est que la réponse n'était pas correctement formatée
                // Si ce n'est pas la dernière tentative, attendre avant de réessayer
                if ($attempt < $maxRetries) {
                    $newAttempt = $attempt + 1;
                    Log::info("Attente de {$delaySeconds}s avant la tentative {$newAttempt}...");
                    sleep($delaySeconds);
                    $delaySeconds *= 2; // Backoff exponentiel
                    continue; // Passer à la prochaine itération de la boucle (réessayer)
                }

                /*return $response->json()['choices'][0]['message']['content']
                    ?? "N'hésitez pas à nous contacter, nous serons ravis de vous aider.";*/

            }catch (RequestException $e) {
                $errorMessage = "Erreur de requête HTTP (tentative {$attempt}): " . $e->getMessage();
                Log::warning($errorMessage);
                // Si ce n'est pas la dernière tentative
                $newAttempt = $attempt+1;
                if ($attempt < $maxRetries) {
                    Log::info("Attente de {$delaySeconds}s avant la tentative {$newAttempt}...");
                    sleep($delaySeconds);
                    $delaySeconds *= 2; // Backoff exponentiel
                    continue; // Passer à la prochaine itération de la boucle (réessayer)
                }
            } catch (Exception $e) { // Capture d'autres exceptions potentielles (JSON invalide, etc.)
                $errorMessage = "Erreur inattendue lors de l'appel API (tentative {$attempt}): " . $e->getMessage();
                Log::error($errorMessage, ['exception' => $e]);
                // Si ce n'est pas la dernière tentative
                if ($attempt < $maxRetries) {
                    $newAttempt = $attempt+1;
                    Log::info("Attente de {$delaySeconds}s avant la tentative {$newAttempt}...");
                    sleep($delaySeconds);
                    $delaySeconds *= 2; // Backoff exponentiel
                    continue; // Passer à la prochaine itération de la boucle (réessayer)
                }
            }
        }

        // --- FIN DE LA BOUCLE DE RETRY ---
        // Si on arrive ici, c'est que toutes les tentatives ont échoué
        $finalErrorMessage = "Échec de l'appel API LLM après {$maxRetries} tentatives.";
        Log::error($finalErrorMessage, [
            'site_id' => $site->id,
            'question' => substr($question, 0, 100), // Logguer une partie de la question pour le contexte
        ]);

        // RETOUR MANQUANT AJOUTÉ ICI
        return "Notre équipe chez {$companyName} reste disponible pour vous accompagner.";
        // OU Optionnellement, vous pouvez lever une exception ici si le contrôleur doit la gérer
        // throw new Exception($finalErrorMessage);

    }
    public function updateConversationSummary(Conversation $conversation): void
    {
        $oldSummary = $conversation->summary ?? '{}';

        $recentMessages = Message::where('conversation_id', $conversation->id)
            ->orderBy('created_at', 'desc')
            ->take(8)
            ->get()
            ->reverse()
            ->map(fn($m) => "{$m->role}: {$m->content}")
            ->implode("\n");

        $prompt = <<<PROMPT
        Tu es un moteur de mémoire conversationnelle utilisé dans un chatbot SaaS multi-domaines
        (e-commerce, support client, blog, SaaS, etc.).

        Ton rôle est de maintenir un résumé court et utile d'une conversation.

        OBJECTIF
        Mettre à jour le résumé existant avec les nouvelles informations pertinentes.

        RÈGLES IMPORTANTES

        - Conserve uniquement les informations durables et utiles au contexte.
        - N'invente jamais d'information.
        - N'interprète pas les intentions non exprimées.
        - Supprime les informations temporaires ou inutiles.
        - Fusionne les informations similaires pour éviter les répétitions.
        - Si une nouvelle information contredit une ancienne, garde la plus récente.

        INFORMATIONS À CONSERVER

        - préférences utilisateur
        - objectifs utilisateur
        - contraintes
        - décisions confirmées
        - informations personnelles utiles

        INFORMATIONS À IGNORER

        - salutations
        - formules de politesse
        - small talk
        - réponses marketing
        - détails temporaires

        FORMAT DU RÉSUMÉ

        - phrases courtes
        - style neutre
        - maximum 12 lignes
        - une information par ligne

        RÉSUMÉ ACTUEL
        {$oldSummary}

        NOUVEAUX MESSAGES
        {$recentMessages}

        INSTRUCTION FINALE

        Génère le nouveau résumé mis à jour en respectant les règles ci-dessus.

        Retourne uniquement le résumé.
        Aucun texte explicatif.
        PROMPT;

        $response = $this->callLLMForSummary($prompt, $conversation, false);

        $conversation->update([
            'summary' => $response,
            'summary_updated_at' => now()
        ]);
    }
    public function updateConversationMemory(Conversation $conversation): void
    {
        $memory = $this->extractStructuredMemory($conversation);

        if (!empty($memory)) {
            DB::table('conversation_memories')->updateOrInsert(
                ['conversation_id' => $conversation->id],
                ['memory' => json_encode($memory), 'updated_at' => now()]
            );
        }
    }
    private function callLLMForSummary(string $prompt, ?Conversation $conversation, bool $return_json = true): string
    {
        $maxRetries = 5;
        $delaySeconds = 1; // base backoff
        $conversationId = $conversation?->id ?? 'unknown';

        $fallback = $return_json
            ? json_encode(['preferences'=>[],'objectives'=>[],'constraints'=>[],'decisions'=>[],'user_info'=>[]])
            : ($conversation?->summary ?? 'Résumé indisponible');

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {

            try {

                Log::info("Appel à l'API LLM pour résumé (tentative {$attempt})", ['conversation_id' => $conversationId]);

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
                    'Content-Type' => 'application/json',
                ])->timeout(30)
                    ->post('https://openrouter.ai/api/v1/chat/completions', [
                        'model' => 'meta-llama/llama-3.1-8b-instruct',
                        'messages' => [
                            ['role' => 'system', 'content' => $prompt]
                        ],
                        'temperature' => 0.3,
                        'max_tokens' => 300,
                    ]);

                if (!$response->successful()) {
                    Log::warning("Erreur HTTP API LLM (tentative {$attempt}): {$response->status()}", [
                        'body' => $response->body(),
                        'conversation_id' => $conversationId
                    ]);
                    if ($attempt < $maxRetries) {
                        sleep($delaySeconds);
                        $delaySeconds *= 2;
                        continue;
                    }
                    break;
                }

                $data = $response->json();
                $content = data_get($data, 'choices.0.message.content', null);

                if (empty($content)) {
                    Log::warning("Réponse vide ou malformée (tentative {$attempt})", [
                        'response_data' => $data,
                        'conversation_id' => $conversationId
                    ]);
                    if ($attempt < $maxRetries) {
                        sleep($delaySeconds);
                        $delaySeconds *= 2;
                        continue;
                    }
                }else {
                    $content = trim($content);

                    if (!$return_json) {
                        // nettoyage markdown ```json ou ``` si string brut
                        $content = preg_replace('/^```[a-z]*|```$/mi', '', $content);
                        $content = trim($content);
                        Log::info("Résumé LLM reçu (string) avec succès (tentative {$attempt})", ['conversation_id' => $conversationId]);
                        return $content;
                    }

                    // Cas JSON attendu
                    $decoded = json_decode($content, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        Log::info("Résumé LLM reçu (JSON) avec succès (tentative {$attempt})", ['conversation_id' => $conversationId]);
                        return $content;
                    }

                    Log::warning("JSON invalide reçu (tentative {$attempt})", ['content' => $content]);
                    if ($attempt < $maxRetries) {
                        sleep($delaySeconds);
                        $delaySeconds *= 2;
                        continue;
                    }
                }

            } catch (Exception $e) {
                Log::error("Exception lors de l'appel API LLM (tentative {$attempt}): " . $e->getMessage(), ['conversation_id' => $conversationId]);
                if ($attempt < $maxRetries) {
                    sleep($delaySeconds);
                    $delaySeconds *= 2;
                    continue;
                }
            }

        }

        Log::error("Échec de l'appel API LLM après {$maxRetries} tentatives, fallback utilisé", ['conversation_id' => $conversationId]);
        return $fallback;

    }
    public function extractStructuredMemory(Conversation $conversation): array
    {
        $summary = $conversation->summary ?? '';

        if ($summary === '' || $summary === 'Résumé indisponible') {
            return [];
        }

        $prompt = <<<PROMPT
        Tu es un moteur d'extraction de mémoire structurée utilisé dans un chatbot SaaS multi-domaines
        (blog, e-commerce, support client, SaaS, etc.).

        Ton rôle :
        Transformer un résumé de conversation en JSON structuré exploitable par un agent conversationnel.

        Règles importantes :

        - Extrais uniquement les informations explicitement présentes dans le résumé.
        - Tu peux reformuler légèrement pour rendre l'information exploitable.
        - N'invente jamais d'information absente.
        - Fusionne les informations identiques pour éviter les doublons.
        - Si une information contredit une autre plus ancienne, garde la plus récente.
        - Les éléments doivent être courts (2 à 8 mots).
        - Maximum 15 éléments par catégorie.
        - Si aucune information pertinente n'est trouvée pour une catégorie, retourne un tableau vide.

        Catégories et exemples :

        preferences : produits, services, choix ou intérêts exprimés.
        Exemples :
        "stylo noir"
        "ordinateur Apple"
        "coffret cadeau"

        objectives : ce que l'utilisateur souhaite faire ou obtenir.
        Exemples :
        "acheter un stylo"
        "contacter le support"
        "obtenir des informations"

        constraints : conditions ou limitations exprimées.
        Exemples :
        "budget limité"
        "livraison rapide"
        "couleur noire"

        decisions : décisions déjà prises ou validées.
        Exemples :
        "choisir offre premium"
        "prendre ce modèle"

        user_info : informations personnelles explicitement mentionnées.
        Exemples :
        "vit à Paris"
        "photographe"
        "email exemple@mail.com"

        Format STRICT :

        {
          "preferences": [],
          "objectives": [],
          "constraints": [],
          "decisions": [],
          "user_info": []
        }

        Résumé à traiter :
        {$summary}

        ⚠️ Réponds uniquement avec un JSON valide.
        Aucun texte avant ou après.

        Exemple 1 :

        Résumé : "l'utilisateur préfère les stylos bleus, souhaite un coffret cadeau pour un ami, a un budget limité, veut contacter le support par email"

        Réponse :
        {
          "preferences": ["stylos bleus", "coffret cadeau"],
          "objectives": ["contacter le support"],
          "constraints": ["budget limité"],
          "decisions": [],
          "user_info": []
        }

        Exemple 2 :

        Résumé : "l'utilisateur veut un ordinateur Apple pas cher"

        Réponse :
        {
          "preferences": ["ordinateur Apple"],
          "objectives": ["acheter un ordinateur"],
          "constraints": ["prix bas"],
          "decisions": [],
          "user_info": []
        }
        PROMPT;

        $response = $this->callLLMForSummary($prompt, $conversation, true);

        Log::info("Extract Structure Memory: ", [
            'response' => $response,
        ]);

        $memory = json_decode($response, true);

        return is_array($memory) ? $memory : [
            'preferences' => [],
            'objectives' => [],
            'constraints' => [],
            'decisions' => [],
            'user_info' => []
        ];
    }
    public function extractStructuredMemoryFromMessage(Message $message): array
    {
        $prompt = <<<PROMPT
        Tu es un moteur d'extraction de mémoire structurée utilisé dans un chatbot SaaS multi-domaines
        (blog, e-commerce, support client, SaaS, etc.).

        Ton rôle est d'extraire les informations utiles contenues dans le message utilisateur.

        Règles importantes :

        - Extrais uniquement les informations présentes dans le message.
        - Tu peux reformuler légèrement pour rendre l'information exploitable.
        - N'invente jamais d'information absente.
        - Si aucune information pertinente n'est trouvée, retourne des tableaux vides.
        - Les éléments doivent être courts (2 à 8 mots).

        Catégories :

        preferences
        Produits, services, choix ou intérêts exprimés.

        Exemples :
        "stylo noir"
        "ordinateur Apple"
        "coffrets cadeaux"

        objectives
        Ce que l'utilisateur souhaite faire ou obtenir.

        Exemples :
        "acheter un stylo"
        "contacter l'entreprise"
        "obtenir des informations"

        constraints
        Conditions ou limitations.

        Exemples :
        "budget limité"
        "livraison rapide"
        "couleur noir"

        decisions
        Décisions déjà prises.

        Exemples :
        "choisir offre premium"
        "prendre ce modèle"

        user_info
        Informations personnelles explicitement mentionnées.

        Exemples :
        "vit à Paris"
        "photographe"
        "travaille en freelance"

        Format STRICT :

        {
          "preferences": [],
          "objectives": [],
          "constraints": [],
          "decisions": [],
          "user_info": []
        }

        Message utilisateur :
        {$message->content}

        Réponds uniquement avec un JSON valide.
        Aucun texte avant ou après.

        Exemple 1 :

        Message : "Je veux un ordinateur Apple pas cher"

        Réponse :
        {
         "preferences": ["ordinateur Apple"],
         "objectives": ["acheter un ordinateur"],
         "constraints": ["prix bas"],
         "decisions": [],
         "user_info": []
        }

        Exemple 2 :

        Message : "Je suis intéressé par vos coffrets"

        Réponse :
        {
         "preferences": ["coffrets"],
         "objectives": ["obtenir des informations"],
         "constraints": [],
         "decisions": [],
         "user_info": []
        }
        PROMPT;

        $response = $this->callLLMForSummary($prompt, $message->conversation, true);

        Log::info("Extract Structure Memory From Message: ", [
            'response' => $response,
        ]);

        $memory = json_decode($response, true);

        return is_array($memory) ? $memory : [
            'preferences' => [],
            'objectives' => [],
            'constraints' => [],
            'decisions' => [],
            'user_info' => []
        ];
    }
    private function buildEntitiesFallbackMessage(array $entities): ?string
    {
        if (empty($entities)) {
            return null;
        }

        // 🔢 Compter les types
        $counts = collect($entities)
            ->groupBy('type')
            ->map(fn($items) => count($items));

        // 🎯 Construire les labels avec count
        $parts = collect($counts)
            ->filter(fn($count, $type) => isset($this->entityLabels[$type]))
            ->sortBy(fn($count, $type) => $this->entityLabels[$type]['priority'])
            ->map(function ($count, $type) {

                $config = $this->entityLabels[$type];

                $label = $count === 1
                    ? $config['singular']
                    : $config['plural'];

                return "{$count} {$label}";
            })
            ->values()
            ->toArray();

        if (empty($parts)) {
            return null;
        }

        // 🧠 Phrase naturelle
        if (count($parts) === 1) {
            $list = $parts[0];
        } elseif (count($parts) === 2) {
            $list = implode(' et ', $parts);
        } else {
            $last = array_pop($parts);
            $list = implode(', ', $parts) . ' et ' . $last;
        }

        // ✨ Markdown propre
        return "Nous n’avons pas cette information exacte.\n\n---\n\n **Voici {$list} qui pourraient vous être utiles :**";
    }
    private function notifyThinking(Site $site, Conversation $conversation, string $label): void
    {
        $this->mercureService->post(
            "/sites/{$site->id}/conversations/{$conversation->id}",
            [
                'type' => 'thinking_step',
                'conversation_id' => $conversation->id,
                'label' => $label,
                'created_at' => now()->toISOString(),
            ]
        );
    }
}
