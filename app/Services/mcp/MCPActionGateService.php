<?php

namespace App\Services\mcp;

use App\Domain\MCP\Agent\AgentSkillResolver;
use App\Domain\MCP\Agent\AgentSupervisor;
use App\Domain\MCP\Audit\AuditLogger;
use App\Domain\MCP\Capability\CapabilityResolver;
use App\Domain\MCP\Contracts\ProvidesSiteScopedTools;
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
use App\Models\Mcp\McpAgent;
use App\Models\Mcp\McpPendingAction;
use App\Models\Mcp\McpWorkflow;
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
        private readonly CapabilityResolver $capabilities, // 🆕
        private readonly AgentSkillResolver $agentSkills, // 🆕
        private readonly AgentSupervisor $supervisor, // 🆕
    ) {
        $this->maxHops = (int) config('mcp.orchestrator.max_hops', 8); // 🆕
    }

    public function tryHandle(Site $site, Conversation $conversation, string $question, array $history, ?string $intent = null): MCPGateResult
    {
        $actor = ActorContext::fromConversation($conversation);
        $permittedTools = $this->permissions->filterAllowedTools($site, $actor, $this->connectorToolSchemas($site));

        if (empty($permittedTools)) {
            return MCPGateResult::notApplicable();
        }

        $activeAgents = McpAgent::where('site_id', $site->id)->where('is_active', true)->get();

        // Aucun agent configuré : comportement historique, passerelle générique.
        if ($activeAgents->isEmpty()) {
            return $this->handleForAgent($site, $conversation, $actor, $question, $history, $permittedTools, null, $intent);
        }

        $selected = $this->supervisor->route($site, $question, $history, $activeAgents);

        // Le superviseur n'a rien trouvé de spécifique : repli sur l'agent
        // marqué "par défaut", sinon le premier agent actif du site.
        if (empty($selected)) {
            $fallback = $activeAgents->firstWhere('is_default', true) ?? $activeAgents->first();
            return $this->handleForAgent($site, $conversation, $actor, $question, $history, $permittedTools, $fallback, $intent);
        }

        if (count($selected) === 1) {
            return $this->handleForAgent($site, $conversation, $actor, $question, $history, $permittedTools, $selected[0], $intent);
        }

        return $this->handleMultiAgent($site, $conversation, $actor, $question, $history, $permittedTools, $selected, $intent);
    }

    /**
     * 🆕 Exécution scopée à UN agent (ou générique si $agent est null) —
     * c'est exactement ce que faisait l'ancien corps de tryHandle().
     */
    private function handleForAgent(
        Site $site, Conversation $conversation, ActorContext $actor, string $question, array $history,
        array $permittedTools, ?\App\Models\Mcp\McpAgent $agent, ?string $intent,
    ): MCPGateResult {
        $agentAllowedNames = ($agent && !empty($agent->skills)) ? $this->agentSkills->resolveAllowedToolNames($site, $agent->skills) : [];
        $scopedTools = $agentAllowedNames
            ? array_values(array_filter($permittedTools, fn ($t) => in_array($t->qualifiedName(), $agentAllowedNames, true)))
            : $permittedTools;

        if (empty($scopedTools)) return MCPGateResult::notApplicable();

        // 🆕 Un agent explicite n'utilise QUE les workflows cochés — aucun coché
        // = aucun workflow pour lui. Seule l'absence totale d'agent (site sans
        // Agent Studio configuré) continue de voir tous les workflows du site.
        $agentWorkflowIds = $agent ? ($agent->workflow_ids ?? []) : null;

        $tools = [
            ...$this->controlTools(),
            ...array_map(fn (ToolSchema $t) => $t->toOpenAIFormat(), [...$scopedTools, $this->ragTool->schema()]),
        ];

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($site, $actor, $intent, $agent, $agentAllowedNames, $agentWorkflowIds)],
            ...$history,
            ['role' => 'user', 'content' => $question],
        ];

        return $this->runLoop($site, $conversation, $actor, $messages, $tools, hop: 1, trace: [], suggestedActions: [], agent: $agent);
    }

    /**
     * 🆕 Plusieurs agents concernés par le même message : chacun traite sa
     * partie via handleForAgent() — les outils de contrôle lui permettent de
     * se retirer proprement si sa partie du message ne le concerne pas. Les
     * réponses obtenues sont ensuite fusionnées en une seule réponse cohérente.
     */
    private function handleMultiAgent(
        Site $site, Conversation $conversation, ActorContext $actor, string $question, array $history,
        array $permittedTools, array $agents, ?string $intent,
    ): MCPGateResult {
        $answers = [];
        $suggestedActions = [];
        $processedAgentIds = [];
        $runningHistory = $history; // 🆕 s'enrichit au fil des agents traités dans ce même tour

        foreach ($agents as $agent) {
            if (in_array($agent->id, $processedAgentIds, true)) continue;
            $processedAgentIds[] = $agent->id;

            $result = $this->handleForAgent($site, $conversation, $actor, $question, $runningHistory, $permittedTools, $agent, $intent);

            if ($result->status === 'awaiting_confirmation') {
                return $result;
            }

            if ($result->status === 'finished' && trim($result->response->message) !== '') {
                $answers[] = $result->response->message;
                $suggestedActions = array_merge($suggestedActions, $result->response->suggestedActions ?? []);

                // 🆕 L'agent suivant voit ce que celui-ci vient de faire — évite
                // qu'un 2ᵉ agent duplique ou contredise une action déjà traitée
                // par le 1er pour la même demande. Cette note reste locale à
                // cette boucle : jamais persistée dans l'historique réel de la
                // conversation, le visiteur ne la voit jamais.
                $runningHistory[] = ['role' => 'user', 'content' => $question];
                $runningHistory[] = ['role' => 'assistant', 'content' => "[Note interne : le collègue {$agent->name} vient de traiter la partie suivante de cette même demande : \"{$result->response->message}\". Ne duplique pas ce qui a déjà été fait — concentre-toi uniquement sur ce qui reste dans ton propre domaine, et si tout est déjà couvert, n'appelle aucun outil.]"];
            }
        }

        if (empty($answers)) return MCPGateResult::notApplicable();

        $mergedMessage = count($answers) === 1 ? $answers[0] : implode("\n\n", $answers);
        return MCPGateResult::finished(new ChatResponse(message: $mergedMessage, ctas: [], entities: [], suggestedActions: $suggestedActions), []);
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

        // 🆕 Reconstruit le même agent (ou aucun) que celui actif au moment de
        // la demande initiale — sinon la reprise pourrait exécuter l'action
        // hors du périmètre qui avait motivé la confirmation.
        $agent = $pendingAction->agent_id ? McpAgent::find($pendingAction->agent_id) : null;

        if (!$approved) {
            $messages[] = ['role' => 'tool', 'tool_call_id' => $pendingAction->tool_call_id, 'content' => json_encode(['status' => 'declined'])];
        } else {
            $result = $this->executeAuthorized($site, $conversation, $actor, $pendingAction->connector_slug, $pendingAction->tool_name, $pendingAction->params, hop: 0, forced: true);
            $messages[] = ['role' => 'tool', 'tool_call_id' => $pendingAction->tool_call_id, 'content' => json_encode($result->toArrayForLLM())];

            if ($result->success) {
                $suggestedActions = $this->suggestionsFor($site, $actor, $pendingAction->connector_slug, $pendingAction->tool_name, $result->data);
            }
        }

        $permittedTools = $this->permissions->filterAllowedTools($site, $actor, $this->connectorToolSchemas($site));
        $agentAllowedNames = ($agent && !empty($agent->skills)) ? $this->agentSkills->resolveAllowedToolNames($site, $agent->skills) : [];
        $scopedTools = $agentAllowedNames
            ? array_values(array_filter($permittedTools, fn ($t) => in_array($t->qualifiedName(), $agentAllowedNames, true)))
            : $permittedTools;

        $tools = [
            ...$this->controlTools(),
            ...array_map(fn (ToolSchema $t) => $t->toOpenAIFormat(), [...$scopedTools, $this->ragTool->schema()]),
        ];

        return $this->runLoop($site, $conversation, $actor, $messages, $tools, hop: $this->maxHops, trace: [], suggestedActions: $suggestedActions, agent: $agent);
    }

    private function runLoop(Site $site, Conversation $conversation, ActorContext $actor, array $messages, array $tools, int $hop, array $trace, array $suggestedActions = [], array $executedCalls = [], ?McpAgent $agent = null): MCPGateResult
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
                        'agent_id' => $agent?->id, // 🆕
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
        if ($credentials === null) {
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
            $connector = $this->registry->get($slug);

            // 🆕 Connecteur à outils dynamiques (Odoo) : filtre selon les modules
            // réellement installés sur l'instance de ce site.
            if ($connector instanceof ProvidesSiteScopedTools) {
                $credentials = $this->vault->retrieve($site, $slug);
                if (!$credentials) continue;
                array_push($schemas, ...$connector->toolsAvailableFor($credentials));
                continue;
            }

            array_push($schemas, ...$connector->listTools());
        }

        return $schemas;
    }

    /*private function systemPrompt(Site $site, ActorContext $actor): string
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
Si une action nécessite un email de contact (création de ticket, de contact, d'opportunité...) et que tu ne le
connais pas encore, demande-le dès le début de l'échange plutôt qu'en toute fin, pour éviter d'aller-retour
inutiles une fois que tu as déjà rassemblé le reste des informations.
Certaines actions doivent être déclenchées DE TA PROPRE INITIATIVE dès que la situation l'indique clairement,
sans attendre que le visiteur formule une demande explicite comme "crée un ticket" ou "ouvre une opportunité"
— c'est à toi de reconnaître le signal et d'agir directement, puis d'informer simplement le visiteur de ce que
tu as fait. Exemples : un visiteur qui signale un problème ("j'ai un souci avec...", "ça ne fonctionne pas...")
→ ouvre un ticket de support sans qu'il ait besoin de le demander. Un visiteur qui exprime un intérêt d'achat
clair et non ambigu ("je suis très intéressé par...", "je veux commander...", en donnant un budget ou un
produit précis) → crée une opportunité commerciale. Un visiteur qui souhaite être recontacté → crée un contact
et/ou une tâche de rappel. N'agis ainsi que lorsque le signal est net (pas une simple mention en passant) et
que l'action correspondante est en mode automatique pour ce site — n'invente jamais une exécution pour une
action en mode confirmation ou bloquée, contente-toi de l'information dans ce cas. Ne duplique jamais une
action déjà réalisée dans cette même conversation (ex: ne recrée pas un second ticket pour le même problème
déjà signalé) — vérifie le fil de la conversation avant d'agir une seconde fois sur le même sujet.
N'appelle jamais clear_cart sauf si le visiteur demande explicitement de vider son panier. Si un outil retourne
un checkout_url, NE L'ÉCRIS JAMAIS dans ta réponse texte : un bouton s'affiche automatiquement séparément.
PROMPT;
    }*/
    private function systemPrompt(Site $site, ActorContext $actor, ?string $intent = null, ?McpAgent $agent = null, array $agentAllowedNames = [], ?array $agentWorkflowIds = null): string
    {
        $name = $site->name ?? parse_url($site->url ?? '', PHP_URL_HOST);
        $roleNote = $actor->isAdmin
            ? "Tu t'adresses ici à un membre de l'équipe (back-office), pas à un visiteur public."
            : "Tu t'adresses à un visiteur ou client du site public.";

        // 🆕 Indice fourni par le système de classification en amont — une aide
        // au jugement, jamais une contrainte : une intention mal classée ne doit
        // jamais empêcher une action légitime, ni en forcer une non pertinente.
        $intentHint = $intent
            ? "Un système de classification amont a détecté l'intention '{$intent}' pour ce message — utilise ça comme indice pour juger s'il s'agit d'une action, sans t'y limiter si le contenu réel du message suggère autre chose."
            : '';

        $timezone = config('app.timezone', 'UTC');
        $now = now($timezone)->locale('fr')->isoFormat('dddd D MMMM YYYY [à] HH:mm');

        return <<<PROMPT
Tu es le module de décision d'action de l'assistant du site {$name}. {$roleNote} {$intentHint} Nous sommes
actuellement le {$now} (fuseau horaire {$timezone}). Utilise cette date comme référence exacte pour tout calcul
relatif (aujourd'hui, demain, cette semaine...). Exprime toute date/heure envoyée à un outil au format ISO 8601
complet, jamais une année passée sauf si le visiteur la précise explicitement.
Tu disposes d'outils qui exécutent de VRAIES actions (produits, panier, commandes, rendez-vous, CRM...). N'appelle
un outil QUE si le message correspond clairement à une action que ces outils permettent. Si c'est une question
d'information générale sans action à exécuter, NE CALL AUCUN outil. Si une information manque, demande-la au
visiteur au lieu d'inventer une valeur.
Pour toute question de disponibilité de rendez-vous, préfère find_available_slots à get_busy_periods : le
premier tient compte des horaires d'ouverture configurés, le second non — s'il retourne quand même
working_hours_windows, ne propose jamais un créneau en dehors.
Si une action nécessite un email de contact et que tu ne le connais pas encore, demande-le dès le début de
l'échange plutôt qu'en toute fin.
Certaines actions doivent être déclenchées DE TA PROPRE INITIATIVE dès que la situation l'indique clairement
(ex: un problème signalé → ouvre un ticket ; un intérêt d'achat net → crée une opportunité), sans attendre une
demande explicite — n'agis ainsi que si le signal est net et que l'action est en mode automatique, et ne
duplique jamais une action déjà réalisée dans cette même conversation.
N'appelle jamais clear_cart sauf si le visiteur demande explicitement de vider son panier. Si un outil retourne
un checkout_url, NE L'ÉCRIS JAMAIS dans ta réponse texte : un bouton s'affiche automatiquement séparément.
PROMPT . $this->agentPersona($agent) . $this->workflowGuidance($site, $agentAllowedNames, $agentWorkflowIds);
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

    /**
     * 🆕 Construit la section "workflows recommandés" du prompt : pour chaque
     * recette active de ce site (ou globale si aucune version propre au site),
     * résout chaque étape (capacité abstraite) vers l'outil concret réellement
     * disponible sur CE site. Une recette dont une étape obligatoire n'est pas
     * réalisable est simplement omise plutôt que d'induire le LLM en erreur
     * avec un plan incomplet.
     */
    private function workflowGuidance(Site $site, array $allowedToolNames = [], ?array $agentWorkflowIds = null): string
    {
        $workflows = \App\Models\Mcp\McpWorkflow::where('is_active', true)
            ->where(fn ($q) => $q->where('site_id', $site->id)->orWhereNull('site_id'))
            ->get()
            ->groupBy('slug')
            ->map(fn ($group) => $group->firstWhere('site_id', $site->id) ?? $group->first())
            ->values()
            // 🆕 null = pas d'agent, tous les workflows visibles (inchangé) ;
            // un tableau (même vide) = liste explicite, jamais de repli "tout".
            ->when($agentWorkflowIds !== null, fn ($c) => $c->filter(fn ($w) => in_array($w->id, $agentWorkflowIds, true))); // 🆕

        $lines = [];
        foreach ($workflows as $workflow) {
            $steps = [];
            $blocked = false;

            foreach ($workflow->steps as $step) {
                $toolName = $this->capabilities->resolveToolName($site, $step['capability']);
                $outOfAgentScope = !empty($allowedToolNames) && $toolName && !in_array($toolName, $allowedToolNames, true); // 🆕

                if (!$toolName || $outOfAgentScope) {
                    if (empty($step['optional'])) { $blocked = true; break; }
                    continue;
                }
                $steps[] = $toolName;
            }

            if ($blocked || empty($steps)) continue;
            $lines[] = "- « {$workflow->name} » (déclenchée quand : {$workflow->trigger_description}) : " . implode(' → ', $steps);
        }

        if (empty($lines)) return '';

        return "\n\nWorkflows recommandés pour ce site (suis cette séquence quand la demande du visiteur correspond au déclencheur décrit, en gardant la liberté d'adapter — sauter une étape non pertinente, demander une précision manquante, ou continuer au-delà si besoin) :\n" . implode("\n", $lines);
    }

    /**
     * 🆕 Agent actif pour ce site (un seul pour l'instant). Retourne null si
     * aucun n'est publié — dans ce cas, tout continue de fonctionner
     * exactement comme avant (comportement générique, aucune régression).
     */
    private function activeAgent(Site $site): ?McpAgent
    {
        return McpAgent::where('site_id', $site->id)
            ->where('is_active', true)->where('is_default', true)->first();
    }

    /**
     * 🆕 Filtre les outils déjà permis par le PermissionEngine selon les
     * compétences de l'agent actif. Un agent ne peut JAMAIS élargir l'accès —
     * uniquement le restreindre. Sans agent actif, retourne $tools inchangés.
     */
    private function applyAgentScope(Site $site, array $tools, ?McpAgent $agent): array
    {
        if (!$agent || empty($agent->skills)) {
            return $tools;
        }

        $allowedNames = $this->agentSkills->resolveAllowedToolNames($site, $agent->skills);

        return array_values(array_filter($tools, fn (ToolSchema $t) => in_array($t->qualifiedName(), $allowedNames, true)));
    }

    private function agentPersona(?McpAgent $agent): string
    {
        if (!$agent) return '';

        $toneInstructions = match ($agent->tone) {
            'friendly' => "Adopte un ton chaleureux et décontracté, comme un ami de confiance.",
            'concise' => "Sois le plus concis possible, va droit au but, phrases courtes.",
            'enthusiastic' => "Sois enthousiaste et engageant, transmets de l'énergie positive.",
            'custom' => $agent->custom_tone_instructions ?? '',
            default => "Adopte un ton professionnel et posé.",
        };

        $objective = $agent->objective ? "Ton objectif principal : {$agent->objective}." : '';

        return "\n\nTu incarnes ici l'agent « {$agent->name} ». {$objective} {$toneInstructions}";
    }
}
