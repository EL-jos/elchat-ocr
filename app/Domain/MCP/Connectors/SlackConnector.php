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
            new ToolSchema('slack', 'send_message', "Envoie un message à un canal Slack (ex: notifier l'équipe d'une demande de rendez-vous ou d'un lead qualifié).", [
                'type' => 'object', 'properties' => ['channel' => ['type' => 'string', 'description' => 'Nom du canal, ex: #ventes'], 'message' => ['type' => 'string']], 'required' => ['channel', 'message'],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'communication.notify_team'),

            new ToolSchema('slack', 'list_channels', "Liste les canaux disponibles.", ['type' => 'object', 'properties' => []],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('slack', 'create_channel', "Crée un nouveau canal.", [
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
