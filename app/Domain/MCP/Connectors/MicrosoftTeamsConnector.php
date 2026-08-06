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
            new ToolSchema('microsoft_teams', 'send_message',
                "Envoie une notification dans un canal Microsoft Teams via le webhook configuré.

Utiliser cet outil uniquement lorsqu'une information mérite d'être transmise à une équipe humaine.

Cas d'usage typiques :

- escalade vers une équipe ;
- incident ou erreur importante ;
- demande nécessitant une intervention humaine ;
- alerte métier ;
- validation attendue ;
- notification opérationnelle ;
- partage d'une information importante.

Ne pas utiliser cet outil pour :

- répondre directement à l'utilisateur ;
- envoyer des messages de conversation ordinaires ;
- publier chaque échange ;
- envoyer des notifications redondantes ;
- remplacer une action réalisable par un autre outil.

Avant l'appel :

- vérifier qu'une notification est réellement utile ;
- regrouper plusieurs informations liées dans un seul message lorsque cela est pertinent ;
- éviter plusieurs notifications successives pour le même événement ;
- produire un titre court et explicite ;
- produire un message clair, structuré et directement exploitable par l'équipe.

Ne jamais inventer :

- un incident ;
- un résultat ;
- un identifiant ;
- un utilisateur ;
- une décision métier.

Après un échec du webhook :

- informer l'utilisateur si cela impacte sa demande ;
- ne pas réessayer en boucle ;
- ne pas envoyer plusieurs fois la même notification.

Après succès, confirmer uniquement que la notification a été transmise.", [
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
