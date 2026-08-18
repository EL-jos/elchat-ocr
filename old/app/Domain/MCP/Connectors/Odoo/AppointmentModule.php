<?php

namespace App\Domain\MCP\Connectors\Odoo;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use App\Domain\MCP\Contracts\{ToolResult, ToolSchema};
use App\Domain\MCP\Exceptions\ToolNotFoundException;

class AppointmentModule implements OdooModuleInterface
{
    public function technicalModuleName(): string { return 'appointment'; }

    public function listTools(): array
    {
        return [
            new ToolSchema('odoo', 'appointment_check_availability',
                "Recherche les créneaux réellement disponibles pour un type de rendez-vous sur une période donnée en tenant compte des disponibilités configurées dans Odoo Appointment. Utiliser lorsque l'utilisateur souhaite prendre un rendez-vous mais n'a pas encore choisi un créneau précis. Si aucune période n'est indiquée, utiliser les valeurs par défaut du connecteur. Ne pas utiliser pour réserver un rendez-vous ni pour vérifier un rendez-vous déjà existant. Utiliser uniquement les créneaux retournés par l'outil et ne jamais en inventer.", [
                'type' => 'object', 'properties' => ['appointment_type_id' => ['type' => 'integer'], 'date_from' => ['type' => 'string'], 'date_to' => ['type' => 'string']], 'required' => ['appointment_type_id'],
            ], defaultMode: 'auto', capability: 'scheduling.check_availability'),

            new ToolSchema('odoo', 'appointment_book',
                "Réserve un rendez-vous Odoo Appointment pour un type de rendez-vous, un créneau de début et un contact identifiés. Utiliser uniquement lorsque le créneau choisi est connu et que les informations nécessaires (type de rendez-vous, heure de début et adresse e-mail du participant) sont disponibles. Si aucun créneau n'a encore été sélectionné, rechercher d'abord les disponibilités. Ne jamais réserver un créneau supposé ou non retourné par l'outil de disponibilité. Utiliser exclusivement les données fournies par l'utilisateur et les résultats des outils.", [
                'type' => 'object', 'properties' => ['appointment_type_id' => ['type' => 'integer'], 'start' => ['type' => 'string'], 'contact_email' => ['type' => 'string']], 'required' => ['appointment_type_id', 'start', 'contact_email'],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'scheduling.create_event'),

            new ToolSchema('odoo', 'appointment_cancel',
                "Annule un rendez-vous Odoo Appointment identifié de manière unique. Utiliser uniquement lorsque l'utilisateur exprime clairement son intention d'annuler un rendez-vous existant. Si l'identifiant du rendez-vous est inconnu, rechercher ou identifier le rendez-vous avant l'annulation. Ne jamais annuler un rendez-vous sur la base d'une supposition ni annuler plusieurs rendez-vous sans confirmation explicite. Utiliser uniquement le résultat réel retourné par l'outil.", [
                'type' => 'object', 'properties' => ['event_id' => ['type' => 'integer']], 'required' => ['event_id'],
            ], isWriteAction: true, defaultMode: 'confirm', defaultConfirmActor: 'visitor', capability: 'scheduling.cancel_event'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context, OdooClient $client): ToolResult
    {
        return match ($toolName) {
            'appointment_check_availability' => $this->checkAvailability($params, $client),
            'appointment_book' => $this->book($params, $client),
            'appointment_cancel' => $this->cancel($params, $client),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour le module Appointment Odoo."),
        };
    }

    private function checkAvailability(array $p, OdooClient $client): ToolResult
    {
        $type = $client->read('appointment.type', (int) $p['appointment_type_id'], ['name', 'appointment_duration', 'appointment_tz']);
        if (!$type) return ToolResult::fail('not_found', 'Type de rendez-vous introuvable.');

        $timezone = $type['appointment_tz'] ?: 'UTC';
        $durationMinutes = max(15, (int) round(((float) $type['appointment_duration']) * 60));

        $from = Carbon::parse($p['date_from'] ?? now($timezone)->toDateString(), $timezone)->startOfDay();
        $to = Carbon::parse($p['date_to'] ?? $from->copy()->addDays(7)->toDateString(), $timezone)->endOfDay();

        // 🆕 slot_ids = les créneaux récurrents configurés (jour de semaine +
        // heure début/fin) pour ce type de rendez-vous — l'équivalent des
        // "horaires de travail" côté Google Calendar, mais définis directement
        // dans Odoo plutôt que dans ELChat.
        try {
            $slotIds = $client->read('appointment.type', (int) $p['appointment_type_id'], ['slot_ids'])['slot_ids'] ?? [];
            $slots = $slotIds ? $client->call('appointment.slot', 'read', [$slotIds], ['fields' => ['weekday', 'start_hour', 'end_hour', 'slot_type']]) : [];
        } catch (\Throwable $e) {
            Log::warning('MCP Odoo: lecture appointment.slot échouée', ['error' => $e->getMessage()]);
            $slots = [];
        }

        // Créneaux occupés : tous les événements calendrier déjà pris sur la période.
        try {
            $busyEvents = $client->searchRead('calendar.event', [
                ['start', '>=', $from->toIso8601String()], ['start', '<=', $to->toIso8601String()], ['active', '=', true],
            ], ['start', 'stop'], 100);
        } catch (\Throwable $e) {
            return ToolResult::fail('connector_unavailable', "Impossible de vérifier les disponibilités pour le moment.");
        }
        $busy = collect($busyEvents)->map(fn ($b) => [Carbon::parse($b['start']), Carbon::parse($b['stop'])]);

        $recurring = collect($slots)->where('slot_type', 'recurring');
        $availableSlots = [];
        $cursor = $from->copy();

        while ($cursor->lte($to) && count($availableSlots) < 20) {
            // weekday Odoo : '0' = lundi ... '6' = dimanche (ISO - 1)
            $weekday = (string) ($cursor->dayOfWeekIso - 1);
            $dayRanges = $recurring->where('weekday', $weekday);

            foreach ($dayRanges as $range) {
                $windowStart = $cursor->copy()->startOfDay()->addMinutes((int) round($range['start_hour'] * 60));
                $windowEnd = $cursor->copy()->startOfDay()->addMinutes((int) round($range['end_hour'] * 60));
                $slotCursor = $windowStart->copy();

                while ($slotCursor->copy()->addMinutes($durationMinutes)->lte($windowEnd) && count($availableSlots) < 20) {
                    $slotEnd = $slotCursor->copy()->addMinutes($durationMinutes);
                    $overlaps = $busy->contains(fn ($b) => $slotCursor->lt($b[1]) && $slotEnd->gt($b[0]));

                    if (!$overlaps && $slotCursor->isFuture()) {
                        $availableSlots[] = ['start' => $slotCursor->toIso8601String(), 'end' => $slotEnd->toIso8601String()];
                    }
                    $slotCursor->addMinutes($durationMinutes);
                }
            }
            $cursor->addDay()->startOfDay();
        }

        if (empty($availableSlots)) {
            // 🆕 Distingue "aucun créneau récurrent configuré du tout" (config à
            // vérifier côté Odoo) de "tout est occupé" (vraie indisponibilité).
            if ($recurring->isEmpty()) {
                return ToolResult::fail('not_configured', "Aucun horaire récurrent n'est configuré pour ce type de rendez-vous dans Odoo.");
            }
            return ToolResult::fail('not_found', 'Aucun créneau disponible sur cette période.');
        }

        return ToolResult::ok(['slots' => $availableSlots], count($availableSlots) . ' créneau(x) disponible(s)');
    }

    private function book(array $p, OdooClient $client): ToolResult
    {
        $partner = $client->searchRead('res.partner', [['email', '=', $p['contact_email']]], ['id'], 1)[0] ?? null;
        $partnerId = $partner['id'] ?? $client->create('res.partner', ['name' => $p['contact_email'], 'email' => $p['contact_email']]);

        $eventId = $client->create('calendar.event', [
            'name' => 'Rendez-vous', 'start' => $p['start'], 'appointment_type_id' => (int) $p['appointment_type_id'],
            'partner_ids' => [[6, 0, [$partnerId]]],
        ]);
        return ToolResult::ok(['event_id' => $eventId], 'Rendez-vous réservé.', identity: ['email' => $p['contact_email']]);
    }

    private function cancel(array $p, OdooClient $client): ToolResult
    {
        $client->write('calendar.event', (int) $p['event_id'], ['active' => false]);
        return ToolResult::ok(['event_id' => $p['event_id']], 'Rendez-vous annulé.');
    }
}
