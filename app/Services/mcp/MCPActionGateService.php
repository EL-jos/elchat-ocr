<?php

namespace App\Services\mcp;

use App\Domain\MCP\Audit\AuditLogger;
use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\ConfirmationRequiredException;
use App\Domain\MCP\Exceptions\ConnectorUnavailableException;
use App\Domain\MCP\Exceptions\MCPException;
use App\Domain\MCP\Exceptions\PermissionDeniedException;
use App\Domain\MCP\Orchestration\MCPGateResult;
use App\Domain\MCP\Orchestration\OpenRouterToolClient;
use App\Domain\MCP\Registry\ConnectorRegistry;
use App\Domain\MCP\Security\ActorContext;
use App\Domain\MCP\Security\CredentialVault;
use App\Domain\MCP\Security\PermissionEngine;
use App\Domain\RAG\RAGToolAdapter;
use App\Models\Conversation;
use App\Models\Mcp\McpPendingAction;
use App\Models\Site;
use App\Services\cta\ChatResponse;
use App\Services\MercureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class MCPActionGateService
{
    private const MAX_HOPS = 6; // 🆕 relevé : chaînes plus longues possibles (recherche -> panier -> checkout)
    private int $maxHops;

    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly PermissionEngine $permissions,
        private readonly CredentialVault $vault,
        private readonly AuditLogger $audit,
        private readonly RAGToolAdapter $ragTool,
        private readonly OpenRouterToolClient $llm,
        private readonly MercureService $mercureService,
        private readonly VisitorIdentityService $visitorIdentity, // 🆕
    ) {
        $this->maxHops = (int) config('mcp.orchestrator.max_hops', 8); // 🆕
    }

    public function tryHandle(Site $site, Conversation $conversation, string $question, array $history): MCPGateResult
    {
        $actor = ActorContext::fromConversation($conversation);
        $mcpTools = $this->permissions->filterAllowedTools($site, $actor, $this->connectorToolSchemas($site));

        if (empty($mcpTools)) {
            return MCPGateResult::notApplicable();
        }

        $tools = [
            ...$this->controlTools(), // 🆕
            ...array_map(fn (ToolSchema $t) => $t->toOpenAIFormat(), [...$mcpTools, $this->ragTool->schema()]),
        ];

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($site, $actor)],
            ...$history,
            ['role' => 'user', 'content' => $question],
        ];

        return $this->runLoop($site, $conversation, $actor, $messages, $tools, hop: 1, trace: [], suggestedActions: []);
    }

    /**
     * 🆕 Reprend après résolution d'une mcp_pending_actions (approuvée ou
     * refusée), par le visiteur OU par un agent back-office.
     */
    public function resumeAfterConfirmation(Site $site, McpPendingAction $pendingAction, bool $approved): MCPGateResult
    {
        $conversation = $pendingAction->conversation;
        $actor = ActorContext::fromConversation($conversation);
        $messages = $pendingAction->messages_snapshot;
        $suggestedActions = [];

        if (!$approved) {
            $messages[] = ['role' => 'tool', 'tool_call_id' => $pendingAction->tool_call_id, 'content' => json_encode(['status' => 'declined'])];
        } else {
            $result = $this->executeAuthorized($site, $conversation, $actor, $pendingAction->connector_slug, $pendingAction->tool_name, $pendingAction->params, hop: 0, forced: true);
            $messages[] = ['role' => 'tool', 'tool_call_id' => $pendingAction->tool_call_id, 'content' => json_encode($result->toArrayForLLM())];

            if ($result->success) {
                $suggestedActions = $this->suggestionsFor($site, $actor, $pendingAction->connector_slug, $pendingAction->tool_name, $result->data);
            }
        }

        $mcpTools = $this->permissions->filterAllowedTools($site, $actor, $this->connectorToolSchemas($site));
        $tools = [
            ...$this->controlTools(),
            ...array_map(fn (ToolSchema $t) => $t->toOpenAIFormat(), [...$mcpTools, $this->ragTool->schema()]),
        ];

        return $this->runLoop($site, $conversation, $actor, $messages, $tools, hop: $this->maxHops, trace: [], suggestedActions: $suggestedActions);
    }

    private function runLoop(Site $site, Conversation $conversation, ActorContext $actor, array $messages, array $tools, int $hop, array $trace, array $suggestedActions = [], array $executedCalls = []): MCPGateResult
    {
        while ($hop <= $this->maxHops) {
            if ($hop === 1) {
                $this->notifyThinking($site, $conversation, 'Vérification des actions possibles...');
            }

            $toolChoice = $hop === 1 ? 'required' : 'auto';
            $llmResponse = $this->llm->send($messages, $tools, $toolChoice);

            if (empty($llmResponse['tool_calls'])) {
                return MCPGateResult::finished(
                    new ChatResponse(message: trim((string) $llmResponse['text']), ctas: [], entities: [], suggestedActions: $suggestedActions),
                    $trace,
                );
            }

            $messages[] = $llmResponse['raw_message'];

            foreach ($llmResponse['tool_calls'] as $toolCall) {
                $qualifiedName = $toolCall['function']['name'];
                $args = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: [];
                $toolCallId = $toolCall['id'];

                if ($qualifiedName === 'control__no_action_needed') {
                    return MCPGateResult::notApplicable();
                }
                if ($qualifiedName === 'control__ask_clarification') {
                    return MCPGateResult::finished(
                        new ChatResponse(message: (string) ($args['question'] ?? ''), ctas: [], entities: [], suggestedActions: $suggestedActions),
                        $trace,
                    );
                }

                [$connectorSlug, $toolName] = array_values(ToolSchema::fromQualifiedName($qualifiedName));

                // 🆕 Garde-fou anti-doublon : le LLM redemande EXACTEMENT le même
                // appel (même outil, mêmes paramètres) qu'il a déjà exécuté avec
                // succès dans cette même boucle. On n'exécute JAMAIS une 2e fois
                // — critique pour les actions financières (generate_checkout,
                // issue_refund...) — et on répond directement avec le résultat
                // déjà obtenu au lieu de laisser la boucle tourner jusqu'à MAX_HOPS.
                $callSignature = $connectorSlug . '.' . $toolName . ':' . md5(json_encode($args));

                if (isset($executedCalls[$callSignature])) {
                    $cached = $executedCalls[$callSignature];
                    $fallbackMessage = $cached->humanSummary ?: 'Cette action a déjà été effectuée avec succès.';
                    if (!empty($cached->data['checkout_url'])) {
                        $fallbackMessage .= " Voici le lien de paiement : {$cached->data['checkout_url']}";
                    }

                    Log::warning("MCP: appel dupliqué détecté et bloqué ({$callSignature}) pour le site {$site->id}");

                    return MCPGateResult::finished(
                        new ChatResponse(message: $fallbackMessage, ctas: [], entities: [], suggestedActions: $suggestedActions),
                        $trace,
                    );
                }

                if ($hop === 1) {
                    $this->notifyThinking($site, $conversation, $this->thinkingLabelFor($connectorSlug, $toolName));
                }

                $execution = $this->executeHop($site, $conversation, $actor, $connectorSlug, $toolName, $args, $hop);
                $trace[] = ['hop' => $hop, 'connector' => $connectorSlug, 'tool' => $toolName, 'status' => $execution['status']];

                if ($execution['status'] === 'awaiting_confirmation') {
                    $pendingAction = McpPendingAction::create([
                        'id' => (string) Str::uuid(),
                        'site_id' => $site->id,
                        'conversation_id' => $conversation->id,
                        'connector_slug' => $connectorSlug,
                        'tool_name' => $toolName,
                        'params' => $args,
                        'confirm_actor' => $execution['confirm_actor'],
                        'tool_call_id' => $toolCallId,
                        'messages_snapshot' => $messages,
                        'status' => 'pending',
                        'expires_at' => now()->addDays(3),
                    ]);

                    return MCPGateResult::awaitingConfirmation($pendingAction, $trace);
                }

                // 🆕 On ne met en cache que les VRAIS succès métier (ToolResult::success
                    // === true), jamais un échec métier (out_of_stock, variation_required,
                    // empty_cart...). Un échec doit toujours pouvoir être retenté par le LLM
                    // après correction — seule une action réellement aboutie doit être protégée
                    // contre une ré-exécution.
                if ($execution['status'] === 'success' && $execution['result']->success) {
                    $suggestedActions = array_merge(
                        $suggestedActions,
                        $this->suggestionsFor($site, $actor, $connectorSlug, $toolName, $execution['result']->data),
                    );
                    $executedCalls[$callSignature] = $execution['result'];
                }

                $messages[] = ['role' => 'tool', 'tool_call_id' => $toolCallId, 'content' => json_encode($execution['result']->toArrayForLLM())];
            }

            $hop++;
        }

        Log::warning("MCP: nombre maximum de hops atteint pour le site {$site->id}, tentative de synthèse finale");

        // 🆕 Plutôt qu'un message générique, on redonne une dernière fois la
        // parole au LLM en mode texte forcé (tool_choice='none') : il a déjà tous
        // les résultats des outils exécutés dans $messages, il peut donc résumer
        // honnêtement ce qui a été fait (et ce qui ne l'a pas été) au lieu de
        // laisser le visiteur sans aucune explication.
        try {
            $finalResponse = $this->llm->send($messages, $tools, 'none');
            $finalText = trim((string) ($finalResponse['text'] ?? ''));
        } catch (Throwable $e) {
            Log::error("MCP: échec de la synthèse finale après hops épuisés : {$e->getMessage()}");
            $finalText = '';
        }

        if ($finalText === '') {
            $finalText = "Je n'ai pas pu finaliser cette action automatiquement. Un conseiller va prendre le relais.";
        }

        return MCPGateResult::finished(new ChatResponse(message: $finalText, ctas: [], entities: [], suggestedActions: $suggestedActions), $trace);
    }

    private function executeHop(Site $site, Conversation $conversation, ActorContext $actor, string $connectorSlug, string $toolName, array $params, int $hop): array
    {
        try {
            $result = $this->executeAuthorized($site, $conversation, $actor, $connectorSlug, $toolName, $params, $hop);
            return ['status' => 'success', 'result' => $result];
        } catch (ConfirmationRequiredException $e) {
            $this->audit->log($site, $connectorSlug, $toolName, $params, 'confirm', 'awaiting_confirmation', conversationId: $conversation->id, hopNumber: $hop);
            return ['status' => 'awaiting_confirmation', 'confirm_actor' => $e->confirmActor];
        } catch (PermissionDeniedException $e) {
            $this->audit->log($site, $connectorSlug, $toolName, $params, 'deny', 'denied', errorCode: 'permission_denied', conversationId: $conversation->id, hopNumber: $hop);
            return ['status' => 'denied', 'result' => ToolResult::fail('permission_denied', $e->getMessage())];
        } catch (ConnectorUnavailableException $e) {
            Log::warning("MCP connector_unavailable: {$e->getMessage()}", ['connector' => $connectorSlug, 'tool' => $toolName]);
            $this->audit->log($site, $connectorSlug, $toolName, $params, 'auto', 'error', errorCode: 'connector_unavailable', conversationId: $conversation->id, hopNumber: $hop);
            return ['status' => 'error', 'result' => ToolResult::fail('connector_unavailable', "Le service {$connectorSlug} est momentanément indisponible.")];
        } catch (MCPException $e) {
            $this->audit->log($site, $connectorSlug, $toolName, $params, 'auto', 'error', errorCode: $e->errorCode(), conversationId: $conversation->id, hopNumber: $hop);
            return ['status' => 'error', 'result' => ToolResult::fail($e->errorCode(), $e->getMessage())];
        } catch (Throwable $e) {
            Log::error("MCP hop non géré: {$e->getMessage()}", ['connector' => $connectorSlug, 'tool' => $toolName]);
            $this->audit->log($site, $connectorSlug, $toolName, $params, 'auto', 'error', errorCode: 'unexpected', conversationId: $conversation->id, hopNumber: $hop);
            return ['status' => 'error', 'result' => ToolResult::fail('unexpected', 'Une erreur technique est survenue.')];
        }
    }

    private function executeAuthorized(Site $site, Conversation $conversation, ActorContext $actor, string $connectorSlug, string $toolName, array $params, int $hop, bool $forced = false): ToolResult
    {
        if ($connectorSlug === RAGToolAdapter::CONNECTOR_SLUG) {
            $result = $this->ragTool->search($site, $params['query'] ?? '');
            $this->audit->log($site, $connectorSlug, $toolName, $params, 'auto', $result->success ? 'success' : 'error', $result, conversationId: $conversation->id, hopNumber: $hop);
            return $result;
        }

        if (!$forced) {
            $this->permissions->authorize($site, $actor, $connectorSlug, $toolName);
        }

        $credentials = $this->vault->retrieve($site, $connectorSlug);
        if (!$credentials) {
            return ToolResult::fail('not_connected', "Le connecteur {$connectorSlug} n'est pas configuré pour ce site.");
        }

        $connector = $this->registry->get($connectorSlug);

        try {
            $freshCredentials = $connector->authenticate($credentials);
            if ($freshCredentials !== $credentials) {
                $this->vault->refresh($site, $connectorSlug, $freshCredentials);
            }
        } catch (\App\Domain\MCP\Exceptions\AuthExpiredException $e) {
            $this->vault->markAuthExpired($site, $connectorSlug, $e->getMessage());
            throw $e;
        }

        // 🆕 Contexte injecté (jamais fourni par le LLM) : panier/wishlist scopés au bon propriétaire.
        $context = [
            'site_id' => $site->id, 'conversation_id' => $conversation->id,
            'owner_type' => $actor->ownerType, 'owner_id' => $actor->ownerId, 'is_admin' => $actor->isAdmin,
        ];

        $startedAt = microtime(true);
        $result = $connector->callTool($toolName, $params, $freshCredentials, $context);
        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        $this->audit->log($site, $connectorSlug, $toolName, $params, 'auto', $result->success ? 'success' : 'error', $result, $durationMs, conversationId: $conversation->id, hopNumber: $hop);

        // 🆕 Les effets secondaires ne doivent JAMAIS pouvoir invalider un résultat
        // d'action déjà réussi côté WooCommerce (ex: une commande déjà créée et
        // payable). Isolés dans leur propre try/catch : une erreur ici est
        // loggée, jamais propagée — sinon le LLM croit que l'action a échoué et
        // la retente, au risque de créer une 2e commande pour de vrai.
        if ($result->success && !empty($result->cartSync)) {
            try {
                $this->notifyCartSync($site, $conversation, $result->cartSync);
            } catch (Throwable $e) {
                Log::error("MCP: échec notifyCartSync après {$connectorSlug}.{$toolName} : {$e->getMessage()}");
            }
        }

        if ($result->success && $result->identity && $actor->ownerType === 'visitor') {
            try {
                $visitor = $conversation->visitor;
                $user = $this->visitorIdentity->resolveFromIdentity($site, $visitor, $result->identity);
                if ($user) {
                    $conversation->refresh();
                    Log::info("MCP: visiteur transformé en user {$user->id} suite à {$connectorSlug}.{$toolName}");
                }
            } catch (Throwable $e) {
                Log::error("MCP: échec transformation visiteur après {$connectorSlug}.{$toolName} : {$e->getMessage()}");
            }
        }

        return $result;
    }

    /** @return ToolSchema[] */
    private function connectorToolSchemas(Site $site): array
    {
        $activeSlugs = $site->mcpSiteConnectors()->where('status', 'connected')->with('mcpConnector')->get()->pluck('mcpConnector.slug');
        $schemas = [];
        foreach ($activeSlugs as $slug) {
            if (!$this->registry->has($slug)) continue;
            array_push($schemas, ...$this->registry->get($slug)->listTools());
        }
        return $schemas;
    }

    /*private function systemPrompt(Site $site, ActorContext $actor): string
    {
        $name = $site->name ?? parse_url($site->url ?? '', PHP_URL_HOST);
        $roleNote = $actor->isAdmin
            ? "Tu t'adresses ici à un membre de l'équipe (back-office), pas à un visiteur public."
            : "Tu t'adresses à un visiteur ou client du site public.";

        return <<<PROMPT
Tu es le module de décision d'action de l'assistant du site {$name}. {$roleNote} Tu disposes d'outils qui
exécutent de VRAIES actions (produits, panier, commandes...). N'appelle un outil QUE si le message correspond
clairement à une action que ces outils permettent. Si c'est une question d'information générale sans action à
exécuter, NE CALL AUCUN outil. Si une information manque (ex: numéro de commande, quantité), demande-la au
visiteur au lieu d'inventer une valeur. Une fois les résultats obtenus, réponds de façon claire et concise.
N'appelle jamais clear_cart sauf si le visiteur demande explicitement de vider son panier — ce n'est jamais une
étape de correction ou de nettoyage intermédiaire d'une autre action. Si un outil retourne un checkout_url,
NE L'ÉCRIS JAMAIS dans ta réponse texte : un bouton de paiement s'affiche automatiquement séparément. Confirme
juste que la commande est prête sans donner l'adresse toi-même.
PROMPT;
    }*/
    private function systemPrompt(Site $site, ActorContext $actor): string
    {
        $name = $site->name ?? parse_url($site->url ?? '', PHP_URL_HOST);
        $roleNote = $actor->isAdmin
            ? "Tu t'adresses ici à un membre de l'équipe (back-office), pas à un visiteur public."
            : "Tu t'adresses à un visiteur ou client du site public.";

        // 🆕 Ancrage temporel explicite — sans ça, le LLM invente une date
        // arbitraire pour "aujourd'hui"/"demain" au lieu de calculer à partir
        // du vrai instant présent.
        $timezone = config('app.timezone', 'UTC'); // 🆕 était config('mcp.connectors.google_calendar.default_timezone')
        $now = now($timezone)->locale('fr')->isoFormat('dddd D MMMM YYYY [à] HH:mm');

        return <<<PROMPT
Tu es le module de décision d'action de l'assistant du site {$name}. {$roleNote} Nous sommes actuellement le
{$now} (fuseau horaire {$timezone}). Utilise cette date comme référence exacte pour tout calcul relatif
(aujourd'hui, demain, cette semaine, la semaine prochaine...) — ne calcule JAMAIS une date à partir d'une autre
supposition. Exprime toute date/heure envoyée à un outil au format ISO 8601 complet avec l'année en cours ou
suivante selon le contexte, jamais une année passée sauf si le visiteur la précise explicitement.
Tu disposes d'outils qui exécutent de VRAIES actions (produits, panier, commandes, rendez-vous...). N'appelle
un outil QUE si le message correspond clairement à une action que ces outils permettent. Si c'est une question
d'information générale sans action à exécuter, NE CALL AUCUN outil. Si une information manque, demande-la au
visiteur au lieu d'inventer une valeur. Une fois les résultats obtenus, réponds de façon claire et concise.
Pour toute question de disponibilité de rendez-vous ("quelles sont vos disponibilités", "êtes-vous libre..."),
préfère find_available_slots à get_busy_periods : le premier tient compte des horaires d'ouverture configurés,
le second non — s'il retourne quand même working_hours_windows, ne propose jamais un créneau en dehors.
N'appelle jamais clear_cart sauf si le visiteur demande explicitement de vider son panier. Si un outil retourne
un checkout_url, NE L'ÉCRIS JAMAIS dans ta réponse texte : un bouton s'affiche automatiquement séparément.
PROMPT;
    }

    private function thinkingLabelFor(string $connectorSlug, string $toolName): string
    {
        return match (true) {
            str_starts_with($toolName, 'search_products') || str_starts_with($toolName, 'get_product') => 'Recherche produit...',
            str_contains($toolName, 'cart') => 'Mise à jour du panier...',
            str_contains($toolName, 'checkout') => 'Préparation du paiement...',
            str_contains($toolName, 'order') => 'Vérification de votre commande...',
            $connectorSlug === 'google_calendar' => 'Consultation du calendrier...',
            $connectorSlug === RAGToolAdapter::CONNECTOR_SLUG => 'Recherche d\'information...',
            default => 'Traitement en cours...',
        };
    }

    private function notifyThinking(Site $site, Conversation $conversation, string $label): void
    {
        $this->mercureService->post("/sites/{$site->id}/conversations/{$conversation->id}", [
            'type' => 'thinking_step', 'conversation_id' => $conversation->id, 'label' => $label, 'created_at' => now()->toISOString(),
        ]);
    }

    private function notifyCartSync(Site $site, Conversation $conversation, array $syncAction): void
    {
        $this->mercureService->post("/sites/{$site->id}/conversations/{$conversation->id}", [
            'type' => 'cart_sync',
            'conversation_id' => $conversation->id,
            'payload' => $syncAction, // {type: add|remove|update|clear, product_id, variation_id?, quantity?}
            'created_at' => now()->toISOString(),
        ]);
    }

    /**
     * 🆕 Deux outils toujours proposés en plus des outils métier. Forcés via
     * tool_choice='required' au 1er tour, ils éliminent l'ambiguïté "pas
     * d'appel d'outil" qui causait la perte silencieuse des questions de
     * clarification (renvoyées à tort vers le RAG comme si ce n'était pas une action).
     */
    private function controlTools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'control__no_action_needed',
                    'description' => "Appelle cet outil si le message du visiteur ne correspond à AUCUNE action réalisable avec les outils disponibles (question d'information générale). N'appelle jamais un autre outil en même temps que celui-ci.",
                    'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'control__ask_clarification',
                    'description' => "Appelle cet outil si le message correspond à une action possible mais qu'il te manque une information pour l'exécuter (quelle variante, quel produit, tout le panier ou un produit précis, quel numéro de commande...).",
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['question' => ['type' => 'string', 'description' => 'Question exacte à poser au visiteur']],
                        'required' => ['question'],
                    ],
                ],
            ],
        ];
    }

    /**
     * 🆕 Suggestions d'actions cliquables après un succès, filtrées par ce que
     * cet acteur (visiteur/admin) a réellement le droit de faire — jamais une
     * suggestion qui mènerait à un refus de permission au clic.
     */
    private function suggestionsFor(Site $site, ActorContext $actor, string $connectorSlug, string $toolName, array $resultData): array
    {
        if ($connectorSlug !== 'woocommerce') {
            return [];
        }

        // 🆕 Un lien de paiement n'est pas une action à ré-exécuter via un
        // message : c'est une URL à ouvrir directement.
        if ($toolName === 'generate_checkout' && !empty($resultData['checkout_url'])) {
            return [['label' => '💳 Payer maintenant', 'url' => $resultData['checkout_url']]];
        }

        $candidates = match ($toolName) {
            'search_products', 'get_product', 'get_product_variations' => [
                ['tool' => 'add_to_cart', 'label' => 'Ajouter au panier', 'prompt' => 'Ajoute ce produit à mon panier'],
            ],
            'add_to_cart', 'update_cart_quantity' => [
                ['tool' => 'get_cart', 'label' => 'Voir mon panier', 'prompt' => 'Montre-moi mon panier'],
                ['tool' => 'generate_checkout', 'label' => 'Passer commande', 'prompt' => 'Crée une commande avec mon panier'],
            ],
            'get_cart' => empty($resultData['cart']) ? [] : [
                ['tool' => 'generate_checkout', 'label' => 'Passer commande', 'prompt' => 'Crée une commande avec mon panier'],
            ],
            'get_order_status', 'track_order' => [
                ['tool' => 'cancel_order', 'label' => 'Annuler la commande', 'prompt' => 'Annule cette commande'],
            ],
            default => [],
        };

        if (empty($candidates)) {
            return [];
        }

        $allowedToolNames = collect($this->permissions->filterAllowedTools($site, $actor, $this->connectorToolSchemas($site)))
            ->pluck('name')->all();

        return collect($candidates)
            ->filter(fn ($c) => in_array($c['tool'], $allowedToolNames, true))
            ->map(fn ($c) => ['label' => $c['label'], 'prompt' => $c['prompt']])
            ->values()->all();
    }

    /**
     * 🆕 Permet à chaque site de configurer SES propres horaires de travail
     * et/ou de surcharger le fuseau détecté automatiquement. Fusionné dans
     * les settings existants (store_url, calendar_id...), rien d'écrasé.
     */
    public function updateSettings(Request $request, Site $site, string $slug)
    {
        $validated = $request->validate([
            'timezone' => ['nullable', 'timezone'], // validation native Laravel (ex: "Indian/Reunion")
            'working_hours' => ['nullable', 'array'],
        ]);

        $record = $site->mcpSiteConnectors()
            ->whereHas('mcpConnector', fn ($q) => $q->where('slug', $slug))
            ->firstOrFail();

        $record->update([
            'settings' => array_merge($record->settings ?? [], array_filter($validated, fn ($v) => $v !== null)),
        ]);

        return response()->json(['status' => 'updated']);
    }
}
