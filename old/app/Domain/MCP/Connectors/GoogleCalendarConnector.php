<?php

namespace App\Domain\MCP\Connectors;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\AuthExpiredException;
use App\Domain\MCP\Exceptions\ConnectorUnavailableException;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Spatie\OpeningHours\OpeningHours;

/**
 * Connecteur Google Calendar (OAuth2, refresh token).
 * credentials attendus (déchiffrés) : { "access_token", "refresh_token", "expires_at" }
 * settings attendus : { "calendar_id": "primary", "timezone": "Europe/Paris", "working_hours": {...} }
 */
class GoogleCalendarConnector extends AbstractConnector
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_BASE = 'https://www.googleapis.com/calendar/v3/';

    public function slug(): string
    {
        return 'google_calendar';
    }

    public function listTools(): array
    {
        return [
            // ── Disponibilités ──
            new ToolSchema('google_calendar', 'find_available_slots',
                "Recherche les créneaux réellement disponibles correspondant aux critères demandés en tenant compte des horaires de travail configurés et des événements existants. Utiliser lorsque l'utilisateur souhaite planifier un rendez-vous mais n'a pas encore choisi d'horaire précis ou demande des propositions de créneaux. Ne pas utiliser pour vérifier un créneau déjà défini ; utiliser check_availability dans ce cas.",
                ['type' => 'object', 'properties' => [
                    'date_from' => ['type' => 'string', 'description' => 'Date de début (YYYY-MM-DD), défaut: aujourd\'hui'],
                    'date_to' => ['type' => 'string', 'description' => 'Date de fin (YYYY-MM-DD), défaut: +7 jours'],
                    'duration_minutes' => ['type' => 'integer', 'description' => 'Durée souhaitée du rendez-vous en minutes, défaut 30'],
                ]], defaultMode: 'auto', capability: 'scheduling.check_availability'),

            new ToolSchema('google_calendar', 'is_time_available',
                "Vérifie si un créneau précis est disponible. Utiliser uniquement lorsque la date et l'heure de début ainsi que de fin sont déjà connues. Pour proposer des créneaux alternatifs, utiliser find_available_slots.",
                ['type' => 'object', 'properties' => [
                    'start' => ['type' => 'string', 'description' => 'ISO 8601'], 'end' => ['type' => 'string', 'description' => 'ISO 8601'],
                ], 'required' => ['start', 'end']], defaultMode: 'auto'),

            new ToolSchema('google_calendar', 'get_busy_periods',
                "Retourne les périodes déjà occupées dans une plage de dates. Utiliser lorsque l'utilisateur souhaite connaître les indisponibilités, visualiser les créneaux occupés ou analyser le planning existant. Ne pas utiliser pour vérifier un créneau précis ni pour proposer des créneaux disponibles. Utiliser uniquement les périodes réellement retournées par l'outil et ne jamais déduire la disponibilité d'autres horaires.",
                ['type' => 'object', 'properties' => [
                    'date_from' => ['type' => 'string', 'description' => 'ISO 8601'], 'date_to' => ['type' => 'string', 'description' => 'ISO 8601'],
                ], 'required' => ['date_from', 'date_to']], defaultMode: 'auto', capability: 'scheduling.check_availability'),

            new ToolSchema('google_calendar', 'check_availability',
                "Vérifie si un créneau précis est disponible. Utiliser uniquement lorsque la date, l'heure de début et l'heure de fin sont déjà connues. Retourner uniquement le résultat fourni par l'outil. Si l'utilisateur n'a pas encore choisi d'horaire ou souhaite obtenir des propositions, utiliser find_available_slots plutôt que cet outil.",
                ['type' => 'object', 'properties' => [
                    'date_from' => ['type' => 'string'], 'date_to' => ['type' => 'string'],
                ], 'required' => ['date_from', 'date_to']], defaultMode: 'auto', capability: 'scheduling.check_availability'),

            new ToolSchema('google_calendar', 'get_working_hours',
                "Retourne les horaires de travail configurés pour le calendrier (jours travaillés, heures d'ouverture et éventuelles règles associées). Utiliser lorsque l'utilisateur souhaite connaître ses horaires de disponibilité théorique ou lorsque ces informations sont nécessaires avant une planification. Cet outil ne tient pas compte des événements existants et ne permet pas de déterminer si un créneau est réellement libre.",
                ['type' => 'object', 'properties' => []], defaultMode: 'auto'),

            // ── Rendez-vous ──
            new ToolSchema('google_calendar', 'create_event',
                "Crée un nouveau rendez-vous et envoie les invitations aux participants. Utiliser uniquement lorsque toutes les informations nécessaires sont connues (titre, horaire, participant). Si l'utilisateur n'a pas encore choisi un horaire, rechercher d'abord des disponibilités. Ne jamais créer un rendez-vous sur la base d'informations incomplètes ou supposées. Utiliser le résultat réel retourné par l'outil sans inventer d'identifiants, de liens ou de disponibilités.",
                ['type' => 'object', 'properties' => [
                    'title' => ['type' => 'string'], 'start' => ['type' => 'string', 'description' => 'ISO 8601'], 'end' => ['type' => 'string', 'description' => 'ISO 8601'],
                    'attendee_email' => ['type' => 'string'], 'description' => ['type' => 'string'], 'location' => ['type' => 'string'],
                    'add_google_meet' => ['type' => 'boolean', 'description' => 'Ajouter un lien de visioconférence Google Meet'],
                ], 'required' => ['title', 'start', 'end', 'attendee_email']], isWriteAction: true, defaultMode: 'auto', capability: 'scheduling.create_event'),

            new ToolSchema('google_calendar', 'create_google_meet',
                "Crée un rendez-vous incluant automatiquement une visioconférence Google Meet. Utiliser uniquement lorsque l'utilisateur demande explicitement une réunion en ligne, un appel vidéo ou un lien Google Meet. Dans les autres cas, utiliser create_event.",
                ['type' => 'object', 'properties' => [
                    'title' => ['type' => 'string'], 'start' => ['type' => 'string'], 'end' => ['type' => 'string'],
                    'attendee_email' => ['type' => 'string'], 'description' => ['type' => 'string'],
                ], 'required' => ['title', 'start', 'end', 'attendee_email']], isWriteAction: true, defaultMode: 'auto', capability: 'scheduling.create_event'),

            new ToolSchema('google_calendar', 'update_event',
                "Met à jour un rendez-vous existant identifié de manière unique. Modifier uniquement les champs explicitement demandés. Si l'identifiant est inconnu, rechercher d'abord le rendez-vous. En présence de plusieurs correspondances, demander une clarification avant toute modification.",
                ['type' => 'object', 'properties' => [
                    'event_id' => ['type' => 'string'], 'title' => ['type' => 'string'],
                    'start' => ['type' => 'string'], 'end' => ['type' => 'string'],
                    'description' => ['type' => 'string'], 'location' => ['type' => 'string'],
                ], 'required' => ['event_id']], isWriteAction: true, defaultMode: 'confirm', defaultConfirmActor: 'visitor', capability: 'scheduling.update_event'),

            new ToolSchema('google_calendar', 'reschedule_event',
                "Déplace un rendez-vous existant vers une nouvelle date ou heure. Utiliser uniquement lorsque l'utilisateur souhaite modifier la planification du rendez-vous. Vérifier que le nouvel horaire est clairement défini avant d'effectuer la modification.",
                ['type' => 'object', 'properties' => [
                    'event_id' => ['type' => 'string'], 'start' => ['type' => 'string'], 'end' => ['type' => 'string'],
                ], 'required' => ['event_id', 'start', 'end']], isWriteAction: true, defaultMode: 'confirm', defaultConfirmActor: 'visitor', capability: 'scheduling.update_event'),

            new ToolSchema('google_calendar', 'cancel_event',
                "Annule un rendez-vous existant identifié de manière unique. Ne jamais annuler un rendez-vous sur la base d'une supposition. Si plusieurs rendez-vous correspondent à la demande, demander une clarification avant l'annulation.",
                ['type' => 'object', 'properties' => ['event_id' => ['type' => 'string']], 'required' => ['event_id']],
                isWriteAction: true, defaultMode: 'confirm', defaultConfirmActor: 'visitor', capability: 'scheduling.cancel_event'),

            // ── Recherche (accès admin par défaut : expose le contenu de l'agenda) ──
            new ToolSchema('google_calendar', 'search_events',
                "Recherche des rendez-vous selon une période et/ou un mot-clé. Utiliser pour retrouver un rendez-vous avant une consultation, une modification, une annulation ou une gestion des participants lorsque son identifiant est inconnu. Ne jamais déduire un identifiant d'événement sans utiliser cette recherche.",
                ['type' => 'object', 'properties' => [
                    'date_from' => ['type' => 'string'], 'date_to' => ['type' => 'string'], 'query' => ['type' => 'string'],
                ]], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('google_calendar', 'get_event',
                "Récupère les informations complètes d'un rendez-vous identifié de manière unique, y compris les participants, le lieu, la description et les informations de visioconférence. Utiliser lorsque des détails supplémentaires sont nécessaires avant une autre action.",
                ['type' => 'object', 'properties' => ['event_id' => ['type' => 'string']], 'required' => ['event_id']],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            // ── Participants (accès admin par défaut) ──
            new ToolSchema('google_calendar', 'add_attendee',
                "Ajoute un participant à un rendez-vous existant. Utiliser uniquement lorsque l'événement est identifié de manière unique et que l'utilisateur souhaite inviter une nouvelle personne. Ne pas utiliser pour modifier les autres propriétés du rendez-vous.",
                ['type' => 'object', 'properties' => ['event_id' => ['type' => 'string'], 'email' => ['type' => 'string']], 'required' => ['event_id', 'email']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('google_calendar', 'remove_attendee',
                "Retire un participant d'un rendez-vous existant. Utiliser uniquement lorsque l'événement est identifié de manière unique et que l'utilisateur demande explicitement de retirer ce participant.",
                ['type' => 'object', 'properties' => ['event_id' => ['type' => 'string'], 'email' => ['type' => 'string']], 'required' => ['event_id', 'email']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('google_calendar', 'list_attendees',
                "Retourne la liste des participants d'un rendez-vous identifié de manière unique. Utiliser lorsque l'utilisateur souhaite consulter les invités sans modifier le rendez-vous.",
                ['type' => 'object', 'properties' => ['event_id' => ['type' => 'string']], 'required' => ['event_id']],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            // ── Notifications ──
            new ToolSchema('google_calendar', 'send_invitation',
                "Renvoie les invitations aux participants d'un rendez-vous existant. Utiliser uniquement lorsque l'utilisateur souhaite renvoyer les notifications sans modifier le contenu du rendez-vous.",
                ['type' => 'object', 'properties' => ['event_id' => ['type' => 'string']], 'required' => ['event_id']],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('google_calendar', 'reminder_settings',
                "Configure ou met à jour les rappels d'un rendez-vous existant. Utiliser uniquement lorsque l'utilisateur souhaite modifier les notifications précédant le rendez-vous.",
                ['type' => 'object', 'properties' => [
                    'event_id' => ['type' => 'string'], 'minutes_before' => ['type' => 'integer'],
                    'method' => ['type' => 'string', 'enum' => ['email', 'popup']],
                ], 'required' => ['event_id']], defaultMode: 'auto'),
        ];
    }

    public function authenticate(array $credentials): array
    {
        $expiresAt = $credentials['expires_at'] ?? null;

        if ($expiresAt && now()->timestamp < $expiresAt - 60) {
            return $credentials;
        }

        if (empty($credentials['refresh_token'])) {
            throw new AuthExpiredException('Refresh token Google absent, reconnexion OAuth requise.');
        }

        try {
            $response = Http::asForm()->post(self::TOKEN_URL, [
                'client_id' => config('mcp.connectors.google_calendar.client_id'),
                'client_secret' => config('mcp.connectors.google_calendar.client_secret'),
                'refresh_token' => $credentials['refresh_token'],
                'grant_type' => 'refresh_token',
            ]);
        } catch (RequestException $e) {
            Log::error('MCP Google Calendar: échec du refresh token', ['body' => $e->response?->body()]);
            throw new AuthExpiredException('Impossible de rafraîchir le token Google, reconnexion requise.');
        }

        if ($response->failed()) {
            Log::error('MCP Google Calendar: refresh token refusé', ['status' => $response->status(), 'body' => $response->body()]);
            throw new AuthExpiredException('Refresh token Google invalide ou révoqué.');
        }

        $data = $response->json();

        // 🆕 Préserve TOUTES les clés déjà présentes (calendar_id, timezone,
        // working_hours...) — seuls les champs OAuth sont mis à jour. Avant ce
        // correctif, chaque rafraîchissement de token effaçait silencieusement
        // les réglages du site pour l'appel en cours.
        return array_merge($credentials, [
            'access_token' => $data['access_token'],
            'refresh_token' => $credentials['refresh_token'],
            'expires_at' => now()->addSeconds($data['expires_in'])->timestamp,
        ]);
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            'find_available_slots' => $this->findAvailableSlots($params, $credentials),
            'is_time_available' => $this->isTimeAvailable($params, $credentials),
            'get_busy_periods', 'check_availability' => $this->getBusyPeriods($params, $credentials),
            'get_working_hours' => $this->getWorkingHoursResult($credentials),
            'create_event' => $this->createEvent($params, $credentials),
            'create_google_meet' => $this->createEvent(array_merge($params, ['add_google_meet' => true]), $credentials),
            'update_event' => $this->updateEvent($params, $credentials),
            'reschedule_event' => $this->rescheduleEvent($params, $credentials),
            'cancel_event' => $this->cancelEvent($params, $credentials),
            'search_events' => $this->searchEvents($params, $credentials),
            'get_event' => $this->getEvent($params, $credentials),
            'add_attendee' => $this->addAttendee($params, $credentials),
            'remove_attendee' => $this->removeAttendee($params, $credentials),
            'list_attendees' => $this->listAttendees($params, $credentials),
            'send_invitation' => $this->sendInvitation($params, $credentials),
            'reminder_settings' => $this->reminderSettings($params, $credentials),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour google_calendar."),
        };
    }

    // ── Disponibilités ──────────────────────────────────────────────

    private function findAvailableSlots(array $p, array $c): ToolResult
    {
        $timezone = $this->timezone($c);
        $duration = max(15, (int) ($p['duration_minutes'] ?? 30));
        $from = $this->parseLocal($p['date_from'] ?? now($timezone)->toDateString(), $timezone)->startOfDay();
        $to = $this->parseLocal($p['date_to'] ?? $from->copy()->addDays(7)->toDateString(), $timezone)->endOfDay();
        $openingHours = $this->openingHours($c); // null si non configuré => aucune restriction

        try {
            $response = $this->site($c)->post('freeBusy', [
                'timeMin' => $from->toIso8601String(), 'timeMax' => $to->toIso8601String(), 'timeZone' => $timezone,
                'items' => [['id' => $c['calendar_id'] ?? 'primary']],
            ]);
        } catch (RequestException $e) {
            Log::error('MCP Google Calendar find_available_slots a échoué', ['status' => $e->response?->status(), 'body' => $e->response?->body()]);
            throw new ConnectorUnavailableException('Google Calendar indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();

        $busy = collect($response->json('calendars.' . ($c['calendar_id'] ?? 'primary') . '.busy', []))
            ->map(fn ($b) => [Carbon::parse($b['start']), Carbon::parse($b['end'])]);

        $slots = [];
        $cursor = $from->copy();

        while ($cursor->lte($to) && count($slots) < 20) {
            // 🆕 si aucun horaire configuré, toute la journée est considérée
            // ouverte — c'est le freeBusy de Google qui reste le seul filtre.
            $ranges = $openingHours ? $this->rangesForDate($openingHours, $cursor) : [[$cursor->copy()->startOfDay(), $cursor->copy()->endOfDay()]];

            foreach ($ranges as [$windowStart, $windowEnd]) {
                $slotCursor = $windowStart->copy();

                while ($slotCursor->copy()->addMinutes($duration)->lte($windowEnd) && count($slots) < 20) {
                    $slotEnd = $slotCursor->copy()->addMinutes($duration);
                    $overlaps = $busy->contains(fn ($b) => $slotCursor->lt($b[1]) && $slotEnd->gt($b[0]));

                    if (!$overlaps && $slotCursor->isFuture()) {
                        $slots[] = ['start' => $slotCursor->toIso8601String(), 'end' => $slotEnd->toIso8601String()];
                    }
                    $slotCursor->addMinutes($duration);
                }
            }
            $cursor->addDay()->startOfDay();
        }

        if (empty($slots)) {
            return ToolResult::fail('not_found', "Aucun créneau disponible trouvé dans la période demandée.");
        }
        return ToolResult::ok(['slots' => $slots], count($slots) . ' créneau(x) disponible(s)');
    }

    private function isTimeAvailable(array $p, array $c): ToolResult
    {
        $timezone = $this->timezone($c);
        $start = $this->parseLocal($p['start'], $timezone);
        $end = $this->parseLocal($p['end'], $timezone);

        try {
            $response = $this->site($c)->post('freeBusy', [
                'timeMin' => $start->toIso8601String(), 'timeMax' => $end->toIso8601String(), 'timeZone' => $timezone,
                'items' => [['id' => $c['calendar_id'] ?? 'primary']],
            ]);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('Google Calendar indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();

        $available = empty($response->json('calendars.' . ($c['calendar_id'] ?? 'primary') . '.busy', []));
        return ToolResult::ok(['available' => $available], $available ? 'Créneau disponible.' : 'Créneau déjà occupé.');
    }

    private function getBusyPeriods(array $p, array $c): ToolResult
    {
        $timezone = $this->timezone($c);
        $from = $this->parseLocal($p['date_from'], $timezone);
        $to = $this->parseLocal($p['date_to'], $timezone);

        try {
            $response = $this->site($c)->post('freeBusy', [
                'timeMin' => $from->toIso8601String(), 'timeMax' => $to->toIso8601String(),
                'timeZone' => $timezone, 'items' => [['id' => $c['calendar_id'] ?? 'primary']],
            ]);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('Google Calendar indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        $busy = $response->json('calendars.' . ($c['calendar_id'] ?? 'primary') . '.busy', []);

        $openingHours = $this->openingHours($c);
        $workingWindows = null;
        $closedDates = [];

        if ($openingHours) {
            $workingWindows = [];
            $cursor = $from->copy()->startOfDay();
            while ($cursor->lte($to)) {
                $dayRanges = $this->rangesForDate($openingHours, $cursor);
                if (empty($dayRanges)) {
                    $closedDates[] = $cursor->toDateString();
                }
                foreach ($dayRanges as [$start, $end]) {
                    $workingWindows[] = ['start' => $start->toIso8601String(), 'end' => $end->toIso8601String()];
                }
                $cursor->addDay();
            }

            // 🆕 Zéro fenêtre d'ouverture sur TOUTE la période demandée = fermé
            // sur toute la période. On le dit explicitement en échec plutôt que
            // de renvoyer un tableau vide ambigu que le LLM peut lire comme
            // "pas de restriction" au lieu de "fermé".
            if (empty($workingWindows)) {
                return ToolResult::fail(
                    'closed',
                    "Fermé sur toute la période demandée (hors horaires d'ouverture configurés : " . implode(', ', $closedDates) . ').'
                );
            }
        }

        return ToolResult::ok(
            ['busy_slots' => $busy, 'working_hours_windows' => $workingWindows, 'closed_dates' => $closedDates ?: null],
            empty($busy) ? 'Aucune période occupée.' : count($busy) . ' période(s) occupée(s)',
        );
    }

    private function getWorkingHoursResult(array $c): ToolResult
    {
        if (empty($c['working_hours'])) {
            return ToolResult::ok(['working_hours' => null], "Aucun horaire de travail n'a été configuré pour ce site — toutes les plages libres du calendrier sont proposables.");
        }
        return ToolResult::ok(['working_hours' => $c['working_hours']], 'Horaires de travail récupérés.');
    }

    // ── Rendez-vous ──────────────────────────────────────────────────

    private function createEvent(array $p, array $c): ToolResult
    {
        $timezone = $this->timezone($c);
        Log::info('MCP Google Calendar create_event: fuseau résolu', [
            'resolved_timezone' => $timezone,
            'c_timezone_present' => array_key_exists('timezone', $c),
            'c_timezone_value' => $c['timezone'] ?? null,
        ]);

        $startDt = $this->parseLocal($p['start'], $timezone);
        $endDt = $this->parseLocal($p['end'], $timezone);

        // 🆕 Vérifié AVANT tout appel à Google — Google ne connaît pas la
        // notion de "jour fermé", c'est une règle propre à ELChat qui doit
        // être appliquée ici, pas seulement suggérée au LLM dans le prompt.
        if ($violation = $this->violatesWorkingHours($startDt, $endDt, $c)) {
            return ToolResult::fail('outside_working_hours', $violation);
        }

        $start = ['dateTime' => $startDt->toIso8601String(), 'timeZone' => $timezone];
        $end = ['dateTime' => $endDt->toIso8601String(), 'timeZone' => $timezone];

        $payload = array_filter([
            'summary' => $p['title'], 'description' => $p['description'] ?? null, 'location' => $p['location'] ?? null,
            'start' => $start, 'end' => $end,
            'attendees' => !empty($p['attendee_email']) ? [['email' => $p['attendee_email']]] : null,
        ], fn ($v) => $v !== null);

        $query = 'sendUpdates=all';
        if (!empty($p['add_google_meet'])) {
            $payload['conferenceData'] = ['createRequest' => ['requestId' => (string) Str::uuid()]];
            $query .= '&conferenceDataVersion=1';
        }

        try {
            $response = $this->site($c)->post('calendars/' . ($c['calendar_id'] ?? 'primary') . "/events?{$query}", $payload);
        } catch (RequestException $e) {
            if ($e->response?->status() === 409) return ToolResult::fail('conflict', 'Ce créneau est déjà réservé.');
            Log::error('MCP Google Calendar create_event a échoué', ['status' => $e->response?->status(), 'body' => $e->response?->body()]);
            throw new ConnectorUnavailableException('Google Calendar indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        $event = $response->json();

        return ToolResult::ok([
            'event_id' => $event['id'], 'html_link' => $event['htmlLink'], 'start' => $start['dateTime'],
            'meet_link' => $event['hangoutLink'] ?? null,
        ], "Rendez-vous créé" . (!empty($event['hangoutLink']) ? " avec lien Google Meet" : "") . ".");
    }

    private function updateEvent(array $p, array $c): ToolResult
    {
        $timezone = $this->timezone($c);
        $newStart = !empty($p['start']) ? $this->parseLocal($p['start'], $timezone) : null;
        $newEnd = !empty($p['end']) ? $this->parseLocal($p['end'], $timezone) : null;

        // 🆕 Même garde-fou que pour la création : un déplacement de
        // rendez-vous vers un créneau fermé doit être bloqué ici, pas laissé
        // à la seule discrétion du LLM.
        if ($newStart && $newEnd) {
            if ($violation = $this->violatesWorkingHours($newStart, $newEnd, $c)) {
                return ToolResult::fail('outside_working_hours', $violation);
            }
        }

        $payload = array_filter([
            'summary' => $p['title'] ?? null, 'description' => $p['description'] ?? null, 'location' => $p['location'] ?? null,
            'start' => $newStart ? ['dateTime' => $newStart->toIso8601String(), 'timeZone' => $timezone] : null,
            'end' => $newEnd ? ['dateTime' => $newEnd->toIso8601String(), 'timeZone' => $timezone] : null,
        ], fn ($v) => $v !== null);

        if (empty($payload)) {
            return ToolResult::fail('no_changes', "Aucune modification fournie.");
        }

        try {
            $this->site($c)->patch('calendars/' . ($c['calendar_id'] ?? 'primary') . "/events/{$p['event_id']}?sendUpdates=all", $payload);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Rendez-vous introuvable.');
            throw new ConnectorUnavailableException('Google Calendar indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['event_id' => $p['event_id']], 'Rendez-vous mis à jour.');
    }

    private function rescheduleEvent(array $p, array $c): ToolResult
    {
        if (empty($p['start']) || empty($p['end'])) {
            return ToolResult::fail('missing_params', "Il faut préciser la nouvelle date et heure (début et fin).");
        }
        return $this->updateEvent($p, $c);
    }

    private function cancelEvent(array $p, array $c): ToolResult
    {
        try {
            $this->site($c)->delete('calendars/' . ($c['calendar_id'] ?? 'primary') . "/events/{$p['event_id']}?sendUpdates=all");
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Rendez-vous introuvable ou déjà annulé.');
            throw new ConnectorUnavailableException('Google Calendar indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['event_id' => $p['event_id']], 'Rendez-vous annulé.');
    }

    // ── Recherche ────────────────────────────────────────────────────

    private function searchEvents(array $p, array $c): ToolResult
    {
        $timezone = $this->timezone($c);
        try {
            $response = $this->site($c)->get('calendars/' . ($c['calendar_id'] ?? 'primary') . '/events', array_filter([
                'timeMin' => !empty($p['date_from']) ? $this->parseLocal($p['date_from'], $timezone)->toIso8601String() : null,
                'timeMax' => !empty($p['date_to']) ? $this->parseLocal($p['date_to'], $timezone)->toIso8601String() : null,
                'q' => $p['query'] ?? null, 'singleEvents' => true, 'orderBy' => 'startTime', 'maxResults' => 20,
            ]));
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('Google Calendar indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();

        $events = collect($response->json('items', []))->map(fn ($e) => [
            'event_id' => $e['id'], 'title' => $e['summary'] ?? null,
            'start' => $e['start']['dateTime'] ?? $e['start']['date'] ?? null,
            'end' => $e['end']['dateTime'] ?? $e['end']['date'] ?? null,
        ])->all();

        if (empty($events)) return ToolResult::fail('not_found', 'Aucun rendez-vous trouvé sur cette période.');
        return ToolResult::ok(['events' => $events], count($events) . ' rendez-vous trouvé(s)');
    }

    private function getEvent(array $p, array $c): ToolResult
    {
        try {
            $event = $this->site($c)->get('calendars/' . ($c['calendar_id'] ?? 'primary') . "/events/{$p['event_id']}")->json();
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Rendez-vous introuvable.');
            throw new ConnectorUnavailableException('Google Calendar indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();

        return ToolResult::ok([
            'event_id' => $event['id'], 'title' => $event['summary'] ?? null,
            'start' => $event['start']['dateTime'] ?? null, 'end' => $event['end']['dateTime'] ?? null,
            'location' => $event['location'] ?? null, 'description' => $event['description'] ?? null,
            'attendees' => collect($event['attendees'] ?? [])->pluck('email')->all(),
            'meet_link' => $event['hangoutLink'] ?? null,
        ], 'Détails du rendez-vous récupérés.');
    }

    // ── Participants ─────────────────────────────────────────────────

    private function addAttendee(array $p, array $c): ToolResult
    {
        $eventResult = $this->getEvent($p, $c);
        if (!$eventResult->success) return $eventResult;

        $attendees = $eventResult->data['attendees'];
        if (!in_array($p['email'], $attendees, true)) $attendees[] = $p['email'];

        try {
            $this->site($c)->patch('calendars/' . ($c['calendar_id'] ?? 'primary') . "/events/{$p['event_id']}?sendUpdates=all", [
                'attendees' => array_map(fn ($e) => ['email' => $e], $attendees),
            ]);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('Google Calendar indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['event_id' => $p['event_id'], 'attendees' => $attendees], "{$p['email']} ajouté au rendez-vous.");
    }

    private function removeAttendee(array $p, array $c): ToolResult
    {
        $eventResult = $this->getEvent($p, $c);
        if (!$eventResult->success) return $eventResult;

        $attendees = array_values(array_filter($eventResult->data['attendees'], fn ($e) => $e !== $p['email']));

        try {
            $this->site($c)->patch('calendars/' . ($c['calendar_id'] ?? 'primary') . "/events/{$p['event_id']}?sendUpdates=all", [
                'attendees' => array_map(fn ($e) => ['email' => $e], $attendees),
            ]);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('Google Calendar indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['event_id' => $p['event_id'], 'attendees' => $attendees], "{$p['email']} retiré du rendez-vous.");
    }

    private function listAttendees(array $p, array $c): ToolResult
    {
        $eventResult = $this->getEvent($p, $c);
        if (!$eventResult->success) return $eventResult;
        return ToolResult::ok(['attendees' => $eventResult->data['attendees']], count($eventResult->data['attendees']) . ' participant(s)');
    }

    // ── Notifications ────────────────────────────────────────────────

    private function sendInvitation(array $p, array $c): ToolResult
    {
        try {
            $event = $this->site($c)->get('calendars/' . ($c['calendar_id'] ?? 'primary') . "/events/{$p['event_id']}")->json();
            $this->site($c)->patch('calendars/' . ($c['calendar_id'] ?? 'primary') . "/events/{$p['event_id']}?sendUpdates=all", [
                'description' => $event['description'] ?? '',
            ]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Rendez-vous introuvable.');
            throw new ConnectorUnavailableException('Google Calendar indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['event_id' => $p['event_id']], 'Invitation renvoyée aux participants.');
    }

    private function reminderSettings(array $p, array $c): ToolResult
    {
        $minutes = (int) ($p['minutes_before'] ?? 10);
        $method = in_array($p['method'] ?? 'popup', ['email', 'popup'], true) ? $p['method'] : 'popup';

        try {
            $this->site($c)->patch('calendars/' . ($c['calendar_id'] ?? 'primary') . "/events/{$p['event_id']}", [
                'reminders' => ['useDefault' => false, 'overrides' => [['method' => $method, 'minutes' => $minutes]]],
            ]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Rendez-vous introuvable.');
            throw new ConnectorUnavailableException('Google Calendar indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['event_id' => $p['event_id']], "Rappel configuré : {$minutes} min avant, par {$method}.");
    }

    // ── Utilitaires ──────────────────────────────────────────────────

    /**
     * 🆕 Point d'entrée unique pour interpréter une date/heure reçue du LLM.
     * Ignore tout décalage horaire qu'il aurait pu ajouter de lui-même
     * (Z, +02:00...) : le fuseau AUTHENTIQUE est toujours celui résolu pour ce
     * site (this->timezone($c)), jamais une supposition du modèle. Sans ce
     * nettoyage, un LLM qui répond "14:00Z" en pensant dire "14h heure locale"
     * décale silencieusement le rendez-vous.
     */
    private function parseLocal(string $raw, string $timezone): Carbon
    {
        $naive = preg_replace('/(Z|[+-]\d{2}:?\d{2})$/', '', trim($raw)) ?: $raw;

        try {
            return Carbon::parse($naive, $timezone);
        } catch (\Throwable) {
            return now($timezone);
        }
    }

    /**
     * 🆕 Garde-fou serveur, appliqué à TOUTE création/modification de
     * rendez-vous — indépendant de ce que le LLM a vérifié ou non au préalable.
     * Retourne un message d'erreur si le créneau demandé est hors des horaires
     * configurés, null si c'est correct (ou si aucun horaire n'est configuré).
     */
    private function violatesWorkingHours(Carbon $start, Carbon $end, array $c): ?string
    {
        $openingHours = $this->openingHours($c);
        if (!$openingHours) {
            return null; // aucune restriction configurée pour ce site
        }

        $ranges = $this->rangesForDate($openingHours, $start);

        if (empty($ranges)) {
            return "Fermé le " . $start->locale('fr')->isoFormat('dddd D MMMM') . " selon les horaires configurés.";
        }

        $withinAnyRange = collect($ranges)->contains(fn ($r) => $start->gte($r[0]) && $end->lte($r[1]));

        if (!$withinAnyRange) {
            $windows = collect($ranges)->map(fn ($r) => $r[0]->format('H:i') . '-' . $r[1]->format('H:i'))->implode(', ');
            return "En dehors des horaires d'ouverture ({$windows}) le " . $start->locale('fr')->isoFormat('dddd D MMMM') . '.';
        }

        return null;
    }

    /**
     * 🆕 Normalise n'importe quelle date reçue du LLM (avec ou sans
     * fuseau) vers le format Google Calendar {dateTime, timeZone} —
     * élimine définitivement le bug "Missing time zone definition",
     * quelle que soit la façon dont le modèle a formulé la date.
     */
    private function toGoogleDateTime(string $raw, string $timezone): array
    {
        $dt = $this->parseLocal($raw, $timezone);
        return ['dateTime' => $dt->toIso8601String(), 'timeZone' => $timezone];
    }

    /**
     * 🆕 Fuseau horaire résolu DYNAMIQUEMENT depuis le calendrier Google réel
     * du site (source de vérité, jamais une hypothèse). Un site peut le
     * surcharger explicitement via ses settings ('timezone') s'il le souhaite.
     * Mis en cache 24h par calendrier (clé stable sur le refresh_token, pas
     * l'access_token qui change à chaque rafraîchissement).
     */
    private function timezone(array $c): string
    {
        if (!empty($c['timezone'])) {
            return $c['timezone'];
        }

        $calendarId = $c['calendar_id'] ?? 'primary';
        $cacheKey = 'mcp:google_calendar:timezone:' . md5(($c['refresh_token'] ?? '') . ':' . $calendarId);

        return Cache::remember($cacheKey, now()->addDay(), function () use ($c, $calendarId) {
            try {
                $calendar = $this->site($c)->get("calendars/{$calendarId}")->json();
                if (!empty($calendar['timeZone'])) {
                    return $calendar['timeZone'];
                }
            } catch (\Throwable $e) {
                Log::warning('MCP Google Calendar: résolution du fuseau horaire échouée', ['error' => $e->getMessage()]);
            }
            return 'UTC'; // dernier repli neutre, jamais une hypothèse commerciale
        });
    }

    private function workingHours(array $c): array
    {
        return $c['working_hours'] ?? config('mcp.connectors.google_calendar.default_working_hours', []);
    }

    private function site(array $credentials)
    {
        return $this->http(self::API_BASE)->withToken($credentials['access_token']);
    }

    /**
     * 🆕 Horaires de travail propres au site, au format spatie/opening-hours
     * (ex: ["09:00-12:00", "14:00-18:00"] par jour). Configurés par le site
     * via ses settings de connecteur ; absents = null = aucune restriction.
     */
    private function openingHours(array $c): ?OpeningHours
    {
        if (empty($c['working_hours'])) {
            return null;
        }

        try {
            return OpeningHours::create($c['working_hours']);
        } catch (\Throwable $e) {
            Log::warning('MCP Google Calendar: working_hours mal formé, ignoré', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function rangesForDate(OpeningHours $openingHours, Carbon $date): array
    {
        return collect($openingHours->forDate($date))
            ->map(function ($range) use ($date) {
                [$start, $end] = explode('-', (string) $range);
                return [$date->copy()->setTimeFromTimeString($start), $date->copy()->setTimeFromTimeString($end)];
            })->all();
    }
}
