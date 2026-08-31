<?php

namespace App\Domain\MCP\Connectors\Microsoft365;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use App\Domain\Microsoft365\MicrosoftGraphClient;

final class TeamsModule extends AbstractMicrosoft365Module
{
    public function key(): string { return 'teams'; }

    public function label(): string { return 'Teams'; }

    public function iconUrl(): ?string { return 'https://upload.wikimedia.org/wikipedia/commons/0/07/Microsoft_Office_Teams_%282025%E2%80%93present%29.svg'; }

    /** @return ToolSchema[] */
    public function listTools(): array
    {
        return [
            $this->readTool('teams_list_teams', 'Liste les équipes Teams dont l’utilisateur connecté est membre.', [], [], 'teams.read'),
            $this->readTool('teams_list_channels', 'Liste les canaux d’une équipe Teams.', ['team_id' => ['type' => 'string']], ['team_id'], 'teams.read'),
            $this->readTool('teams_list_channel_messages', 'Liste les messages récents d’un canal Teams.', ['team_id' => ['type' => 'string'], 'channel_id' => ['type' => 'string'], 'top' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]], ['team_id', 'channel_id'], 'teams.read'),
            $this->writeTool('teams_send_channel_message', 'Publie un message dans un canal Teams. Confirmation humaine obligatoire.', ['team_id' => ['type' => 'string'], 'channel_id' => ['type' => 'string'], 'content' => ['type' => 'string']], ['team_id', 'channel_id', 'content'], 'teams.send', 'confirm'),
        ];
    }

    /** @return array<string, list<string>> */
    protected function requiredScopes(): array
    {
        return ['teams_list_teams' => ['Team.ReadBasic.All'], 'teams_list_channels' => ['Channel.ReadBasic.All'], 'teams_list_channel_messages' => ['ChannelMessage.Read.All'], 'teams_send_channel_message' => ['ChannelMessage.Send']];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context, MicrosoftGraphClient $graph): ToolResult
    {
        return match ($toolName) {
            'teams_list_teams' => $this->listTeams($graph),
            'teams_list_channels' => $this->listChannels($graph, $params),
            'teams_list_channel_messages' => $this->listMessages($graph, $params),
            'teams_send_channel_message' => $this->sendMessage($graph, $params),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour le module Teams Microsoft 365."),
        };
    }

    private function listTeams(MicrosoftGraphClient $g): ToolResult
    {
        return ToolResult::ok(['teams' => $g->collectPages('/me/joinedTeams')], 'Équipes Teams récupérées.');
    }

    private function listChannels(MicrosoftGraphClient $g, array $p): ToolResult
    {
        return ToolResult::ok(['channels' => $g->collectPages('/teams/' . $this->id($p['team_id']) . '/channels')], 'Canaux Teams récupérés.');
    }

    private function listMessages(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $messages = $g->collectPages('/teams/' . $this->id($p['team_id']) . '/channels/' . $this->id($p['channel_id']) . '/messages', ['$top' => min(50, max(1, (int) ($p['top'] ?? 20)))]);
        return ToolResult::ok(['messages' => $messages], count($messages) . ' message(s) Teams récupéré(s)');
    }

    private function sendMessage(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $g->post('/teams/' . $this->id($p['team_id']) . '/channels/' . $this->id($p['channel_id']) . '/messages', ['body' => ['contentType' => 'html', 'content' => (string) $p['content']]]);
        return ToolResult::ok(['team_id' => $p['team_id'], 'channel_id' => $p['channel_id']], 'Message Teams envoyé.');
    }
}
