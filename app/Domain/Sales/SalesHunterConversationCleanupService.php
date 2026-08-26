<?php

namespace App\Domain\Sales;

use App\Models\Conversation;
use App\Models\Mcp\McpPendingAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Nettoie les contextes MCP créés exclusivement pour une campagne Sales Hunter. */
final class SalesHunterConversationCleanupService
{
    public const TEMPORARY_MARKER = 'sales_hunter_temporary';

    public function temporaryMetadata(string $context, ?string $prospectId = null): array
    {
        return array_filter([
            self::TEMPORARY_MARKER => true,
            'sales_hunter_context' => $context,
            'prospect_id' => $prospectId,
        ], static fn ($value): bool => $value !== null);
    }

    public function isTemporary(?Conversation $conversation): bool
    {
        return $conversation !== null
            && data_get($conversation->metadata, self::TEMPORARY_MARKER) === true;
    }

    /**
     * Supprime uniquement un contexte explicitement marqué comme temporaire.
     * Une action MCP encore en attente doit conserver sa conversation pour
     * permettre sa résolution par un utilisateur.
     */
    public function cleanup(?Conversation $conversation): bool
    {
        if (! $this->isTemporary($conversation)) {
            return false;
        }

        $conversationId = (string) $conversation->getKey();
        if (McpPendingAction::query()
            ->where('conversation_id', $conversationId)
            ->where('status', 'pending')
            ->exists()) {
            Log::notice('Sales Hunter temporary conversation retained for pending MCP action', [
                'conversation_id' => $conversationId,
            ]);

            return false;
        }

        return DB::transaction(function () use ($conversation, $conversationId): bool {
            // Les traces d'audit restent disponibles, mais ne pointent plus
            // vers un contexte conversationnel qui vient d'être supprimé.
            DB::table('mcp_audit_logs')
                ->where('conversation_id', $conversationId)
                ->update(['conversation_id' => null]);
            DB::table('resource_events')
                ->where('conversation_id', $conversationId)
                ->update(['conversation_id' => null]);

            $conversation->delete();

            return true;
        });
    }
}
