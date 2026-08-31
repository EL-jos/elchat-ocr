<?php

namespace App\Domain\MCP\Connectors\Microsoft365;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use App\Domain\Microsoft365\MicrosoftGraphClient;

final class OutlookModule extends AbstractMicrosoft365Module
{
    public function key(): string { return 'outlook'; }

    public function label(): string { return 'Outlook'; }

    public function iconUrl(): ?string { return 'https://upload.wikimedia.org/wikipedia/commons/c/cc/Microsoft_Outlook_Icon_%282025%E2%80%93present%29.svg'; }

    /** @return ToolSchema[] */
    public function listTools(): array
    {
        return [
            $this->readTool('outlook_search_messages', 'Recherche des e-mails dans Outlook avec la recherche Microsoft Graph.', ['query' => ['type' => 'string'], 'top' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]], ['query'], 'outlook.search'),
            $this->readTool('outlook_get_message', 'Lit un e-mail Outlook précis, sans exposer de jeton ni d’identifiant d’authentification.', ['message_id' => ['type' => 'string']], ['message_id'], 'outlook.read'),
            $this->writeTool('outlook_create_draft', 'Crée un brouillon Outlook ; la création du brouillon est distincte de l’envoi.', ['subject' => ['type' => 'string'], 'body' => ['type' => 'string'], 'to' => ['type' => 'array'], 'cc' => ['type' => 'array']], ['subject', 'body', 'to'], 'outlook.draft', 'auto'),
            $this->writeTool('outlook_send_draft', 'Envoie un brouillon Outlook existant. Cette action nécessite toujours une confirmation humaine.', ['message_id' => ['type' => 'string']], ['message_id'], 'outlook.send', 'confirm'),
        ];
    }

    /** @return array<string, list<string>> */
    protected function requiredScopes(): array
    {
        return ['outlook_search_messages' => ['Mail.ReadBasic'], 'outlook_get_message' => ['Mail.Read'], 'outlook_create_draft' => ['Mail.ReadWrite'], 'outlook_send_draft' => ['Mail.Send']];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context, MicrosoftGraphClient $graph): ToolResult
    {
        return match ($toolName) {
            'outlook_search_messages' => $this->searchMessages($graph, $params),
            'outlook_get_message' => $this->getMessage($graph, $params),
            'outlook_create_draft' => $this->createDraft($graph, $params),
            'outlook_send_draft' => $this->sendDraft($graph, $params),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour le module Outlook Microsoft 365."),
        };
    }

    private function searchMessages(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $top = min(50, max(1, (int) ($p['top'] ?? 10)));
        $messages = $g->collectPages('/me/messages', [
            '$search' => '"' . (string) $p['query'] . '"', '$top' => $top,
            '$select' => 'id,subject,from,toRecipients,receivedDateTime,webLink,isRead,hasAttachments',
        ], ['ConsistencyLevel' => 'eventual']);
        return ToolResult::ok(['messages' => $messages], count($messages) . ' message(s) trouvé(s)');
    }

    private function getMessage(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $message = $g->get('/me/messages/' . $this->id($p['message_id']), ['$select' => 'id,subject,body,from,toRecipients,ccRecipients,receivedDateTime,webLink,hasAttachments']);
        return ToolResult::ok(['message' => $message], 'Message Outlook récupéré.');
    }

    private function createDraft(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $body = [
            'subject' => (string) $p['subject'], 'body' => ['contentType' => 'HTML', 'content' => (string) $p['body']],
            'toRecipients' => $this->recipients($p['to'] ?? []), 'ccRecipients' => $this->recipients($p['cc'] ?? []),
        ];
        $draft = $g->post('/me/messages', $body);
        return ToolResult::ok(['draft' => $draft], 'Brouillon Outlook créé.');
    }

    private function sendDraft(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $g->post('/me/messages/' . $this->id($p['message_id']) . '/send');
        return ToolResult::ok(['message_id' => $p['message_id']], 'Brouillon Outlook envoyé.');
    }

    private function recipients(array $recipients): array
    {
        return array_values(array_filter(array_map(function ($value) {
            $address = is_array($value) ? ($value['email'] ?? $value['address'] ?? null) : $value;
            return is_string($address) && filter_var($address, FILTER_VALIDATE_EMAIL) ? ['emailAddress' => ['address' => $address]] : null;
        }, $recipients)));
    }
}
