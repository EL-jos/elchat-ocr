<?php

namespace App\Services\WebsiteGrowthAdvisor;

use App\Models\Conversation;
use App\Models\Mcp\McpPendingAction;
use Illuminate\Support\Facades\DB;

/** Keeps the MCP audit trail while removing the technical conversation. */
final class WebsiteGrowthAdvisorConversationCleanupService
{
    public const TEMPORARY_MARKER = 'website_growth_advisor_temporary';

    public function temporaryMetadata(): array
    {
        return [self::TEMPORARY_MARKER => true, 'context' => 'website_growth_advisor'];
    }

    public function cleanup(?Conversation $conversation): void
    {
        if (! $conversation || data_get($conversation->metadata, self::TEMPORARY_MARKER) !== true) return;

        $conversationId = (string) $conversation->getKey();
        if (McpPendingAction::query()->where('conversation_id', $conversationId)->where('status', 'pending')->exists()) return;

        DB::transaction(function () use ($conversation, $conversationId): void {
            DB::table('mcp_audit_logs')->where('conversation_id', $conversationId)->update(['conversation_id' => null]);
            DB::table('resource_events')->where('conversation_id', $conversationId)->update(['conversation_id' => null]);
            $conversation->delete();
        });
    }
}
