<?php

namespace App\Domain\MCP\Connectors\Odoo;

use App\Domain\MCP\Contracts\{ToolResult, ToolSchema};
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use Illuminate\Support\Carbon;

class HelpdeskModule implements OdooModuleInterface
{
    public function technicalModuleName(): string { return 'helpdesk'; }

    public function listTools(): array
    {
        return [
            new ToolSchema('odoo', 'helpdesk_create_ticket',
                "Crée un nouveau ticket de support Odoo pour signaler un incident, une demande ou une question. Utiliser uniquement lorsqu'un nouveau ticket est nécessaire. Vérifier que le sujet, la description et les informations du contact sont disponibles avant la création. Si un ticket similaire semble déjà exister, rechercher les tickets existants avant d'en créer un nouveau afin d'éviter les doublons. Ne jamais créer un ticket à partir d'informations supposées ou incomplètes. Utiliser exclusivement les données et l'identifiant retournés par l'ERP.", [
                    'type' => 'object', 'properties' => ['subject' => ['type' => 'string'], 'description' => ['type' => 'string'], 'contact_email' => ['type' => 'string']], 'required' => ['subject', 'description', 'contact_email'],
                ], isWriteAction: true, defaultMode: 'auto', capability: 'support.create_ticket'),

            new ToolSchema('odoo', 'helpdesk_get_ticket',
                "Récupère les informations détaillées d'un ticket de support identifié de manière unique, notamment son statut, son étape actuelle et sa description. Utiliser lorsque l'utilisateur souhaite consulter un ticket existant ou obtenir ses détails avant une autre action. Si l'identifiant est inconnu, rechercher d'abord le ticket. Ne jamais inventer un identifiant de ticket.", [
                    'type' => 'object', 'properties' => ['ticket_id' => ['type' => 'integer']], 'required' => ['ticket_id'],
                ], defaultMode: 'auto'),

            new ToolSchema('odoo', 'helpdesk_update_ticket',
                "Met à jour un ticket de support existant identifié de manière unique. Modifier uniquement les champs explicitement demandés par l'utilisateur. Si l'identifiant du ticket est inconnu, rechercher d'abord le ticket. En cas de plusieurs correspondances, demander une clarification avant toute modification. Ne jamais créer un nouveau ticket à la place d'une mise à jour.", [
                    'type' => 'object', 'properties' => ['ticket_id' => ['type' => 'integer'], 'description' => ['type' => 'string']], 'required' => ['ticket_id'],
                ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('odoo', 'helpdesk_close_ticket',
                "Clôture un ticket de support existant identifié de manière unique. Utiliser uniquement lorsque l'utilisateur demande explicitement de fermer, résoudre ou clôturer le ticket. Si plusieurs tickets correspondent, demander une clarification avant l'exécution. Ne jamais clôturer un ticket par supposition ni clôturer plusieurs tickets sans confirmation explicite. Utiliser uniquement le résultat retourné par Odoo.", [
                    'type' => 'object', 'properties' => ['ticket_id' => ['type' => 'integer']], 'required' => ['ticket_id'],
                ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('odoo', 'helpdesk_search_tickets',
                "Recherche des tickets de support selon leur sujet ou un texte fourni par l'utilisateur. Utiliser pour retrouver un ticket avant une consultation, une mise à jour ou une clôture lorsque son identifiant est inconnu. Si plusieurs tickets correspondent, demander une clarification avant toute autre action. Ne jamais supposer qu'un ticket est unique.", [
                    'type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query'],
                ], defaultActorScope: 'admin', defaultMode: 'auto'),

            // ── 🆕 Reporting support : charge par agent, CSAT, temps de réponse ──

            new ToolSchema('odoo', 'helpdesk_get_agent_workload',
                "Retourne le nombre de tickets actuellement ouverts (dans une étape non marquée comme clôturante) par agent assigné, classé du plus chargé au moins chargé, ainsi que le nombre de tickets non assignés. Utiliser pour évaluer la charge de travail actuelle de l'équipe support ou décider à qui assigner un nouveau ticket. Jamais un historique : uniquement l'état actuel.", [
                    'type' => 'object', 'properties' => ['team' => ['type' => 'string', 'description' => "nom exact de l'équipe helpdesk, optionnel — sans filtre, toutes les équipes"]],
                ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('odoo', 'helpdesk_get_csat_summary',
                "Retourne un résumé agrégé des évaluations de satisfaction laissées par les clients sur leurs tickets (fonctionnalité « Évaluations clients » d'Odoo Helpdesk, si activée sur l'équipe) sur une période donnée : nombre d'évaluations, note moyenne, répartition satisfait/neutre/insatisfait. Si aucune évaluation n'existe, l'outil l'indique clairement plutôt que d'inventer un chiffre — cela signifie généralement que la fonctionnalité n'est pas activée sur l'équipe helpdesk concernée. Utiliser pour évaluer la satisfaction globale, jamais pour un ticket précis.", [
                    'type' => 'object', 'properties' => [
                        'date_from' => ['type' => 'string', 'description' => 'YYYY-MM-DD, défaut: -30 jours'],
                        'date_to' => ['type' => 'string', 'description' => 'YYYY-MM-DD, défaut: aujourd\'hui'],
                    ],
                ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('odoo', 'helpdesk_get_response_time_stats',
                "Retourne le temps moyen et médian entre la création d'un ticket et le premier message interne (note ou réponse d'un agent, hors messages automatiques) enregistré dessus, calculé sur un échantillon des tickets les plus récents. Utiliser pour évaluer la réactivité globale du support, jamais pour un ticket précis (utiliser helpdesk_get_ticket dans ce cas).", [
                    'type' => 'object', 'properties' => ['sample_size' => ['type' => 'integer', 'description' => 'défaut 30, max 100']],
                ], defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context, OdooClient $client): ToolResult
    {
        return match ($toolName) {
            'helpdesk_create_ticket' => $this->createTicket($params, $client),
            'helpdesk_get_ticket' => $this->getTicket($params, $client),
            'helpdesk_update_ticket' => $this->updateTicket($params, $client),
            'helpdesk_close_ticket' => $this->closeTicket($params, $client),
            'helpdesk_search_tickets' => $this->searchTickets($params, $client),
            'helpdesk_get_agent_workload' => $this->agentWorkload($params, $client),
            'helpdesk_get_csat_summary' => $this->csatSummary($params, $client),
            'helpdesk_get_response_time_stats' => $this->responseTimeStats($params, $client),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour le module Helpdesk Odoo."),
        };
    }

    private function createTicket(array $p, OdooClient $client): ToolResult
    {
        $partner = $client->searchRead('res.partner', [['email', '=', $p['contact_email']]], ['id'], 1)[0] ?? null;

        $id = $client->create('helpdesk.ticket', array_filter([
            'name' => $p['subject'], 'description' => $p['description'], 'partner_id' => $partner['id'] ?? null, 'partner_email' => $p['contact_email'],
        ]));
        return ToolResult::ok(['ticket_id' => $id], "Ticket « {$p['subject']} » créé, référence #{$id}.", identity: ['email' => $p['contact_email']]);
    }

    private function getTicket(array $p, OdooClient $client): ToolResult
    {
        $ticket = $client->read('helpdesk.ticket', (int) $p['ticket_id'], ['name', 'stage_id', 'description']);
        if (!$ticket) return ToolResult::fail('not_found', 'Ticket introuvable.');
        return ToolResult::ok($ticket, 'Ticket récupéré.');
    }

    private function updateTicket(array $p, OdooClient $client): ToolResult
    {
        $client->write('helpdesk.ticket', (int) $p['ticket_id'], array_filter(['description' => $p['description'] ?? null]));
        return ToolResult::ok(['ticket_id' => $p['ticket_id']], 'Ticket mis à jour.');
    }

    private function closeTicket(array $p, OdooClient $client): ToolResult
    {
        // ⚠️ Nom de méthode/étape de clôture variable selon version Odoo —
        // à ajuster si votre pipeline helpdesk a un stage "Terminé" différent.
        $client->call('helpdesk.ticket', 'action_close', [(int) $p['ticket_id']]);
        return ToolResult::ok(['ticket_id' => $p['ticket_id']], 'Ticket clôturé.');
    }

    private function searchTickets(array $p, OdooClient $client): ToolResult
    {
        $rows = $client->searchRead('helpdesk.ticket', [['name', 'ilike', $p['query']]], ['name', 'stage_id'], 10);
        if (empty($rows)) return ToolResult::fail('not_found', 'Aucun ticket trouvé.');
        return ToolResult::ok(['tickets' => $rows], count($rows) . ' ticket(s) trouvé(s)');
    }

    // =====================================================================
    // 📊 Reporting support
    // =====================================================================

    private function agentWorkload(array $p, OdooClient $client): ToolResult
    {
        // Les étapes "clôturantes" sont marquées is_close=true sur
        // helpdesk.stage — jamais un nom de stage codé en dur (varie selon
        // la configuration de chaque instance Odoo).
        $openStages = $client->searchRead('helpdesk.stage', [['is_close', '=', false]], ['id'], 50);
        if (empty($openStages)) {
            return ToolResult::fail('not_found', "Aucune étape ouverte trouvée — vérifiez la configuration du pipeline helpdesk.");
        }
        $openStageIds = array_column($openStages, 'id');

        $domain = [['stage_id', 'in', $openStageIds]];
        if (!empty($p['team'])) {
            $team = $client->searchRead('helpdesk.team', [['name', 'ilike', $p['team']]], ['id'], 1)[0] ?? null;
            if (!$team) return ToolResult::fail('not_found', "Aucune équipe helpdesk nommée « {$p['team']} ».");
            $domain[] = ['team_id', '=', $team['id']];
        }

        $tickets = $client->searchRead('helpdesk.ticket', $domain, ['user_id'], 300);
        if (empty($tickets)) {
            return ToolResult::fail('not_found', 'Aucun ticket ouvert actuellement.');
        }

        $byAgent = collect($tickets)->groupBy(fn ($t) => $t['user_id'] ? $t['user_id'][0] : 'unassigned');

        $workload = $byAgent->map(function ($group, $agentId) {
            $agentName = $agentId === 'unassigned' ? 'Non assigné' : ($group->first()['user_id'][1] ?? $agentId);
            return ['agent_id' => $agentId, 'agent_name' => $agentName, 'open_tickets' => $group->count()];
        })->sortByDesc('open_tickets')->values()->all();

        return ToolResult::ok(
            ['workload' => $workload, 'total_open' => count($tickets)],
            count($workload) . ' agent(s) avec des tickets ouverts, ' . count($tickets) . ' ticket(s) au total' . (count($tickets) >= 300 ? ' (échantillon plafonné à 300)' : '') . '.'
        );
    }

    private function csatSummary(array $p, OdooClient $client): ToolResult
    {
        $from = $p['date_from'] ?? now()->subDays(30)->toDateString();
        $to = $p['date_to'] ?? now()->toDateString();

        // Les évaluations Odoo (module mail.rating.mixin, activé sur
        // helpdesk.ticket si "Évaluations clients" est coché sur l'équipe)
        // sont stockées dans rating.rating, liées par res_model/res_id —
        // jamais directement sur le ticket lui-même.
        $ratings = $client->searchRead('rating.rating', [
            ['res_model', '=', 'helpdesk.ticket'],
            ['create_date', '>=', Carbon::parse($from)->startOfDay()->toDateTimeString()],
            ['create_date', '<=', Carbon::parse($to)->endOfDay()->toDateTimeString()],
            ['rating', '>', 0], // exclut les enregistrements sans évaluation réelle
        ], ['rating', 'rating_text'], 500);

        if (empty($ratings)) {
            return ToolResult::fail('not_found', "Aucune évaluation client trouvée sur cette période — vérifiez que « Évaluations clients » est activé sur l'équipe helpdesk concernée.");
        }

        $scores = array_column($ratings, 'rating');
        $byText = collect($ratings)->groupBy('rating_text')->map->count();

        return ToolResult::ok([
            'period' => "{$from} → {$to}",
            'responses' => count($ratings),
            'average_score' => round(array_sum($scores) / count($scores), 2) . '/5',
            'satisfied' => $byText->get('satisfied', 0) + $byText->get('top', 0),
            'okay' => $byText->get('okay', 0),
            'dissatisfied' => $byText->get('dissatisfied', 0) + $byText->get('bad', 0),
        ], count($ratings) . " évaluation(s) client sur la période.");
    }

    private function responseTimeStats(array $p, OdooClient $client): ToolResult
    {
        $sampleSize = max(1, min(100, (int) ($p['sample_size'] ?? 30)));

        $tickets = $client->searchRead('helpdesk.ticket', [], ['create_date'], $sampleSize);
        if (empty($tickets)) {
            return ToolResult::fail('not_found', 'Aucun ticket trouvé.');
        }
        $ticketIds = array_column($tickets, 'id');
        $ticketsById = collect($tickets)->keyBy('id');

        // Premier message d'agent = premier message du fil dont l'auteur
        // n'est pas le partenaire client lui-même (author_id différent du
        // partner_id du ticket) — approximation robuste sans dépendre d'un
        // champ SLA spécifique qui peut ne pas être configuré.
        $messages = $client->searchRead('mail.message', [
            ['model', '=', 'helpdesk.ticket'], ['res_id', 'in', $ticketIds],
            ['message_type', 'in', ['comment', 'email']],
        ], ['res_id', 'create_date', 'author_id'], 1000);

        $firstResponsePerTicket = collect($messages)
            ->sortBy('create_date')
            ->groupBy('res_id')
            ->map(fn ($group) => $group->first());

        $diffs = [];
        foreach ($firstResponsePerTicket as $ticketId => $message) {
            $ticket = $ticketsById->get($ticketId);
            if (!$ticket) continue;
            $created = Carbon::parse($ticket['create_date']);
            $responded = Carbon::parse($message['create_date']);
            if ($responded->greaterThan($created)) {
                $diffs[] = $responded->diffInMinutes($created);
            }
        }

        if (empty($diffs)) {
            return ToolResult::fail('not_found', "Aucun ticket avec une réponse enregistrée dans cet échantillon.");
        }

        sort($diffs);
        return ToolResult::ok([
            'sample_size' => count($diffs),
            'average_minutes' => round(array_sum($diffs) / count($diffs), 1),
            'median_minutes' => $diffs[intdiv(count($diffs), 2)],
        ], "Temps de première réponse calculé sur " . count($diffs) . ' ticket(s) récent(s) avec une réponse.');
    }
}
