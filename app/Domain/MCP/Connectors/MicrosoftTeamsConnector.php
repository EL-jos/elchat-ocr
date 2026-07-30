<?php

namespace App\Domain\MCP\Connectors;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\AuthExpiredException;
use App\Domain\MCP\Exceptions\ConnectorUnavailableException;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use Illuminate\Http\Client\RequestException;

/**
 * MVP volontairement simplifié : notification via Webhook entrant Teams
 * (Paramètres du canal → Connecteurs → Webhook entrant), pas d'OAuth
 * Microsoft Graph complet. Suffisant pour le cas d'usage principal
 * (notifier l'équipe) ; extensible plus tard vers Graph si vous avez besoin
 * de lire des messages ou gérer des canaux.
 * credentials attendus : { "webhook_url": "https://.../IncomingWebhook/..." }
 */
class MicrosoftTeamsConnector extends AbstractConnector
{
    public function slug(): string { return 'microsoft_teams'; }

    public function authenticate(array $credentials): array
    {
        if (empty($credentials['webhook_url'])) {
            throw new AuthExpiredException('URL de webhook Teams manquante.');
        }
        return $credentials;
    }

    public function listTools(): array
    {
        return [
            new ToolSchema('microsoft_teams', 'send_message', "Envoie une notification dans un canal Teams (ex: prévenir l'équipe d'une demande importante).", [
                'type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'message' => ['type' => 'string']], 'required' => ['message'],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'communication.notify_team'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            'send_message' => $this->sendMessage($params, $credentials),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour microsoft_teams."),
        };
    }

    private function sendMessage(array $p, array $c): ToolResult
    {
        try {
            $this->http()->post($c['webhook_url'], [
                '@type' => 'MessageCard', '@context' => 'https://schema.org/extensions',
                'title' => $p['title'] ?? 'Notification ELChat', 'text' => $p['message'],
            ]);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('Microsoft Teams indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok([], 'Message envoyé sur Teams.');
    }
}
