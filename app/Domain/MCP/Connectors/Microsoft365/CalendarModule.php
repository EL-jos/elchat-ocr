<?php

namespace App\Domain\MCP\Connectors\Microsoft365;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use App\Domain\Microsoft365\MicrosoftGraphClient;

final class CalendarModule extends AbstractMicrosoft365Module
{
    public function key(): string { return 'calendar'; }

    public function label(): string { return 'Calendrier Outlook'; }

    public function iconUrl(): ?string { return 'https://upload.wikimedia.org/wikipedia/commons/c/cc/Microsoft_Outlook_Icon_%282025%E2%80%93present%29.svg'; }

    /** @return ToolSchema[] */
    public function listTools(): array
    {
        return [
            $this->readTool('calendar_list_events', 'Liste les événements du calendrier Outlook sur une période donnée.', ['start' => ['type' => 'string'], 'end' => ['type' => 'string'], 'top' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], ['start', 'end'], 'calendar.read'),
            $this->readTool('calendar_get_event', 'Récupère un événement précis du calendrier Outlook.', ['event_id' => ['type' => 'string']], ['event_id'], 'calendar.read'),
            $this->writeTool('calendar_create_event', 'Crée un événement dans le calendrier Outlook après confirmation.', ['subject' => ['type' => 'string'], 'start' => ['type' => 'string'], 'end' => ['type' => 'string'], 'time_zone' => ['type' => 'string'], 'body' => ['type' => 'string'], 'location' => ['type' => 'string'], 'attendees' => ['type' => 'array']], ['subject', 'start', 'end'], 'calendar.create_event', 'confirm'),
            $this->writeTool('calendar_update_event', 'Modifie un événement existant du calendrier Outlook après confirmation.', ['event_id' => ['type' => 'string'], 'subject' => ['type' => 'string'], 'start' => ['type' => 'string'], 'end' => ['type' => 'string'], 'time_zone' => ['type' => 'string'], 'body' => ['type' => 'string'], 'location' => ['type' => 'string']], ['event_id'], 'calendar.update_event', 'confirm'),
            $this->writeTool('calendar_delete_event', 'Supprime un événement existant du calendrier Outlook après confirmation.', ['event_id' => ['type' => 'string']], ['event_id'], 'calendar.delete_event', 'confirm'),
        ];
    }

    /** @return array<string, list<string>> */
    protected function requiredScopes(): array
    {
        return [
            'calendar_list_events' => ['Calendars.Read'], 'calendar_get_event' => ['Calendars.Read'],
            'calendar_create_event' => ['Calendars.ReadWrite'], 'calendar_update_event' => ['Calendars.ReadWrite'], 'calendar_delete_event' => ['Calendars.ReadWrite'],
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context, MicrosoftGraphClient $graph): ToolResult
    {
        return match ($toolName) {
            'calendar_list_events' => $this->listEvents($graph, $params),
            'calendar_get_event' => $this->getEvent($graph, $params),
            'calendar_create_event' => $this->createEvent($graph, $params),
            'calendar_update_event' => $this->updateEvent($graph, $params),
            'calendar_delete_event' => $this->deleteEvent($graph, $params),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour le module Calendrier Microsoft 365."),
        };
    }

    private function listEvents(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $query = [
            '$top' => min(100, max(1, (int) ($p['top'] ?? 50))),
            '$select' => 'id,subject,start,end,location,organizer,attendees,webLink,isAllDay',
            '$orderby' => 'start/dateTime',
        ];
        if (!empty($p['start'])) $query['startDateTime'] = (string) $p['start'];
        if (!empty($p['end'])) $query['endDateTime'] = (string) $p['end'];

        $events = $g->collectPages('/me/calendarView', $query, ['Prefer' => 'outlook.timezone="UTC"']);
        return ToolResult::ok(['events' => $events], count($events) . ' événement(s) récupéré(s)');
    }

    private function getEvent(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $event = $g->get('/me/events/' . $this->id($p['event_id']), ['$select' => 'id,subject,body,start,end,location,organizer,attendees,webLink,isAllDay']);
        return ToolResult::ok(['event' => $event], 'Événement Outlook récupéré.');
    }

    private function createEvent(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $event = $g->post('/me/events', $this->eventBody($p));
        return ToolResult::ok(['event' => $event], 'Événement Outlook créé.');
    }

    private function updateEvent(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $event = $g->patch('/me/events/' . $this->id($p['event_id']), $this->eventBody($p));
        return ToolResult::ok(['event' => $event], 'Événement Outlook mis à jour.');
    }

    private function deleteEvent(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $g->delete('/me/events/' . $this->id($p['event_id']));
        return ToolResult::ok(['event_id' => $p['event_id']], 'Événement Outlook supprimé.');
    }

    private function eventBody(array $p): array
    {
        $timezone = (string) ($p['time_zone'] ?? 'UTC');
        $body = array_filter([
            'subject' => $p['subject'] ?? null,
            'body' => isset($p['body']) ? ['contentType' => 'HTML', 'content' => (string) $p['body']] : null,
            'start' => isset($p['start']) ? ['dateTime' => (string) $p['start'], 'timeZone' => $timezone] : null,
            'end' => isset($p['end']) ? ['dateTime' => (string) $p['end'], 'timeZone' => $timezone] : null,
            'location' => isset($p['location']) ? ['displayName' => (string) $p['location']] : null,
            'attendees' => isset($p['attendees']) ? $this->attendees($p['attendees']) : null,
        ], static fn ($value): bool => $value !== null);

        return $body;
    }

    private function attendees(array $attendees): array
    {
        return array_values(array_filter(array_map(function ($value): ?array {
            $address = is_array($value) ? ($value['email'] ?? $value['address'] ?? null) : $value;
            if (!is_string($address) || !filter_var($address, FILTER_VALIDATE_EMAIL)) return null;
            return ['emailAddress' => ['address' => $address], 'type' => 'required'];
        }, $attendees)));
    }
}
