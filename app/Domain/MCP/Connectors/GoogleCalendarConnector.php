<?php

namespace App\Domain\MCP\Connectors;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\AuthExpiredException;
use App\Domain\MCP\Exceptions\ConnectorUnavailableException;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Connecteur Google Calendar (OAuth2, refresh token).
 * credentials attendus (déchiffrés) : { "access_token", "refresh_token", "expires_at" }
 * settings attendus : { "calendar_id": "primary" }
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
            new ToolSchema(
                connectorSlug: $this->slug(),
                name: 'check_availability',
                description: "Vérifie les créneaux disponibles sur le calendrier entre deux dates.",
                parameters: [
                    'type' => 'object',
                    'properties' => [
                        'date_from' => ['type' => 'string', 'description' => 'ISO 8601'],
                        'date_to' => ['type' => 'string', 'description' => 'ISO 8601'],
                    ],
                    'required' => ['date_from', 'date_to'],
                ],
                isWriteAction: false,
            ),
            new ToolSchema(
                connectorSlug: $this->slug(),
                name: 'create_event',
                description: "Crée un rendez-vous dans le calendrier et envoie l'invitation au participant.",
                parameters: [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'start' => ['type' => 'string', 'description' => 'ISO 8601'],
                        'end' => ['type' => 'string', 'description' => 'ISO 8601'],
                        'attendee_email' => ['type' => 'string'],
                    ],
                    'required' => ['title', 'start', 'end', 'attendee_email'],
                ],
                isWriteAction: true,
            ),
        ];
    }

    public function authenticate(array $credentials): array
    {
        $expiresAt = $credentials['expires_at'] ?? null;

        if ($expiresAt && now()->timestamp < $expiresAt - 60) {
            return $credentials; // token encore valide
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
        } catch (RequestException) {
            throw new AuthExpiredException('Impossible de rafraîchir le token Google, reconnexion requise.');
        }

        if ($response->failed()) {
            throw new AuthExpiredException('Refresh token Google invalide ou révoqué.');
        }

        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $credentials['refresh_token'], // Google ne le renvoie pas systématiquement
            'expires_at' => now()->addSeconds($data['expires_in'])->timestamp,
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            'check_availability' => $this->checkAvailability($params, $credentials),
            'create_event' => $this->createEvent($params, $credentials),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour google_calendar."),
        };
    }

    private function checkAvailability(array $params, array $credentials): ToolResult
    {
        try {
            $response = $this->site($credentials)->post('freeBusy', [
                'timeMin' => $params['date_from'],
                'timeMax' => $params['date_to'],
                'items' => [['id' => $credentials['calendar_id'] ?? 'primary']],
            ]);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('Google Calendar indisponible: ' . $e->getMessage());
        }

        $this->recordSuccess();
        $busy = $response->json('calendars.' . ($credentials['calendar_id'] ?? 'primary') . '.busy', []);

        return ToolResult::ok(
            ['busy_slots' => $busy, 'range' => ['from' => $params['date_from'], 'to' => $params['date_to']]],
            empty($busy) ? 'Créneau entièrement disponible' : count($busy) . ' créneau(x) occupé(s) trouvé(s)'
        );
    }

    private function createEvent(array $params, array $credentials): ToolResult
    {
        try {
            $response = $this->site($credentials)->post('calendars/' . ($credentials['calendar_id'] ?? 'primary') . '/events', [
                'summary' => $params['title'],
                'start' => ['dateTime' => $params['start']],
                'end' => ['dateTime' => $params['end']],
                'attendees' => [['email' => $params['attendee_email']]],
                'sendUpdates' => 'all',
            ]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 409) {
                return ToolResult::fail('conflict', 'Ce créneau est déjà réservé.');
            }
            throw new ConnectorUnavailableException('Google Calendar indisponible: ' . $e->getMessage());
        }

        $this->recordSuccess();
        $event = $response->json();

        return ToolResult::ok([
            'event_id' => $event['id'],
            'html_link' => $event['htmlLink'],
            'start' => $params['start'],
        ], "Rendez-vous créé et invitation envoyée à {$params['attendee_email']}.");
    }

    private function site(array $credentials)
    {
        return $this->http(self::API_BASE)->withToken($credentials['access_token']);
    }
}
