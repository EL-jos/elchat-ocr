<?php

namespace App\Domain\MCP\Connectors;

use App\Domain\Proactive\ProactiveSequenceService;
use App\Domain\RAG\RAGToolAdapter;
use App\Domain\MCP\Contracts\{ToolResult, ToolSchema};
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use App\Models\{Conversation, Message, Site, UnansweredQuestion, User, Visitor};
use App\Models\Mcp\{McpAgent, McpConnector, McpWorkflow};
use App\Models\Proactive\{ProactiveAuditLog, ProactiveCampaign, ProactiveSequence};
use App\Models\Social\SocialConversationLink; // ⚠️ suppose le nom conventionnel du modèle, non vu directement
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Connecteur MCP INTERNE — jamais d'accès SQL direct pour le LLM, uniquement
 * des méthodes Eloquent scopées au site courant (sauf list_sites, scopé au
 * compte propriétaire — voir listSites()). Aucun credential tiers : le
 * "connecteur" existe pour offrir la même interface homogène
 * (listTools/authenticate/callTool) que WooCommerce, HubSpot, Odoo... — pour
 * le LLM, ELChat lui-même n'est qu'un connecteur de plus.
 */
class ElchatPlatformConnector extends AbstractConnector
{
    public function __construct(
        private readonly RAGToolAdapter $ragToolAdapter,
        private readonly ProactiveSequenceService $proactiveSequences,
    )
    {}

    public function slug(): string { return 'elchat_platform'; }

    public function authenticate(array $credentials): array
    {
        return $credentials; // aucune donnée externe requise
    }

    public function listTools(): array
    {
        return [
            ...$this->conversationTools(),
            ...$this->messageTools(),
            ...$this->userTools(),
            ...$this->analyticsTools(),
            ...$this->knowledgeTools(), // 🆕
            ...$this->adminTools(),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        $site = Site::find($context['site_id'] ?? null);
        if (!$site) return ToolResult::fail('not_found', 'Site introuvable dans ce contexte.');

        return match ($toolName) {
            'get_conversation' => $this->getConversation($params, $site),
            'search_conversations' => $this->searchConversations($params, $site),
            'list_open_conversations' => $this->listOpenConversations($params, $site),
            'summarize_conversation' => $this->summarizeConversation($params, $site),
            'close_conversation' => $this->closeConversation($params, $site),

            'search_messages' => $this->searchMessages($params, $site),
            'count_unanswered_messages' => $this->countUnansweredMessages($site),
            'latest_messages' => $this->latestMessages($params, $site),

            'find_user' => $this->findUser($params, $site),
            'get_user' => $this->getUser($params, $site),

            'count_conversations_today' => $this->countConversationsToday($site),
            'average_response_time' => $this->averageResponseTime($params, $site),
            'top_questions' => $this->topQuestions($params, $site),
            'channels_usage' => $this->channelsUsage($site),
            'active_visitors' => $this->activeVisitors($params, $site),
            'new_leads' => $this->newLeads($params, $site),

            'search_knowledge_base' => $this->searchKnowledgeBase($params, $site), // 🆕

            'list_sites' => $this->listSites($site),
            'list_agents' => $this->listAgents($site),
            'list_workflows' => $this->listWorkflows($site),
            'list_connectors' => $this->listConnectors($site),
            'activate_workflow' => $this->activateWorkflow($params, $site),
            'schedule_proactive_message' => $this->scheduleProactiveMessage($params, $site),
            'stop_proactive_sequence' => $this->stopProactiveSequence($params, $site),
            'get_proactive_status' => $this->getProactiveStatus($params, $site),
            'get_proactive_history' => $this->getProactiveHistory($params, $site),

            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour elchat_platform."),
        };
    }

    // =====================================================================
    // 💬 CONVERSATIONS — tout en actor_scope: admin (cockpit interne)
    // =====================================================================

    private function conversationTools(): array
    {
        return [
            new ToolSchema('elchat_platform', 'get_conversation',
                "Récupère les informations détaillées d'une conversation identifiée de manière fiable. Utilise cet outil uniquement lorsqu'un conversation_id valide est déjà connu. Ne l'utilise pas pour rechercher une conversation. Si l'identifiant est inconnu ou ambigu, utilise d'abord search_conversations ou list_open_conversations. Exploite les données retournées sans les compléter ni les interpréter comme des faits.", [
                'type' => 'object', 'properties' => ['conversation_id' => ['type' => 'string']], 'required' => ['conversation_id'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('elchat_platform', 'search_conversations',
                "Recherche une ou plusieurs conversations à partir de critères connus (texte, statut, canal ou période). Privilégie cet outil lorsqu'aucun identifiant fiable n'est disponible. Utilise uniquement les filtres explicitement demandés ou déduits avec certitude du contexte. Si plusieurs conversations correspondent, présente-les à l'utilisateur avant toute action. Ne déduis jamais un identifiant.", [
                'type' => 'object', 'properties' => [
                    'query' => ['type' => 'string'], 'status' => ['type' => 'string'], 'channel' => ['type' => 'string'],
                    'since' => ['type' => 'string', 'description' => 'ISO 8601'],
                ],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('elchat_platform', 'list_open_conversations', "Liste les conversations actives en attente d'une réponse (dernier message envoyé par le visiteur), regroupées par canal.", [
                'type' => 'object', 'properties' => ['channel' => ['type' => 'string']],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('elchat_platform', 'summarize_conversation',
                "Fournit un résumé d'une conversation. Si un résumé est déjà disponible, utilise-le en priorité. Sinon, l'outil renvoie les derniers échanges afin que tu produises toi-même une synthèse fidèle. Ne crée jamais de faits absents de la transcription et indique clairement lorsqu'une information n'apparaît pas dans les messages.", [
                'type' => 'object', 'properties' => ['conversation_id' => ['type' => 'string']], 'required' => ['conversation_id'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('elchat_platform', 'close_conversation',
                "Clôture définitivement une conversation identifiée. Utilise cet outil uniquement lorsqu'une demande explicite de clôture est formulée ou lorsqu'une politique métier l'autorise. Ne ferme jamais une conversation sur simple supposition. Vérifie que le conversation_id est connu avant l'appel. Évite toute exécution répétée de cette action.", [
                'type' => 'object', 'properties' => ['conversation_id' => ['type' => 'string']], 'required' => ['conversation_id'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    private function getConversation(array $p, Site $site): ToolResult
    {
        $conversation = Conversation::where('site_id', $site->id)->find($p['conversation_id']);
        if (!$conversation) return ToolResult::fail('not_found', 'Conversation introuvable.');

        $lastMessages = $conversation->messages()->reorder('created_at', 'desc')->limit(5)->get(['id', 'role', 'content', 'created_at']);

        return ToolResult::ok([
            'id' => $conversation->id, 'status' => $conversation->status, 'channel' => $this->channelFor($conversation),
            'summary' => $conversation->summary, 'created_at' => $conversation->created_at,
            'last_messages' => $lastMessages->reverse()->values(),
        ], "Conversation #{$conversation->id} récupérée.");
    }

    private function searchConversations(array $p, Site $site): ToolResult
    {
        $query = Conversation::where('site_id', $site->id);

        if (!empty($p['status'])) $query->where('status', $p['status']);
        if (!empty($p['since'])) $query->where('created_at', '>=', Carbon::parse($p['since']));
        if (!empty($p['query'])) {
            $query->whereHas('messages', fn ($q) => $q->where('content', 'like', '%' . $p['query'] . '%'));
        }

        $conversations = $query->orderByDesc('created_at')->limit(30)->get();

        if (!empty($p['channel'])) {
            $conversations = $conversations->filter(fn ($c) => $this->channelFor($c) === $p['channel'])->values();
        }

        if ($conversations->isEmpty()) return ToolResult::fail('not_found', 'Aucune conversation trouvée.');

        return ToolResult::ok(['conversations' => $conversations->map(fn ($c) => [
            'id' => $c->id, 'status' => $c->status, 'channel' => $this->channelFor($c), 'created_at' => $c->created_at,
        ])], count($conversations) . ' conversation(s) trouvée(s)');
    }

    private function listOpenConversations(array $p, Site $site): ToolResult
    {
        $conversations = Conversation::where('site_id', $site->id)
            ->where('status', 'active')
            ->with(['messages' => fn ($q) => $q->reorder('created_at', 'desc')->limit(1)]) // 🆕 reorder() : le global scope asc de Message écraserait sinon un simple latest()
            ->limit(200)
            ->get()
            ->filter(fn ($c) => $c->messages->first()?->role === 'user')
            ->values();

        if (!empty($p['channel'])) {
            $conversations = $conversations->filter(fn ($c) => $this->channelFor($c) === $p['channel'])->values();
        }

        if ($conversations->isEmpty()) return ToolResult::fail('not_found', 'Aucune conversation en attente de réponse.');

        $byChannel = $conversations->groupBy(fn ($c) => $this->channelFor($c))->map->count();

        return ToolResult::ok([
            'total' => $conversations->count(), 'by_channel' => $byChannel,
            'conversation_ids' => $conversations->pluck('id'),
        ], "{$conversations->count()} conversation(s) en attente de réponse.");
    }

    private function summarizeConversation(array $p, Site $site): ToolResult
    {
        $conversation = Conversation::where('site_id', $site->id)->find($p['conversation_id']);
        if (!$conversation) return ToolResult::fail('not_found', 'Conversation introuvable.');

        if (!empty($conversation->summary)) {
            return ToolResult::ok(['summary' => $conversation->summary, 'generated' => true], 'Résumé existant récupéré.');
        }

        $recent = $conversation->messages()->reorder('created_at', 'asc')->limit(20)
            ->get(['role', 'content'])->map(fn ($m) => "{$m->role}: {$m->content}")->implode("\n");

        if ($recent === '') return ToolResult::fail('not_found', 'Aucun message dans cette conversation.');

        return ToolResult::ok(['summary' => null, 'raw_transcript' => $recent, 'generated' => false],
            "Pas de résumé pré-généré — voici les derniers échanges, synthétise-les toi-même.");
    }

    private function closeConversation(array $p, Site $site): ToolResult
    {
        $conversation = Conversation::where('site_id', $site->id)->find($p['conversation_id']);
        if (!$conversation) return ToolResult::fail('not_found', 'Conversation introuvable.');

        $conversation->update(['status' => 'closed']);
        return ToolResult::ok(['conversation_id' => $conversation->id], 'Conversation clôturée.');
    }

    // =====================================================================
    // 📨 MESSAGES
    // =====================================================================

    private function messageTools(): array
    {
        return [
            new ToolSchema('elchat_platform', 'search_messages',
                "Recherche des messages contenant un texte donné dans une conversation spécifique ou sur l'ensemble du site. Utilise cet outil lorsqu'il est nécessaire de retrouver un contenu précis et qu'un résumé est insuffisant. Ne l'utilise pas pour récupérer une conversation complète.", [
                'type' => 'object', 'properties' => ['query' => ['type' => 'string'], 'conversation_id' => ['type' => 'string']], 'required' => ['query'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('elchat_platform', 'count_unanswered_messages', "Nombre de conversations dont le dernier message (du visiteur) n'a pas encore reçu de réponse.", ['type' => 'object', 'properties' => []],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('elchat_platform', 'latest_messages', "Messages les plus récents, tous canaux confondus.", [
                'type' => 'object', 'properties' => ['limit' => ['type' => 'integer']],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    private function searchMessages(array $p, Site $site): ToolResult
    {
        $query = Message::whereHas('conversation', fn ($q) => $q->where('site_id', $site->id))
            ->where('content', 'like', '%' . $p['query'] . '%');

        if (!empty($p['conversation_id'])) $query->where('conversation_id', $p['conversation_id']);

        $messages = $query->reorder('created_at', 'desc')->limit(20)->get(['id', 'conversation_id', 'role', 'content', 'created_at']);

        if ($messages->isEmpty()) return ToolResult::fail('not_found', 'Aucun message trouvé.');
        return ToolResult::ok(['messages' => $messages], count($messages) . ' message(s) trouvé(s)');
    }

    private function countUnansweredMessages(Site $site): ToolResult
    {
        $count = Conversation::where('site_id', $site->id)
            ->where('status', 'active')
            ->with(['messages' => fn ($q) => $q->reorder('created_at', 'desc')->limit(1)])
            ->limit(500)
            ->get()
            ->filter(fn ($c) => $c->messages->first()?->role === 'user')
            ->count();

        return ToolResult::ok(['count' => $count], "{$count} conversation(s) en attente de réponse.");
    }

    private function latestMessages(array $p, Site $site): ToolResult
    {
        $limit = min(50, (int) ($p['limit'] ?? 20));
        $messages = Message::whereHas('conversation', fn ($q) => $q->where('site_id', $site->id))
            ->reorder('created_at', 'desc')->limit($limit)->get(['id', 'conversation_id', 'role', 'content', 'created_at']);

        return ToolResult::ok(['messages' => $messages], count($messages) . ' message(s) récupéré(s)');
    }

    // =====================================================================
    // 👤 UTILISATEURS
    // =====================================================================

    private function userTools(): array
    {
        return [
            new ToolSchema('elchat_platform', 'find_user',
                "Recherche un utilisateur appartenant au site courant à partir d'un nom, prénom, email ou fragment d'information. Utilise cet outil uniquement lorsqu'aucun user_id fiable n'est disponible. Si plusieurs utilisateurs correspondent, demande une précision avant toute opération utilisant un utilisateur unique.", [
                'type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('elchat_platform', 'get_user',
                "Récupère les informations détaillées d'un utilisateur identifié. Utilise uniquement un user_id obtenu précédemment ou fourni explicitement. Ne tente jamais de deviner l'identifiant.", [
                'type' => 'object', 'properties' => ['user_id' => ['type' => 'string']], 'required' => ['user_id'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    private function findUser(array $p, Site $site): ToolResult
    {
        // 🔒 Scopé aux users réellement liés à CE site (pivot sites_users),
        // jamais une recherche globale plateforme.
        $users = $site->users()
            ->where(fn ($q) => $q->where('email', 'like', '%' . $p['query'] . '%')
                ->orWhere('firstname', 'like', '%' . $p['query'] . '%')
                ->orWhere('lastname', 'like', '%' . $p['query'] . '%'))
            ->limit(10)->get(['users.id', 'firstname', 'lastname', 'email', 'phone']);

        if ($users->isEmpty()) return ToolResult::fail('not_found', 'Aucun utilisateur trouvé.');
        return ToolResult::ok(['users' => $users], count($users) . ' utilisateur(s) trouvé(s)');
    }

    private function getUser(array $p, Site $site): ToolResult
    {
        $user = $site->users()->where('users.id', $p['user_id'])->first(['users.id', 'firstname', 'lastname', 'email', 'phone', 'created_at']);
        if (!$user) return ToolResult::fail('not_found', 'Utilisateur introuvable pour ce site.');
        return ToolResult::ok($user->toArray(), 'Utilisateur récupéré.');
    }

    // =====================================================================
    // 📊 ANALYTICS
    // =====================================================================

    private function analyticsTools(): array
    {
        return [
            new ToolSchema('elchat_platform', 'count_conversations_today', "Nombre de conversations démarrées aujourd'hui.", ['type' => 'object', 'properties' => []],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('elchat_platform', 'average_response_time',
                "Calcule le temps moyen séparant un message utilisateur de la première réponse du bot sur la période demandée. Utilise cet outil uniquement pour produire des indicateurs de performance. N'interprète pas cette valeur comme un indicateur de satisfaction.", [
                'type' => 'object', 'properties' => ['since' => ['type' => 'string', 'description' => 'ISO 8601, défaut: 24h']],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('elchat_platform', 'top_questions',
                "Utilise cet outil pour identifier les lacunes de la base de connaissances. Les résultats représentent des questions enregistrées comme non résolues et ne doivent pas être interprétés comme l'ensemble des demandes des utilisateurs.", [
                'type' => 'object', 'properties' => ['limit' => ['type' => 'integer']],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('elchat_platform', 'channels_usage', "Répartition des conversations par canal.", ['type' => 'object', 'properties' => []],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('elchat_platform', 'active_visitors',
                "Estime le nombre de visiteurs ayant eu une activité récente. Cette valeur dépend de la fenêtre temporelle demandée et représente un instantané, pas une mesure en temps réel permanente.", [
                'type' => 'object', 'properties' => ['minutes' => ['type' => 'integer', 'description' => 'défaut: 15']],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('elchat_platform', 'new_leads', "Nombre de nouveaux visiteurs identifiés (transformés en clients) sur une période.", [
                'type' => 'object', 'properties' => ['since' => ['type' => 'string', 'description' => 'ISO 8601, défaut: 7 jours']],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    private function countConversationsToday(Site $site): ToolResult
    {
        $count = Conversation::where('site_id', $site->id)->whereDate('created_at', now()->toDateString())->count();
        return ToolResult::ok(['count' => $count], "{$count} conversation(s) aujourd'hui.");
    }

    private function averageResponseTime(array $p, Site $site): ToolResult
    {
        $since = !empty($p['since']) ? Carbon::parse($p['since']) : now()->subDay();

        $messages = Message::whereHas('conversation', fn ($q) => $q->where('site_id', $site->id))
            ->where('created_at', '>=', $since)
            ->reorder('created_at', 'asc')
            ->get(['conversation_id', 'role', 'created_at']);

        $diffs = [];
        foreach ($messages->groupBy('conversation_id') as $convMessages) {
            $convMessages = $convMessages->values();
            for ($i = 0; $i < $convMessages->count() - 1; $i++) {
                if ($convMessages[$i]->role === 'user' && $convMessages[$i + 1]->role === 'bot') {
                    $diffs[] = $convMessages[$i + 1]->created_at->diffInSeconds($convMessages[$i]->created_at);
                }
            }
        }

        if (empty($diffs)) return ToolResult::fail('not_found', 'Pas assez de données sur cette période.');

        $avg = round(array_sum($diffs) / count($diffs), 1);
        return ToolResult::ok(['average_seconds' => $avg, 'sample_size' => count($diffs)], "Temps de réponse moyen : {$avg}s sur " . count($diffs) . ' échange(s).');
    }

    private function topQuestions(array $p, Site $site): ToolResult
    {
        $limit = min(20, (int) ($p['limit'] ?? 10));

        $rows = UnansweredQuestion::where('site_id', $site->id)
            ->selectRaw('question, count(*) as occurrences')
            ->groupBy('question')
            ->orderByDesc('occurrences')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) return ToolResult::fail('not_found', 'Aucune question sans réponse enregistrée.');
        return ToolResult::ok(['questions' => $rows], count($rows) . " question(s) fréquemment sans réponse — pistes d'amélioration de la base de connaissances.");
    }

    private function channelsUsage(Site $site): ToolResult
    {
        $conversations = Conversation::where('site_id', $site->id)->limit(1000)->get(['id']);
        $byChannel = $conversations->groupBy(fn ($c) => $this->channelFor($c))->map->count();

        return ToolResult::ok(['usage' => $byChannel], 'Répartition par canal calculée.');
    }

    private function activeVisitors(array $p, Site $site): ToolResult
    {
        $since = now()->subMinutes((int) ($p['minutes'] ?? 15));

        $count = Visitor::where('site_id', $site->id)
            ->whereHas('conversations.messages', fn ($q) => $q->where('created_at', '>=', $since))
            ->count();

        return ToolResult::ok(['count' => $count], "{$count} visiteur(s) actif(s) sur les {$p['minutes']} dernières minutes.");
    }

    private function newLeads(array $p, Site $site): ToolResult
    {
        $since = !empty($p['since']) ? Carbon::parse($p['since']) : now()->subDays(7);

        // ⚠️ Suppose une relation Site::users() belongsToMany(User) symétrique
        // à User::sites() — à ajouter si absente (voir note en fin de réponse).
        $count = $site->users()->wherePivot('first_seen_at', '>=', $since)->count();

        return ToolResult::ok(['count' => $count], "{$count} nouveau(x) client(s) identifié(s) depuis {$since->toDateString()}.");
    }

    // =====================================================================
    // 🔎 BASE DE CONNAISSANCES
    // =====================================================================
    //
    // Délègue entièrement à RAGToolAdapter (même embedding + recherche
    // hybride + hydratation que le reste de la plateforme) plutôt que de
    // dupliquer cette logique ici. Volontairement en actor_scope 'admin',
    // comme le reste des outils de ce connecteur (cockpit interne) : le
    // tool 'knowledge_base__search' de RAGToolAdapter reste, lui, le point
    // d'entrée pour un visiteur en cours d'action (voir
    // MCPActionGateService::handleForAgent(), qui l'ajoute automatiquement
    // à chaque appel, hors du système de permissions normal).

    private function knowledgeTools(): array
    {
        return [
            new ToolSchema('elchat_platform', 'search_knowledge_base',
                "Recherche dans la base de connaissances du site (FAQ, politiques, catalogue, documents indexés) pour une information ponctuelle nécessaire à une action de pilotage en cours (ex: vérifier un fait avant de rédiger un résumé ou une réponse). Réutilise le même moteur de recherche que le reste de la plateforme — les résultats ne sont jamais réinventés ni complétés. Ne PAS utiliser pour une question purement informationnelle d'un visiteur qui ne déclenche aucune action : elle est déjà traitée par le pipeline RAG principal du site, hors de cette boucle d'outils.",
                [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Requête de recherche'],
                        'limit' => ['type' => 'integer', 'description' => "nombre maximum d'extraits retournés, défaut 8, max 20"],
                    ],
                    'required' => ['query'],
                ],
                defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    private function searchKnowledgeBase(array $p, Site $site): ToolResult
    {
        $query = trim($p['query'] ?? '');
        if ($query === '') {
            return ToolResult::fail('invalid_request', 'Une requête de recherche est requise.');
        }

        $limit = max(1, min(20, (int) ($p['limit'] ?? 8)));

        return $this->ragToolAdapter->search($site, $query, $limit);
    }

    // =====================================================================
    // ⚙️ ADMINISTRATION
    // =====================================================================

    private function adminTools(): array
    {
        return [
            new ToolSchema('elchat_platform', 'list_sites', "Liste les sites appartenant au même compte que le site courant.", ['type' => 'object', 'properties' => []],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('elchat_platform', 'list_agents', "Liste les agents IA configurés sur ce site.", ['type' => 'object', 'properties' => []],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('elchat_platform', 'list_workflows', "Liste les recettes de workflow disponibles sur ce site.", ['type' => 'object', 'properties' => []],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('elchat_platform', 'list_connectors', "Liste les connecteurs MCP actifs sur ce site.", ['type' => 'object', 'properties' => []],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('elchat_platform', 'activate_workflow',
                "Active un workflow existant identifié par son nom exact. Utilise uniquement lorsque l'utilisateur demande explicitement d'activer ou de réactiver un workflow. Si plusieurs workflows portent des noms proches ou si le nom est ambigu, demande une confirmation avant l'appel. Ne crée jamais un workflow et ne tente jamais de corriger son nom automatiquement.", [
                'type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('elchat_platform', 'schedule_proactive_message',
                "Planifie un message dans une campagne proactive déjà active et une conversation existante. N'invente jamais d'identifiant et exige une demande explicite de l'administrateur.", [
                    'type' => 'object', 'properties' => [
                        'campaign_id' => ['type' => 'string'], 'conversation_id' => ['type' => 'string'],
                        'scheduled_at' => ['type' => 'string', 'description' => 'ISO 8601, optionnel'],
                        'content' => ['type' => 'string'], 'idempotency_key' => ['type' => 'string'],
                    ], 'required' => ['campaign_id', 'conversation_id'],
                ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'confirm', capability: 'proactive.schedule'),

            new ToolSchema('elchat_platform', 'stop_proactive_sequence',
                "Arrête une séquence proactive existante sans supprimer son historique.", [
                    'type' => 'object', 'properties' => ['sequence_id' => ['type' => 'string'], 'reason' => ['type' => 'string']], 'required' => ['sequence_id'],
                ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'confirm', capability: 'proactive.stop'),

            new ToolSchema('elchat_platform', 'get_proactive_status',
                "Consulte l'état d'une campagne ou d'une séquence proactive sans lancer d'action.", [
                    'type' => 'object', 'properties' => ['campaign_id' => ['type' => 'string'], 'sequence_id' => ['type' => 'string']],
                ], defaultActorScope: 'admin', defaultMode: 'auto', capability: 'proactive.read'),

            new ToolSchema('elchat_platform', 'get_proactive_history',
                "Consulte le journal explicable des campagnes proactives du site.", [
                    'type' => 'object', 'properties' => ['campaign_id' => ['type' => 'string'], 'limit' => ['type' => 'integer']],
                ], defaultActorScope: 'admin', defaultMode: 'auto', capability: 'proactive.read'),
        ];
    }

    private function listSites(Site $site): ToolResult
    {
        $accountId = $site->account_id ?? null; // ⚠️ colonne supposée, à confirmer via Site.php

        if (!$accountId) {
            return ToolResult::ok(
                ['sites' => [['id' => $site->id, 'name' => $site->name ?? $site->url]]],
                '1 site (portée compte non détectée — voir Site.php pour activer le multi-site).'
            );
        }

        $sites = Site::where('account_id', $accountId)->get(['id', 'name', 'url']);
        return ToolResult::ok(['sites' => $sites], count($sites) . ' site(s) du même compte.');
    }

    private function listAgents(Site $site): ToolResult
    {
        $agents = McpAgent::where('site_id', $site->id)->get(['id', 'name', 'objective', 'is_active', 'is_default']);
        if ($agents->isEmpty()) return ToolResult::fail('not_found', 'Aucun agent configuré.');
        return ToolResult::ok(['agents' => $agents], count($agents) . ' agent(s)');
    }

    private function listWorkflows(Site $site): ToolResult
    {
        $workflows = McpWorkflow::where(fn ($q) => $q->where('site_id', $site->id)->orWhereNull('site_id'))
            ->get(['id', 'name', 'trigger_description', 'is_active']);
        if ($workflows->isEmpty()) return ToolResult::fail('not_found', 'Aucun workflow disponible.');
        return ToolResult::ok(['workflows' => $workflows], count($workflows) . ' workflow(s)');
    }

    private function listConnectors(Site $site): ToolResult
    {
        $connectors = $site->mcpSiteConnectors()->with('mcpConnector')->get()
            ->map(fn ($c) => ['slug' => $c->mcpConnector->slug, 'name' => $c->mcpConnector->name, 'status' => $c->status]);

        return ToolResult::ok(['connectors' => $connectors], count($connectors) . ' connecteur(s) configuré(s)');
    }

    private function activateWorkflow(array $p, Site $site): ToolResult
    {
        $workflow = McpWorkflow::where('site_id', $site->id)->where('name', $p['name'])->first();
        if (!$workflow) return ToolResult::fail('not_found', "Aucun workflow propre à ce site nommé « {$p['name']} ».");

        $workflow->update(['is_active' => true]);
        return ToolResult::ok(['workflow_id' => $workflow->id], "Workflow « {$p['name']} » activé.");
    }

    // =====================================================================
    // Utilitaires
    // =====================================================================

    private function scheduleProactiveMessage(array $p, Site $site): ToolResult
    {
        $campaign = ProactiveCampaign::query()->where('site_id', $site->id)->where('status', 'active')->find($p['campaign_id'] ?? null);
        if (!$campaign) return ToolResult::fail('not_found', 'Campagne proactive active introuvable.');

        $message = $this->proactiveSequences->scheduleManual($campaign, [
            'conversation_id' => $p['conversation_id'] ?? null,
            'scheduled_at' => $p['scheduled_at'] ?? null,
        ], $p['content'] ?? null, !empty($p['idempotency_key']) ? hash('sha256', (string) $p['idempotency_key']) : null);

        return ToolResult::ok(['message_id' => $message->id, 'sequence_id' => $message->sequence_id, 'scheduled_at' => $message->scheduled_at], 'Message proactif planifié.');
    }

    private function stopProactiveSequence(array $p, Site $site): ToolResult
    {
        $sequence = ProactiveSequence::query()->where('site_id', $site->id)->find($p['sequence_id'] ?? null);
        if (!$sequence) return ToolResult::fail('not_found', 'Séquence proactive introuvable.');
        if ($sequence->status !== 'active') return ToolResult::ok(['sequence_id' => $sequence->id, 'status' => $sequence->status], 'La séquence était déjà arrêtée.');

        $reason = mb_substr((string) ($p['reason'] ?? 'mcp_admin_stop'), 0, 64);
        $sequence->update(['status' => 'stopped', 'stopped_at' => now(), 'stop_reason' => $reason, 'next_scheduled_at' => null]);
        $sequence->messages()->whereIn('status', ['scheduled', 'retrying'])->update(['status' => 'canceled', 'canceled_at' => now(), 'failure_code' => 'sequence_stopped']);
        return ToolResult::ok(['sequence_id' => $sequence->id, 'status' => 'stopped'], 'Séquence proactive arrêtée.');
    }

    private function getProactiveStatus(array $p, Site $site): ToolResult
    {
        if (!empty($p['sequence_id'])) {
            $sequence = ProactiveSequence::query()->where('site_id', $site->id)->withCount('messages')->find($p['sequence_id']);
            return $sequence ? ToolResult::ok(['sequence' => $sequence], 'Statut de la séquence récupéré.') : ToolResult::fail('not_found', 'Séquence introuvable.');
        }
        if (!empty($p['campaign_id'])) {
            $campaign = ProactiveCampaign::query()->where('site_id', $site->id)->withCount(['sequences', 'messages', 'outcomes'])->find($p['campaign_id']);
            return $campaign ? ToolResult::ok(['campaign' => $campaign], 'Statut de la campagne récupéré.') : ToolResult::fail('not_found', 'Campagne introuvable.');
        }
        return ToolResult::fail('invalid_request', 'campaign_id ou sequence_id est requis.');
    }

    private function getProactiveHistory(array $p, Site $site): ToolResult
    {
        $query = ProactiveAuditLog::query()->where('site_id', $site->id);
        if (!empty($p['campaign_id'])) $query->where('campaign_id', $p['campaign_id']);
        $logs = $query->latest('created_at')->limit(min(100, max(1, (int) ($p['limit'] ?? 25))))->get();
        return ToolResult::ok(['history' => $logs], $logs->count().' entrée(s) du journal proactif.');
    }

    private function channelFor(Conversation $conversation): string
    {
        try {
            $link = SocialConversationLink::where('conversation_id', $conversation->id)->with('socialConversation')->first();
            return $link?->socialConversation?->provider ?? 'widget';
        } catch (\Throwable $e) {
            Log::warning('MCP ElchatPlatform: résolution du canal échouée', ['error' => $e->getMessage()]);
            return 'widget'; // repli neutre, jamais une exception qui casse tout l'outil appelant
        }
    }
}
