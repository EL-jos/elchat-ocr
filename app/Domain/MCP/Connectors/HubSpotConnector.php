<?php

namespace App\Domain\MCP\Connectors;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\AuthExpiredException;
use App\Domain\MCP\Exceptions\ConnectorUnavailableException;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Connecteur HubSpot (CRM). Auth par Private App Token (Bearer), généré
 * dans HubSpot → Paramètres → Intégrations → Apps privées, avec les scopes :
 * crm.objects.contacts.write/read, crm.objects.deals.write/read,
 * crm.objects.notes.write/read, crm.objects.tasks.write/read,
 * crm.objects.meetings.write/read, crm.objects.calls.write/read,
 * tickets, files, crm.objects.owners.read.
 * credentials attendus (déchiffrés) : { "access_token": "pat-..." }
 */
class HubSpotConnector extends AbstractConnector
{
    private const API_BASE = 'https://api.hubapi.com';

    public function slug(): string
    {
        return 'hubspot';
    }

    public function authenticate(array $credentials): array
    {
        if (empty($credentials['access_token'])) {
            throw new AuthExpiredException('Jeton HubSpot (Private App Token) manquant.');
        }
        return $credentials; // pas de rafraîchissement : un token d'app privée n'expire pas
    }

    public function listTools(): array
    {
        return [
            ...$this->contactTools(),
            ...$this->dealTools(),
            ...$this->noteTools(),
            ...$this->taskTools(),
            ...$this->meetingTools(),
            ...$this->emailTools(),
            ...$this->callTools(),
            ...$this->ticketTools(),
            ...$this->fileTools(),
            ...$this->aiTools(),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            'create_contact' => $this->createContact($params, $credentials, $context),
            'find_contact' => $this->findContact($params, $credentials),
            'update_contact' => $this->updateContact($params, $credentials, $context),
            'search_contacts' => $this->searchContacts($params, $credentials),
            'get_contact' => $this->getContact($params, $credentials),

            'create_deal' => $this->createDeal($params, $credentials),
            'update_deal' => $this->updateDeal($params, $credentials),
            'get_deal' => $this->getDeal($params, $credentials),
            'search_deals' => $this->searchDeals($params, $credentials),
            'close_deal' => $this->closeDeal($params, $credentials),

            'add_note' => $this->addNote($params, $credentials),
            'list_notes' => $this->listNotes($params, $credentials),

            'create_task' => $this->createTask($params, $credentials),
            'update_task' => $this->updateTask($params, $credentials),
            'complete_task' => $this->completeTask($params, $credentials),
            'list_tasks' => $this->listTasks($params, $credentials),

            'create_meeting' => $this->createMeeting($params, $credentials),
            'cancel_meeting' => $this->cancelMeeting($params, $credentials),
            'search_meetings' => $this->searchMeetings($params, $credentials),

            'log_email' => $this->logEmail($params, $credentials),
            'send_email' => $this->sendEmail($params, $credentials),

            'log_call' => $this->logCall($params, $credentials),

            'create_ticket' => $this->createTicket($params, $credentials, $context),
            'update_ticket' => $this->updateTicket($params, $credentials),
            'get_ticket' => $this->getTicket($params, $credentials),
            'search_tickets' => $this->searchTickets($params, $credentials),
            'close_ticket' => $this->closeTicket($params, $credentials),

            'upload_file' => $this->uploadFile($params, $credentials),
            'attach_file_to_contact' => $this->attachFileToContact($params, $credentials),

            'qualify_lead' => $this->qualifyLead($params, $credentials),
            'score_lead' => $this->scoreLead($params, $credentials),
            'assign_owner' => $this->assignOwner($params, $credentials, $context),
            'summarize_contact' => $this->summarizeContact($params, $credentials),

            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour hubspot."),
        };
    }

    // =====================================================================
    // 🏢 CONTACTS
    // =====================================================================

    private function contactTools(): array
    {
        return [
            new ToolSchema('hubspot', 'create_contact',
                "Crée un nouveau contact HubSpot uniquement lorsqu'aucun contact existant ne correspond à la personne décrite par l'utilisateur. Si un email, un numéro de téléphone ou un nom est fourni sans identifiant HubSpot, rechercher d'abord les contacts existants afin d'éviter les doublons. Vérifier que les informations obligatoires sont disponibles avant la création. Ne jamais créer un contact à partir d'informations supposées ou incomplètes. Utiliser exclusivement les données fournies par l'utilisateur et retourner uniquement le résultat réel de l'outil.", [
                'type' => 'object',
                'properties' => [
                    'firstname' => ['type' => 'string'], 'lastname' => ['type' => 'string'],
                    'email' => ['type' => 'string'], 'phone' => ['type' => 'string'], 'company' => ['type' => 'string'],
                ], 'required' => ['email'],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'crm.create_or_update_contact'),

            new ToolSchema('hubspot', 'find_contact',
                "Recherche et identifie un contact HubSpot à partir d'un nom, d'une adresse e-mail, d'un numéro de téléphone ou d'autres informations connues. Utiliser avant toute opération nécessitant un contact lorsque son identifiant est inconnu. Si plusieurs contacts correspondent, demander une clarification avant de poursuivre. Ne jamais supposer qu'un contact est unique.", [
                'type' => 'object', 'properties' => ['email' => ['type' => 'string']], 'required' => ['email'],
            ], defaultMode: 'auto'),

            new ToolSchema('hubspot', 'update_contact',
                "Met à jour un contact HubSpot existant identifié de manière unique. Modifier uniquement les propriétés explicitement demandées par l'utilisateur et préserver toutes les autres informations. Si l'identifiant est inconnu, rechercher d'abord le contact. En cas de plusieurs correspondances, demander une clarification avant toute modification.", [
                'type' => 'object', 'properties' => [
                    'phone' => ['type' => 'string'], 'email' => ['type' => 'string'],
                    'company' => ['type' => 'string'], 'address' => ['type' => 'string'],
                ],
            ], isWriteAction: true, defaultMode: 'confirm', defaultConfirmActor: 'visitor', capability: 'crm.create_or_update_contact'),

            new ToolSchema('hubspot', 'search_contacts',
                "Recherche un ou plusieurs contacts HubSpot à partir d'un nom, d'une adresse e-mail, d'un numéro de téléphone ou d'autres informations connues. Utiliser cet outil pour retrouver un contact avant toute consultation, modification ou création lorsqu'un identifiant HubSpot n'est pas disponible. Si plusieurs contacts correspondent, demander une clarification avant toute action. Ne jamais supposer qu'un résultat est unique.", [
                'type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('hubspot', 'get_contact',
                "Récupère les informations complètes d'un contact HubSpot identifié de manière unique. Utiliser lorsque l'identifiant du contact est connu ou après une recherche ayant permis d'identifier un seul contact. Ne jamais inventer ou déduire un identifiant HubSpot.", [
                'type' => 'object', 'properties' => ['contact_id' => ['type' => 'string']], 'required' => ['contact_id'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    private function createContact(array $p, array $c, array $ctx): ToolResult
    {
        try {
            $contact = $this->client($c)->post('/crm/v3/objects/contacts', [
                'properties' => array_filter([
                    'firstname' => $p['firstname'] ?? null, 'lastname' => $p['lastname'] ?? null,
                    'email' => $p['email'], 'phone' => $p['phone'] ?? null, 'company' => $p['company'] ?? null,
                ]),
            ])->json();
        } catch (RequestException $e) {
            if ($e->response?->status() === 409) {
                return ToolResult::fail('already_exists', 'Un contact existe déjà avec cet email.');
            }
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();

        return ToolResult::ok(
            ['contact_id' => $contact['id'], 'email' => $p['email']],
            'Contact créé.',
            identity: ['email' => $p['email'], 'firstname' => $p['firstname'] ?? null, 'lastname' => $p['lastname'] ?? null, 'phone' => $p['phone'] ?? null],
        );
    }

    private function findContact(array $p, array $c): ToolResult
    {
        $contact = $this->searchContactByEmail($p['email'], $c);

        if (!$contact) {
            return ToolResult::ok(['exists' => false], "Aucun contact n'existe avec cet email.");
        }

        // 🔒 Comme pour WooCommerce find_customer : uniquement l'existence +
        // prénom, jamais les coordonnées complètes d'un tiers non authentifié.
        $props = $contact['properties'] ?? [];
        return ToolResult::ok(
            ['exists' => true, 'firstname' => $props['firstname'] ?? null],
            'Un contact existe avec cet email.',
            identity: ['email' => $p['email']],
        );
    }

    private function updateContact(array $p, array $c, array $ctx): ToolResult
    {
        $contactId = $this->resolveContactIdFromContext($ctx, $c);
        if (!$contactId) {
            return ToolResult::fail('not_identified', "Identifiez-vous d'abord (email) avant de modifier votre fiche.");
        }

        try {
            $this->client($c)->patch("/crm/v3/objects/contacts/{$contactId}", [
                'properties' => array_filter([
                    'phone' => $p['phone'] ?? null, 'email' => $p['email'] ?? null,
                    'company' => $p['company'] ?? null, 'address' => $p['address'] ?? null,
                ]),
            ]);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['contact_id' => $contactId], 'Fiche contact mise à jour.');
    }

    private function searchContacts(array $p, array $c): ToolResult
    {
        try {
            $res = $this->client($c)->post('/crm/v3/objects/contacts/search', [
                'query' => $p['query'], 'limit' => 10,
                'properties' => ['firstname', 'lastname', 'email', 'phone', 'company'],
            ])->json();
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();

        $contacts = collect($res['results'] ?? [])->map(fn ($r) => ['contact_id' => $r['id'], ...$r['properties']])->all();
        if (empty($contacts)) return ToolResult::fail('not_found', 'Aucun contact trouvé.');
        return ToolResult::ok(['contacts' => $contacts], count($contacts) . ' contact(s) trouvé(s)');
    }

    private function getContact(array $p, array $c): ToolResult
    {
        try {
            $contact = $this->client($c)->get("/crm/v3/objects/contacts/{$p['contact_id']}", [
                'properties' => 'firstname,lastname,email,phone,company,address,lifecyclestage',
            ])->json();
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Contact introuvable.');
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['contact_id' => $contact['id'], ...$contact['properties']], 'Contact récupéré.');
    }

    // =====================================================================
    // 💰 DEALS
    // =====================================================================

    private function dealTools(): array
    {
        return [
            new ToolSchema('hubspot', 'create_deal',
                "Crée une nouvelle transaction (deal) HubSpot lorsqu'une nouvelle opportunité commerciale doit être enregistrée. Avant la création, vérifier si une transaction équivalente existe déjà afin d'éviter les doublons. Associer la transaction au contact ou à l'entreprise appropriés lorsque ces informations sont disponibles. Ne jamais créer une transaction à partir d'informations supposées.", [
                'type' => 'object', 'properties' => [
                    'name' => ['type' => 'string'], 'amount' => ['type' => 'number'],
                    'contact_email' => ['type' => 'string', 'description' => "Email du contact à associer à l'opportunité"],
                ], 'required' => ['name'],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'crm.create_opportunity'),

            new ToolSchema('hubspot', 'update_deal',
                "Met à jour une transaction HubSpot existante identifiée de manière unique. Modifier uniquement les propriétés explicitement demandées par l'utilisateur. Si la transaction n'est pas identifiée de manière unique, effectuer une recherche puis demander une clarification si nécessaire avant toute modification.", [
                'type' => 'object', 'properties' => [
                    'deal_id' => ['type' => 'string'], 'name' => ['type' => 'string'],
                    'amount' => ['type' => 'number'], 'pipeline' => ['type' => 'string'], 'stage' => ['type' => 'string'],
                ], 'required' => ['deal_id'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('hubspot', 'get_deal',
                "Récupère les informations détaillées d'une transaction HubSpot identifiée de manière unique. Utiliser lorsque l'utilisateur souhaite consulter son état, son montant, son pipeline, ses associations ou ses propriétés avant une autre action. Ne jamais inventer un identifiant de transaction.", [
                'type' => 'object', 'properties' => ['deal_id' => ['type' => 'string']], 'required' => ['deal_id'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('hubspot', 'search_deals',
                "Recherche des transactions HubSpot selon leur nom ou d'autres critères. Utiliser avant toute consultation, modification ou changement d'étape lorsqu'aucun identifiant n'est disponible. Si plusieurs transactions correspondent, demander une clarification.", [
                'type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('hubspot', 'close_deal',
                "Marque une transaction HubSpot comme gagnée ou perdue selon la demande explicite de l'utilisateur. Vérifier que la transaction est identifiée de manière unique avant toute modification. Si plusieurs transactions correspondent, demander une clarification. Ne jamais modifier l'état d'une transaction par supposition.", [
                'type' => 'object', 'properties' => [
                    'deal_id' => ['type' => 'string'], 'outcome' => ['type' => 'string', 'enum' => ['won', 'lost']],
                ], 'required' => ['deal_id', 'outcome'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    private function createDeal(array $p, array $c): ToolResult
    {
        $defaultStage = $this->defaultDealStage($c);

        try {
            $deal = $this->client($c)->post('/crm/v3/objects/deals', [
                'properties' => $this->cleanProperties([
                    'dealname' => $p['name'], 'amount' => $p['amount'] ?? null,
                    'dealstage' => $defaultStage,
                ]),
            ])->json();
        } catch (RequestException $e) {
            Log::error('MCP HubSpot create_deal a échoué', [
                'status' => $e->response?->status(), 'body' => $e->response?->body(), 'sent_stage' => $defaultStage,
            ]);
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();

        if (!empty($p['contact_email'])) {
            $contact = $this->searchContactByEmail($p['contact_email'], $c);
            if ($contact) {
                $this->associateDefault($c, 'deals', $deal['id'], 'contacts', $contact['id']);
            }
        }

        return ToolResult::ok(['deal_id' => $deal['id']], "Opportunité « {$p['name']} » créée.");
    }

    /**
     * 🆕 Résout la première étape du premier pipeline de deals du portail —
     * jamais laissé au LLM, qui ne peut pas connaître les identifiants réels
     * configurés par ce client HubSpot précis. Mis en cache 24h par portail.
     */
    private function defaultDealStage(array $c): string
    {
        $cacheKey = 'mcp:hubspot:default_deal_stage:' . md5($c['access_token']);

        return Cache::remember($cacheKey, now()->addDay(), function () use ($c) {
            try {
                $pipelines = $this->client($c)->get('/crm/v3/pipelines/deals')->json();
                $firstStage = $pipelines['results'][0]['stages'][0]['id'] ?? null;
                if ($firstStage) {
                    return $firstStage;
                }
            } catch (\Throwable $e) {
                Log::warning('MCP HubSpot: résolution du pipeline par défaut échouée', ['error' => $e->getMessage()]);
            }
            return 'appointmentscheduled'; // repli : identifiant standard HubSpot le plus courant
        });
    }

    private function updateDeal(array $p, array $c): ToolResult
    {
        try {
            $this->client($c)->patch("/crm/v3/objects/deals/{$p['deal_id']}", [
                'properties' => array_filter([
                    'dealname' => $p['name'] ?? null, 'amount' => $p['amount'] ?? null,
                    'pipeline' => $p['pipeline'] ?? null, 'dealstage' => $p['stage'] ?? null,
                ]),
            ]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Opportunité introuvable.');
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['deal_id' => $p['deal_id']], 'Opportunité mise à jour.');
    }

    private function getDeal(array $p, array $c): ToolResult
    {
        try {
            $deal = $this->client($c)->get("/crm/v3/objects/deals/{$p['deal_id']}", [
                'properties' => 'dealname,amount,pipeline,dealstage,closedate',
            ])->json();
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Opportunité introuvable.');
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['deal_id' => $deal['id'], ...$deal['properties']], 'Opportunité récupérée.');
    }

    private function searchDeals(array $p, array $c): ToolResult
    {
        try {
            $res = $this->client($c)->post('/crm/v3/objects/deals/search', [
                'query' => $p['query'], 'limit' => 10, 'properties' => ['dealname', 'amount', 'dealstage'],
            ])->json();
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        $deals = collect($res['results'] ?? [])->map(fn ($r) => ['deal_id' => $r['id'], ...$r['properties']])->all();
        if (empty($deals)) return ToolResult::fail('not_found', 'Aucune opportunité trouvée.');
        return ToolResult::ok(['deals' => $deals], count($deals) . ' opportunité(s) trouvée(s)');
    }

    private function closeDeal(array $p, array $c): ToolResult
    {
        try {
            $this->client($c)->patch("/crm/v3/objects/deals/{$p['deal_id']}", [
                'properties' => ['dealstage' => $p['outcome'] === 'won' ? 'closedwon' : 'closedlost'],
            ]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Opportunité introuvable.');
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['deal_id' => $p['deal_id'], 'outcome' => $p['outcome']], 'Opportunité clôturée.');
    }

    // =====================================================================
    // 📝 NOTES
    // =====================================================================

    private function noteTools(): array
    {
        return [
            new ToolSchema('hubspot', 'add_note',
                "Ajoute une note à un contact, une entreprise ou une transaction HubSpot existante. Utiliser uniquement pour enregistrer une nouvelle information sans modifier les autres propriétés de l'objet. Vérifier que l'objet est identifié de manière unique avant d'ajouter la note.", [
                'type' => 'object', 'properties' => [
                    'contact_email' => ['type' => 'string'], 'content' => ['type' => 'string'],
                ], 'required' => ['contact_email', 'content'],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'crm.log_activity'),

            new ToolSchema('hubspot', 'list_notes',
                "Retourne les notes associées à un contact, une entreprise ou une transaction HubSpot. Utiliser lorsque l'utilisateur souhaite consulter l'historique des échanges ou des informations enregistrées sans modifier les données existantes.", [
                'type' => 'object', 'properties' => ['contact_id' => ['type' => 'string']], 'required' => ['contact_id'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    private function addNote(array $p, array $c): ToolResult
    {
        $contact = $this->searchContactByEmail($p['contact_email'], $c);
        if (!$contact) return ToolResult::fail('not_found', 'Contact introuvable pour cet email.');

        try {
            $note = $this->client($c)->post('/crm/v3/objects/notes', [
                'properties' => ['hs_note_body' => $p['content'], 'hs_timestamp' => now()->valueOf()],
            ])->json();
            $this->associateDefault($c, 'notes', $note['id'], 'contacts', $contact['id']);
        } catch (RequestException $e) {
            Log::error('MCP HubSpot add_note a échoué', ['status' => $e->response?->status(), 'body' => $e->response?->body()]);
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['note_id' => $note['id']], 'Note ajoutée au dossier.');
    }

    private function listNotes(array $p, array $c): ToolResult
    {
        try {
            $assoc = $this->client($c)->get("/crm/v3/objects/contacts/{$p['contact_id']}/associations/notes")->json();
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();

        $ids = collect($assoc['results'] ?? [])->pluck('id')->take(10);
        if ($ids->isEmpty()) return ToolResult::fail('not_found', 'Aucune note pour ce contact.');

        $notes = $ids->map(function ($id) use ($c) {
            try {
                $n = $this->client($c)->get("/crm/v3/objects/notes/{$id}", ['properties' => 'hs_note_body,hs_timestamp'])->json();
                return ['note_id' => $id, 'content' => $n['properties']['hs_note_body'] ?? null, 'date' => $n['properties']['hs_timestamp'] ?? null];
            } catch (RequestException) {
                return null;
            }
        })->filter()->values()->all();

        return ToolResult::ok(['notes' => $notes], count($notes) . ' note(s)');
    }

    // =====================================================================
    // ✅ TÂCHES
    // =====================================================================

    private function taskTools(): array
    {
        return [
            new ToolSchema('hubspot', 'create_task',
                "Crée une tâche HubSpot associée à un contact, une entreprise ou une transaction afin de planifier une action future. Utiliser uniquement lorsqu'une nouvelle tâche doit être créée. Vérifier que les objets associés sont correctement identifiés avant l'appel. Ne pas utiliser pour modifier une tâche existante.", [
                'type' => 'object', 'properties' => [
                    'title' => ['type' => 'string'], 'due_date' => ['type' => 'string', 'description' => 'ISO 8601'],
                    'contact_email' => ['type' => 'string'], 'notes' => ['type' => 'string'],
                ], 'required' => ['title'],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'crm.create_task'),

            new ToolSchema('hubspot', 'update_task',
                "Met à jour une tâche HubSpot existante identifiée de manière unique. Modifier uniquement les propriétés explicitement demandées par l'utilisateur. Si l'identifiant est inconnu, rechercher d'abord la tâche correspondante. En cas de plusieurs résultats, demander une clarification avant toute modification. Ne jamais créer une nouvelle tâche à la place d'une mise à jour.", [
                'type' => 'object', 'properties' => [
                    'task_id' => ['type' => 'string'], 'title' => ['type' => 'string'], 'due_date' => ['type' => 'string'],
                ], 'required' => ['task_id'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('hubspot', 'complete_task',
                "Marque une tâche HubSpot comme terminée. Utiliser uniquement lorsque l'utilisateur souhaite clôturer une tâche existante. Si la tâche n'est pas identifiée de manière unique, rechercher la tâche puis demander une clarification si nécessaire. Ne jamais terminer plusieurs tâches sans confirmation explicite.", [
                'type' => 'object', 'properties' => ['task_id' => ['type' => 'string']], 'required' => ['task_id'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('hubspot', 'list_tasks',
                "Retourne la liste des tâches HubSpot selon les critères demandés (propriétaire, statut, échéance ou autres filtres disponibles). Utiliser lorsque l'utilisateur souhaite consulter ses tâches ou obtenir un aperçu de son travail. Pour retrouver une tâche précise avant une modification, privilégier search_tasks si disponible.", [
                'type' => 'object', 'properties' => ['contact_id' => ['type' => 'string']],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    private function createTask(array $p, array $c): ToolResult
    {
        try {
            $task = $this->client($c)->post('/crm/v3/objects/tasks', [
                'properties' => array_filter([
                    'hs_task_subject' => $p['title'], 'hs_task_body' => $p['notes'] ?? null,
                    'hs_timestamp' => !empty($p['due_date']) ? \Illuminate\Support\Carbon::parse($p['due_date'])->valueOf() : now()->valueOf(),
                    'hs_task_status' => 'NOT_STARTED',
                ]),
            ])->json();
        } catch (RequestException $e) {
            Log::error('MCP HubSpot create_task a échoué', ['status' => $e->response?->status(), 'body' => $e->response?->body()]);
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();

        if (!empty($p['contact_email'])) {
            $contact = $this->searchContactByEmail($p['contact_email'], $c);
            if ($contact) $this->associateDefault($c, 'tasks', $task['id'], 'contacts', $contact['id']);
        }

        return ToolResult::ok(['task_id' => $task['id']], "Tâche « {$p['title']} » créée.");
    }

    private function updateTask(array $p, array $c): ToolResult
    {
        try {
            $this->client($c)->patch("/crm/v3/objects/tasks/{$p['task_id']}", [
                'properties' => array_filter([
                    'hs_task_subject' => $p['title'] ?? null,
                    'hs_timestamp' => !empty($p['due_date']) ? \Illuminate\Support\Carbon::parse($p['due_date'])->valueOf() : null,
                ]),
            ]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Tâche introuvable.');
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['task_id' => $p['task_id']], 'Tâche mise à jour.');
    }

    private function completeTask(array $p, array $c): ToolResult
    {
        try {
            $this->client($c)->patch("/crm/v3/objects/tasks/{$p['task_id']}", ['properties' => ['hs_task_status' => 'COMPLETED']]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Tâche introuvable.');
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['task_id' => $p['task_id']], 'Tâche marquée comme terminée.');
    }

    private function listTasks(array $p, array $c): ToolResult
    {
        try {
            if (!empty($p['contact_id'])) {
                $assoc = $this->client($c)->get("/crm/v3/objects/contacts/{$p['contact_id']}/associations/tasks")->json();
                $ids = collect($assoc['results'] ?? [])->pluck('id')->take(10);
                $tasks = $ids->map(fn ($id) => $this->client($c)->get("/crm/v3/objects/tasks/{$id}", ['properties' => 'hs_task_subject,hs_task_status,hs_timestamp'])->json())
                    ->map(fn ($t) => ['task_id' => $t['id'], ...$t['properties']])->all();
            } else {
                $res = $this->client($c)->get('/crm/v3/objects/tasks', ['limit' => 10, 'properties' => 'hs_task_subject,hs_task_status,hs_timestamp'])->json();
                $tasks = collect($res['results'] ?? [])->map(fn ($t) => ['task_id' => $t['id'], ...$t['properties']])->all();
            }
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        if (empty($tasks)) return ToolResult::fail('not_found', 'Aucune tâche trouvée.');
        return ToolResult::ok(['tasks' => $tasks], count($tasks) . ' tâche(s)');
    }

    // =====================================================================
    // 📅 MEETINGS (si Google Calendar n'est pas connecté sur ce site)
    // =====================================================================

    private function meetingTools(): array
    {
        return [
            new ToolSchema('hubspot', 'create_meeting',
                "Crée une réunion HubSpot associée aux objets concernés (contact, entreprise ou transaction). Utiliser uniquement lorsque la date, l'heure et les informations essentielles sont connues. Si les participants ou l'horaire sont incomplets, demander les informations manquantes avant la création.", [
                'type' => 'object', 'properties' => [
                    'title' => ['type' => 'string'], 'start' => ['type' => 'string', 'description' => 'ISO 8601'],
                    'end' => ['type' => 'string', 'description' => 'ISO 8601'], 'contact_email' => ['type' => 'string'],
                ], 'required' => ['title', 'start', 'end', 'contact_email'],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'scheduling.create_event'),

            new ToolSchema('hubspot', 'cancel_meeting',
                "Annule une réunion HubSpot existante identifiée de manière unique. Si la réunion ne peut pas être identifiée de manière certaine, effectuer une recherche puis demander une clarification avant de l'annuler.", [
                'type' => 'object', 'properties' => ['meeting_id' => ['type' => 'string']], 'required' => ['meeting_id'],
            ], isWriteAction: true, defaultMode: 'confirm', defaultConfirmActor: 'visitor', capability: 'scheduling.cancel_event'),

            new ToolSchema('hubspot', 'search_meetings',
                "Recherche des réunions HubSpot selon leur sujet, leur période ou leurs associations. Utiliser avant toute consultation, modification ou annulation lorsqu'aucun identifiant de réunion n'est disponible.", [
                'type' => 'object', 'properties' => ['contact_id' => ['type' => 'string']],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    private function createMeeting(array $p, array $c): ToolResult
    {
        $contact = $this->searchContactByEmail($p['contact_email'], $c);

        try {
            $meeting = $this->client($c)->post('/crm/v3/objects/meetings', [
                'properties' => array_filter([
                    'hs_meeting_title' => $p['title'],
                    'hs_meeting_start_time' => \Illuminate\Support\Carbon::parse($p['start'])->valueOf(),
                    'hs_meeting_end_time' => \Illuminate\Support\Carbon::parse($p['end'])->valueOf(),
                ]),
            ])->json();
        } catch (RequestException $e) {
            Log::error('MCP HubSpot create_meeting a échoué', ['status' => $e->response?->status(), 'body' => $e->response?->body()]);
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();

        if ($contact) $this->associateDefault($c, 'meetings', $meeting['id'], 'contacts', $contact['id']);

        return ToolResult::ok(
            ['meeting_id' => $meeting['id']],
            "Rendez-vous « {$p['title']} » planifié.",
            identity: ['email' => $p['contact_email']],
        );
    }

    private function cancelMeeting(array $p, array $c): ToolResult
    {
        try {
            $this->client($c)->delete("/crm/v3/objects/meetings/{$p['meeting_id']}");
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Rendez-vous introuvable.');
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['meeting_id' => $p['meeting_id']], 'Rendez-vous annulé.');
    }

    private function searchMeetings(array $p, array $c): ToolResult
    {
        try {
            if (!empty($p['contact_id'])) {
                $assoc = $this->client($c)->get("/crm/v3/objects/contacts/{$p['contact_id']}/associations/meetings")->json();
                $ids = collect($assoc['results'] ?? [])->pluck('id')->take(10);
                $meetings = $ids->map(fn ($id) => $this->client($c)->get("/crm/v3/objects/meetings/{$id}", ['properties' => 'hs_meeting_title,hs_meeting_start_time'])->json())
                    ->map(fn ($m) => ['meeting_id' => $m['id'], ...$m['properties']])->all();
            } else {
                $res = $this->client($c)->get('/crm/v3/objects/meetings', ['limit' => 10, 'properties' => 'hs_meeting_title,hs_meeting_start_time'])->json();
                $meetings = collect($res['results'] ?? [])->map(fn ($m) => ['meeting_id' => $m['id'], ...$m['properties']])->all();
            }
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        if (empty($meetings)) return ToolResult::fail('not_found', 'Aucun rendez-vous trouvé.');
        return ToolResult::ok(['meetings' => $meetings], count($meetings) . ' rendez-vous');
    }

    // =====================================================================
    // 📧 EMAILS / 📞 APPELS
    // =====================================================================

    private function emailTools(): array
    {
        return [
            new ToolSchema('hubspot', 'log_email',
                "Enregistre un e-mail comme activité HubSpot liée à un contact, une entreprise ou une transaction. Utiliser uniquement pour consigner un échange déjà réalisé. Ne pas utiliser pour envoyer un nouvel e-mail.", [
                'type' => 'object', 'properties' => [
                    'contact_email' => ['type' => 'string'], 'subject' => ['type' => 'string'], 'body' => ['type' => 'string'],
                ], 'required' => ['contact_email', 'subject'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto', capability: 'crm.log_activity'),

            new ToolSchema('hubspot', 'send_email',
                "Envoie un nouvel e-mail aux destinataires indiqués et enregistre l'envoi dans HubSpot si cette fonctionnalité est prise en charge. Vérifier que les destinataires, l'objet et le contenu sont disponibles avant l'envoi. Ne jamais inventer une adresse e-mail ni envoyer un message incomplet.", [
                'type' => 'object', 'properties' => [
                    'contact_email' => ['type' => 'string'], 'template_id' => ['type' => 'string'],
                ], 'required' => ['contact_email', 'template_id'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'confirm', defaultConfirmActor: 'admin', capability: 'communication.send_email'),
        ];
    }

    private function logEmail(array $p, array $c): ToolResult
    {
        $contact = $this->searchContactByEmail($p['contact_email'], $c);
        if (!$contact) return ToolResult::fail('not_found', 'Contact introuvable pour cet email.');

        try {
            $email = $this->client($c)->post('/crm/v3/objects/emails', [
                'properties' => array_filter([
                    'hs_email_subject' => $p['subject'], 'hs_email_text' => $p['body'] ?? null,
                    'hs_timestamp' => now()->valueOf(), 'hs_email_direction' => 'EMAIL',
                ]),
            ])->json();
            $this->associateDefault($c, 'emails', $email['id'], 'contacts', $contact['id']);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['email_id' => $email['id']], 'Email enregistré dans l\'historique.');
    }

    private function sendEmail(array $p, array $c): ToolResult
    {
        try {
            $response = $this->client($c)->post('/marketing/v3/transactional/single-email/send', [
                'emailId' => $p['template_id'],
                'message' => ['to' => $p['contact_email']],
            ]);
        } catch (RequestException $e) {
            Log::warning('MCP HubSpot send_email a échoué', ['status' => $e->response?->status(), 'body' => $e->response?->body()]);
            return ToolResult::fail('send_failed', "L'envoi d'email requiert que l'envoi transactionnel HubSpot soit configuré (Marketing Hub).");
        }
        $this->recordSuccess();
        return ToolResult::ok(['status' => $response->json('sendResult')], "Email envoyé à {$p['contact_email']}.");
    }

    private function callTools(): array
    {
        return [
            new ToolSchema('hubspot', 'log_call',
                "Enregistre un appel téléphonique comme activité HubSpot associée aux objets concernés. Utiliser uniquement pour consigner un appel déjà effectué. Ne pas utiliser pour planifier ou lancer un appel.", [
                'type' => 'object', 'properties' => [
                    'contact_email' => ['type' => 'string'], 'summary' => ['type' => 'string'],
                    'duration_minutes' => ['type' => 'integer'], 'outcome' => ['type' => 'string'],
                ], 'required' => ['contact_email', 'summary'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto', capability: 'crm.log_activity'),
        ];
    }

    private function logCall(array $p, array $c): ToolResult
    {
        $contact = $this->searchContactByEmail($p['contact_email'], $c);
        if (!$contact) return ToolResult::fail('not_found', 'Contact introuvable pour cet email.');

        try {
            $call = $this->client($c)->post('/crm/v3/objects/calls', [
                'properties' => array_filter([
                    'hs_call_body' => $p['summary'], 'hs_call_duration' => isset($p['duration_minutes']) ? $p['duration_minutes'] * 60000 : null,
                    'hs_timestamp' => now()->valueOf(), 'hs_call_disposition' => $p['outcome'] ?? null,
                ]),
            ])->json();
            $this->associateDefault($c, 'calls', $call['id'], 'contacts', $contact['id']);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['call_id' => $call['id']], 'Appel enregistré dans l\'historique.');
    }

    // =====================================================================
    // 🎫 TICKETS
    // =====================================================================

    private function ticketTools(): array
    {
        return [
            new ToolSchema('hubspot', 'create_ticket',
                "Crée un nouveau ticket HubSpot lorsqu'une nouvelle demande de support ou un nouvel incident doit être enregistré. Vérifier qu'un ticket équivalent n'existe pas déjà afin d'éviter les doublons. Associer le ticket aux objets concernés lorsque ces informations sont disponibles.", [
                'type' => 'object', 'properties' => [
                    'subject' => ['type' => 'string'], 'description' => ['type' => 'string'],
                    'contact_email' => ['type' => 'string'], 'priority' => ['type' => 'string', 'enum' => ['LOW', 'MEDIUM', 'HIGH']],
                ], 'required' => ['subject', 'description', 'contact_email'],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'support.create_ticket'),

            new ToolSchema('hubspot', 'update_ticket',
                "Met à jour un ticket HubSpot existant identifié de manière unique. Modifier uniquement les propriétés explicitement demandées. Si le ticket n'est pas identifié de manière certaine, effectuer une recherche puis demander une clarification avant la modification.", [
                'type' => 'object', 'properties' => [
                    'ticket_id' => ['type' => 'string'], 'description' => ['type' => 'string'],
                    'priority' => ['type' => 'string', 'enum' => ['LOW', 'MEDIUM', 'HIGH']],
                ], 'required' => ['ticket_id'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('hubspot', 'get_ticket',
                "Récupère les informations détaillées d'un ticket HubSpot identifié de manière unique. Utiliser lorsque l'utilisateur souhaite consulter son état, sa priorité, son propriétaire ou ses propriétés avant une autre action.", [
                'type' => 'object', 'properties' => ['ticket_id' => ['type' => 'string']], 'required' => ['ticket_id'],
            ], defaultMode: 'auto'),

            new ToolSchema('hubspot', 'search_tickets',
                "Recherche des tickets HubSpot selon leur titre, leur identifiant ou d'autres critères disponibles. Utiliser avant toute consultation, modification ou clôture lorsqu'aucun identifiant n'est connu. Si plusieurs tickets correspondent, demander une clarification avant de poursuivre.", [
                'type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('hubspot', 'close_ticket',
                "Clôture un ticket HubSpot existant. Utiliser uniquement lorsque l'utilisateur demande explicitement de résoudre ou fermer un ticket. Vérifier que le ticket est identifié de manière unique avant toute modification.", [
                'type' => 'object', 'properties' => ['ticket_id' => ['type' => 'string']], 'required' => ['ticket_id'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    private function createTicket(array $p, array $c, array $ctx): ToolResult
    {
        $contact = $this->searchContactByEmail($p['contact_email'], $c);

        try {
            $ticket = $this->client($c)->post('/crm/v3/objects/tickets', [
                'properties' => $this->cleanProperties([
                    'subject' => $p['subject'], 'content' => $p['description'],
                    'hs_pipeline_stage' => '1', 'hs_ticket_priority' => $p['priority'] ?? 'MEDIUM',
                ]),
            ])->json();
        } catch (RequestException $e) {
            Log::error('MCP HubSpot create_ticket a échoué', [
                'status' => $e->response?->status(),
                'body' => $e->response?->body(), // 🆕 corps complet, non tronqué
            ]);
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();

        if ($contact) $this->associateDefault($c, 'tickets', $ticket['id'], 'contacts', $contact['id']);

        return ToolResult::ok(
            ['ticket_id' => $ticket['id']],
            "Ticket « {$p['subject']} » créé, référence #{$ticket['id']}.",
            identity: ['email' => $p['contact_email']],
        );
    }

    private function updateTicket(array $p, array $c): ToolResult
    {
        try {
            $this->client($c)->patch("/crm/v3/objects/tickets/{$p['ticket_id']}", [
                'properties' => array_filter(['content' => $p['description'] ?? null, 'hs_ticket_priority' => $p['priority'] ?? null]),
            ]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Ticket introuvable.');
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['ticket_id' => $p['ticket_id']], 'Ticket mis à jour.');
    }

    private function getTicket(array $p, array $c): ToolResult
    {
        try {
            $ticket = $this->client($c)->get("/crm/v3/objects/tickets/{$p['ticket_id']}", [
                'properties' => 'subject,content,hs_pipeline_stage,hs_ticket_priority',
            ])->json();
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Ticket introuvable.');
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['ticket_id' => $ticket['id'], ...$ticket['properties']], 'Ticket récupéré.');
    }

    private function searchTickets(array $p, array $c): ToolResult
    {
        try {
            $res = $this->client($c)->post('/crm/v3/objects/tickets/search', [
                'query' => $p['query'], 'limit' => 10, 'properties' => ['subject', 'hs_pipeline_stage', 'hs_ticket_priority'],
            ])->json();
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        $tickets = collect($res['results'] ?? [])->map(fn ($r) => ['ticket_id' => $r['id'], ...$r['properties']])->all();
        if (empty($tickets)) return ToolResult::fail('not_found', 'Aucun ticket trouvé.');
        return ToolResult::ok(['tickets' => $tickets], count($tickets) . ' ticket(s) trouvé(s)');
    }

    private function closeTicket(array $p, array $c): ToolResult
    {
        try {
            $this->client($c)->patch("/crm/v3/objects/tickets/{$p['ticket_id']}", ['properties' => ['hs_pipeline_stage' => 'closed']]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Ticket introuvable.');
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['ticket_id' => $p['ticket_id']], 'Ticket clôturé.');
    }

    // =====================================================================
    // 📎 PIÈCES JOINTES
    // =====================================================================

    private function fileTools(): array
    {
        return [
            new ToolSchema('hubspot', 'upload_file',
                "Téléverse un nouveau fichier dans HubSpot afin qu'il puisse être associé à des contacts, entreprises, transactions ou tickets. Utiliser uniquement lorsqu'un nouveau fichier doit être importé. Ne pas utiliser pour remplacer ou modifier un fichier existant.", [
                'type' => 'object', 'properties' => ['file_url' => ['type' => 'string'], 'file_name' => ['type' => 'string']], 'required' => ['file_url'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('hubspot', 'attach_file_to_contact',
                "Associe un fichier existant à un contact HubSpot identifié de manière unique. Vérifier que le fichier et le contact sont tous deux correctement identifiés avant de créer l'association. Ne jamais créer de liaison par supposition.", [
                'type' => 'object', 'properties' => ['file_id' => ['type' => 'string'], 'contact_email' => ['type' => 'string']], 'required' => ['file_id', 'contact_email'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    private function uploadFile(array $p, array $c): ToolResult
    {
        // 🆕 Volontairement simplifié : ELChat n'a pas vocation à transférer
        // du binaire depuis le chat vers HubSpot. Si le fichier est déjà
        // accessible par URL (ex: pièce jointe déjà uploadée côté ELChat),
        // on se contente d'en garder la référence texte — l'upload binaire
        // réel HubSpot (multipart) est hors scope de ce connecteur pour l'instant.
        return ToolResult::fail('not_implemented', "L'upload direct n'est pas encore supporté : fournissez plutôt l'URL du fichier dans une note (add_note).");
    }

    private function attachFileToContact(array $p, array $c): ToolResult
    {
        return ToolResult::fail('not_implemented', "Fonctionnalité non encore disponible — voir add_note en attendant.");
    }

    // =====================================================================
    // 🤖 IA
    // =====================================================================

    private function aiTools(): array
    {
        return [
            new ToolSchema('hubspot', 'qualify_lead',
                "Met à jour le statut de qualification d'un prospect selon les informations fournies par l'utilisateur ou les règles métier. Utiliser uniquement lorsque l'objectif est de qualifier ou requalifier un prospect existant. Ne jamais qualifier automatiquement un prospect sans instruction explicite ou règle définie.", [
                'type' => 'object', 'properties' => [
                    'contact_email' => ['type' => 'string'], 'temperature' => ['type' => 'string', 'enum' => ['chaud', 'tiède', 'froid']],
                ], 'required' => ['contact_email', 'temperature'],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'crm.qualify_lead'),

            new ToolSchema('hubspot', 'score_lead',
                "Calcule ou met à jour le score d'un prospect selon les critères configurés dans HubSpot. Utiliser uniquement lorsque l'utilisateur souhaite obtenir ou recalculer le score d'un prospect existant. Ne jamais inventer un score ni modifier les règles de notation.", [
                'type' => 'object', 'properties' => [
                    'contact_email' => ['type' => 'string'], 'score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                ], 'required' => ['contact_email', 'score'],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'crm.qualify_lead'),

            new ToolSchema('hubspot', 'assign_owner',
                "Attribue un propriétaire (commercial ou responsable) à un contact, une entreprise, une transaction ou un ticket HubSpot. Vérifier que l'objet et le propriétaire sont identifiés de manière unique avant l'attribution. Si plusieurs propriétaires correspondent, demander une clarification.", [
                'type' => 'object', 'properties' => [
                    'entity_type' => ['type' => 'string', 'enum' => ['contact', 'deal']], 'entity_id' => ['type' => 'string'],
                    'owner_email' => ['type' => 'string', 'description' => 'Optionnel : email du commercial à assigner explicitement'],
                ], 'required' => ['entity_type', 'entity_id'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('hubspot', 'summarize_contact',
                "Génère un résumé fidèle d'un contact HubSpot à partir des informations réellement enregistrées (propriétés, activités, notes, tickets, transactions et interactions disponibles). Ne jamais inventer de faits, compléter les informations manquantes ou déduire des éléments non présents dans les données retournées. Indiquer clairement lorsqu'une information n'est pas disponible.", [
                'type' => 'object', 'properties' => ['contact_id' => ['type' => 'string']], 'required' => ['contact_id'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    private function qualifyLead(array $p, array $c): ToolResult
    {
        $contact = $this->searchContactByEmail($p['contact_email'], $c);
        if (!$contact) return ToolResult::fail('not_found', 'Contact introuvable pour cet email.');

        // 🆕 Propriété personnalisée ELChat — 'hs_lead_status' natif de
        // HubSpot n'accepte que des valeurs prédéfinies par le portail ;
        // on utilise une propriété dédiée pour rester compatible partout.
        try {
            $this->client($c)->patch("/crm/v3/objects/contacts/{$contact['id']}", [
                'properties' => ['elchat_lead_temperature' => $p['temperature']],
            ]);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['contact_id' => $contact['id'], 'temperature' => $p['temperature']], "Prospect qualifié : {$p['temperature']}.");
    }

    private function scoreLead(array $p, array $c): ToolResult
    {
        $contact = $this->searchContactByEmail($p['contact_email'], $c);
        if (!$contact) return ToolResult::fail('not_found', 'Contact introuvable pour cet email.');

        try {
            $this->client($c)->patch("/crm/v3/objects/contacts/{$contact['id']}", [
                'properties' => ['elchat_lead_score' => (string) max(0, min(100, (int) $p['score']))],
            ]);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['contact_id' => $contact['id'], 'score' => $p['score']], "Score attribué : {$p['score']}/100.");
    }

    private function assignOwner(array $p, array $c, array $ctx): ToolResult
    {
        $ownerId = null;

        if (!empty($p['owner_email'])) {
            try {
                $owners = $this->client($c)->get('/crm/v3/owners', ['email' => $p['owner_email']])->json();
                $ownerId = $owners['results'][0]['id'] ?? null;
            } catch (RequestException $e) {
                throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
            }
            if (!$ownerId) return ToolResult::fail('not_found', "Aucun commercial trouvé avec l'email {$p['owner_email']}.");
        } else {
            // 🆕 Répartition équitable automatique (round-robin) entre les
            // commerciaux actifs, si aucun n'est explicitement demandé.
            try {
                $owners = $this->client($c)->get('/crm/v3/owners', ['limit' => 100])->json();
            } catch (RequestException $e) {
                throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
            }
            $activeOwners = collect($owners['results'] ?? [])->reject(fn ($o) => $o['archived'] ?? false)->values();
            if ($activeOwners->isEmpty()) return ToolResult::fail('not_found', 'Aucun commercial disponible dans ce portail HubSpot.');

            $key = 'mcp:hubspot:owner_round_robin:' . md5($c['access_token']);
            $index = Cache::get($key, 0) % $activeOwners->count();
            Cache::put($key, $index + 1, now()->addDays(30));
            $ownerId = $activeOwners[$index]['id'];
        }

        $objectType = $p['entity_type'] === 'deal' ? 'deals' : 'contacts';
        try {
            $this->client($c)->patch("/crm/v3/objects/{$objectType}/{$p['entity_id']}", ['properties' => ['hubspot_owner_id' => $ownerId]]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Fiche introuvable.');
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['entity_id' => $p['entity_id'], 'owner_id' => $ownerId], 'Commercial assigné.');
    }

    private function summarizeContact(array $p, array $c): ToolResult
    {
        try {
            $contact = $this->client($c)->get("/crm/v3/objects/contacts/{$p['contact_id']}", ['properties' => 'firstname,lastname,email,company,lifecyclestage'])->json();
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Contact introuvable.');
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();

        $counts = [];
        foreach (['deals', 'tickets', 'notes', 'tasks'] as $type) {
            try {
                $assoc = $this->client($c)->get("/crm/v3/objects/contacts/{$p['contact_id']}/associations/{$type}")->json();
                $counts[$type] = count($assoc['results'] ?? []);
            } catch (RequestException) {
                $counts[$type] = 0;
            }
        }

        return ToolResult::ok([
            'contact' => $contact['properties'], 'activity_counts' => $counts,
        ], 'Historique du contact récupéré — synthétise-le pour le conseiller.');
    }

    // =====================================================================
    // Utilitaires
    // =====================================================================

    private function searchContactByEmail(string $email, array $c): ?array
    {
        try {
            $res = $this->client($c)->post('/crm/v3/objects/contacts/search', [
                'filterGroups' => [['filters' => [['propertyName' => 'email', 'operator' => 'EQ', 'value' => $email]]]],
                'properties' => ['firstname', 'lastname', 'email'],
                'limit' => 1,
            ])->json();
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('HubSpot indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return $res['results'][0] ?? null;
    }

    private function resolveContactIdFromContext(array $ctx, array $c): ?string
    {
        // Pas de mapping local dédié pour HubSpot (contrairement à
        // mcp_customer_links pour WooCommerce) : on retrouve le contact via
        // l'email connu du visiteur identifié dans cette conversation, si
        // VisitorIdentityService l'a déjà résolu en amont.
        if (empty($ctx['owner_type']) || $ctx['owner_type'] !== 'user') {
            return null;
        }
        $user = \App\Models\User::find($ctx['owner_id']);
        if (!$user || !$user->email) {
            return null;
        }
        $contact = $this->searchContactByEmail($user->email, $c);
        return $contact['id'] ?? null;
    }

    /**
     * Associe deux objets HubSpot via leur association PAR DÉFAUT, sans
     * avoir à connaître/deviner l'ID numérique du type d'association
     * (source d'erreurs si codé en dur) — HubSpot le résout lui-même.
     */
    private function associateDefault(array $c, string $fromType, string $fromId, string $toType, string $toId): void
    {
        try {
            $this->client($c)->put("/crm/v3/objects/{$fromType}/{$fromId}/associations/default/{$toType}/{$toId}");
        } catch (RequestException $e) {
            Log::warning("MCP HubSpot: association {$fromType}->{$toType} échouée", ['error' => $e->getMessage()]);
        }
    }

    private function client(array $credentials)
    {
        return $this->http(self::API_BASE)->withToken($credentials['access_token']);
    }

    /**
     * 🆕 Filtre plus strict que array_filter() par défaut : élimine aussi les
     * chaînes composées uniquement d'espaces, pas seulement les chaînes
     * strictement vides. HubSpot rejette ces deux cas côté serveur avec
     * "value must be non-empty and non-blank", mais PHP ne les traite pas de
     * la même façon — d'où ce filtre dédié pour rester aligné sur la
     * validation réelle de l'API.
     */
    private function cleanProperties(array $properties): array
    {
        return array_filter($properties, fn ($v) => $v !== null && trim((string) $v) !== '');
    }
}
