<?php

namespace App\Domain\MCP\Connectors;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\AuthExpiredException;
use App\Domain\MCP\Exceptions\ConnectorUnavailableException;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use Illuminate\Http\Client\RequestException;

/** credentials attendus : { "bot_token": "xoxb-..." } (Bot User OAuth Token d'une Slack App) */
class SlackConnector extends AbstractConnector
{
    public function slug(): string { return 'slack'; }

    public function authenticate(array $credentials): array
    {
        if (empty($credentials['bot_token'])) {
            throw new AuthExpiredException('Jeton bot Slack manquant.');
        }
        return $credentials;
    }

    public function listTools(): array
    {
        return [
            new ToolSchema('slack', 'send_message',
                "Envoie un message dans un canal Slack afin d'informer une équipe ou un service d'un événement nécessitant une attention humaine.
Utilise cet outil lorsqu'une notification interne est plus utile qu'une réponse au visiteur.

Cas d'utilisation :
- notifier un nouveau lead qualifié à l'équipe commerciale ;
- prévenir le support d'un incident ou d'une panne signalée ;
- envoyer une demande urgente à une équipe interne ;
- partager un résumé d'une conversation importante ;
- transmettre une demande de devis, de partenariat ou de recrutement ;
- avertir d'une commande inhabituelle ou d'un risque détecté ;
- escalader une situation nécessitant une intervention humaine.

Ne pas utiliser pour répondre directement au visiteur.
Le paramètre 'channel' doit contenir le canal Slack cible (ex : #sales, #support, #marketing).", [
                'type' => 'object', 'properties' => ['channel' => ['type' => 'string', 'description' => 'Nom du canal, ex: #ventes'], 'message' => ['type' => 'string']], 'required' => ['channel', 'message'],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'communication.notify_team'),

            new ToolSchema('slack', 'list_channels',
                "Liste tous les canaux Slack accessibles par le bot.

Utilise cet outil lorsqu'il faut :
- retrouver le bon canal avant d'envoyer une notification ;
- proposer les canaux disponibles à un administrateur ;
- vérifier qu'un canal existe.

À utiliser uniquement lorsqu'une information sur les canaux est réellement nécessaire.", ['type' => 'object', 'properties' => []],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('slack', 'create_channel',
                "Crée un nouveau canal Slack pour organiser une collaboration ou un projet.

Cas d'utilisation :
- création d'un canal dédié à un nouveau client ;
- ouverture d'un canal pour un projet ;
- création d'un espace pour un événement ;
- mise en place d'un canal de crise ou d'incident.

À utiliser uniquement lorsqu'un nouveau canal est réellement nécessaire et qu'aucun canal existant n'est approprié.", [
                'type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            'send_message' => $this->sendMessage($params, $credentials),
            'list_channels' => $this->listChannels($credentials),
            'create_channel' => $this->createChannel($params, $credentials),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour slack."),
        };
    }

    private function sendMessage(array $p, array $c): ToolResult
    {
        try {
            $res = $this->client($c)->post('chat.postMessage', ['channel' => $p['channel'], 'text' => $p['message']])->json();
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('Slack indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        if (!($res['ok'] ?? false)) return ToolResult::fail('slack_error', $res['error'] ?? 'Erreur Slack inconnue.');
        return ToolResult::ok(['ts' => $res['ts']], "Message envoyé sur {$p['channel']}.");
    }

    private function listChannels(array $c): ToolResult
    {
        try {
            $res = $this->client($c)->get('conversations.list', ['limit' => 100])->json();
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('Slack indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        $channels = collect($res['channels'] ?? [])->map(fn ($ch) => ['id' => $ch['id'], 'name' => $ch['name']])->all();
        return ToolResult::ok(['channels' => $channels], count($channels) . ' canal(aux)');
    }

    private function createChannel(array $p, array $c): ToolResult
    {
        try {
            $res = $this->client($c)->post('conversations.create', ['name' => $p['name']])->json();
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('Slack indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        if (!($res['ok'] ?? false)) return ToolResult::fail('slack_error', $res['error'] ?? 'Erreur Slack inconnue.');
        return ToolResult::ok(['channel_id' => $res['channel']['id']], "Canal #{$p['name']} créé.");
    }

    private function client(array $c)
    {
        return $this->http('https://slack.com/api/')->withToken($c['bot_token']);
    }
}
