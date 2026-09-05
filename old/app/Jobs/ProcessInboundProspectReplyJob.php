<?php

namespace App\Jobs;
use romanzipp\QueueMonitor\Traits\IsMonitored;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Sales\ProspectMessage;
use App\Services\mcp\MCPActionGateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Classification d'intention légère (mots-clés — pas d'appel LLM pour ce
 * garde-fou précis) : une demande "ne plus être contacté" est traitée de
 * façon DÉTERMINISTE et immédiate, jamais laissée à l'appréciation du LLM
 * (§15 du cahier des charges — irréversible, sécurité avant tout). Pour
 * toute autre intention, l'agent Sales Hunter est invoqué normalement.
 */
class ProcessInboundProspectReplyJob implements ShouldQueue
{
    use IsMonitored;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const UNSUBSCRIBE_MARKERS = [
        'ne plus me contacter', 'ne plus être contacté', 'désinscri', 'stop', 'unsubscribe', 'ne plus recevoir',
    ];

    public function __construct(private readonly string $prospectMessageId)
    {
    }

    public function handle(MCPActionGateService $gate): void
    {
        $inbound = ProspectMessage::with('prospect.site', 'prospect.campaign.config.agent')->findOrFail($this->prospectMessageId);
        $prospect = $inbound->prospect;

        if ($this->looksLikeUnsubscribe($inbound->content)) {
            $prospect->update(['status' => 'do_not_contact']);
            $inbound->update(['intent' => 'unsubscribe']);
            return; // aucune invocation LLM — le blocage doit être immédiat et certain
        }

        $conversation = Conversation::find($prospect->conversation_id);
        $message = Message::create([
            'id' => (string) Str::uuid(), 'conversation_id' => $conversation->id,
            'user_id' => null, 'role' => 'user', 'content' => $inbound->content,
        ]);
        $inbound->update(['message_id' => $message->id]);
        $prospect->update(['status' => 'replied']);
        $prospect->touchActivity();

        $history = Message::where('conversation_id', $conversation->id)
            ->orderBy('created_at', 'desc')->take(6)->get()->reverse()
            ->map(fn ($m) => ['role' => $m->role === 'bot' ? 'assistant' : 'user', 'content' => $m->content])
            ->toArray();

        $gate->runForAgent(
            site: $prospect->site,
            conversation: $conversation,
            agent: $prospect->campaign->config->agent,
            question: $inbound->content,
            history: $history,
        );
    }

    private function looksLikeUnsubscribe(string $content): bool
    {
        $normalized = mb_strtolower($content);
        foreach (self::UNSUBSCRIBE_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) return true;
        }
        return false;
    }
}
