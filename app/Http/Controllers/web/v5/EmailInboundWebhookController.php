<?php

namespace App\Http\Controllers\web\v5;

use App\Domain\Email\EmailService;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundProspectReplyJob;
use App\Models\Sales\Prospect;
use App\Models\Sales\ProspectMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Remplace la version Phase 1 (qui lisait $request->input() en dur) —
 * passe désormais entièrement par EmailService, agnostique du fournisseur.
 */
class EmailInboundWebhookController extends Controller
{
    public function __construct(private readonly EmailService $emailService)
    {
    }

    public function handle(Request $request)
    {
        $handshake = $this->emailService->handleWebhookHandshake($request);
        if ($handshake) {
            return response()->json($handshake, 200);
        }

        if (!$this->emailService->verifyInboundWebhookSignature($request)) {
            Log::warning('EmailInboundWebhook: signature invalide, requête rejetée.');
            return response()->json(['status' => 'invalid_signature'], 403);
        }

        $inbound = $this->emailService->parseInboundWebhook($request);
        if (!$inbound) {
            return response()->json(['status' => 'ignored'], 200);
        }

        $prospect = $this->matchProspect($inbound->to, $inbound->subject, $inbound->from);
        if (!$prospect) {
            Log::warning('EmailInboundWebhook: aucun prospect trouvé.', ['to' => $inbound->to, 'subject' => $inbound->subject]);
            return response()->json(['status' => 'ignored'], 200);
        }

        $message = ProspectMessage::create([
            'id' => (string) Str::uuid(), 'prospect_id' => $prospect->id,
            'channel' => 'email', 'direction' => 'inbound', 'status' => 'received',
            'content' => $inbound->textBody,
            'external_message_id' => $inbound->providerMessageId,
            'in_reply_to_external_id' => $inbound->inReplyTo,
        ]);

        ProcessInboundProspectReplyJob::dispatch($message->id);

        return response()->json(['status' => 'accepted'], 200);
    }

    private function matchProspect(string $to, string $subject, string $from): ?Prospect
    {
        if (preg_match('/\+([a-f0-9\-]{36})@/i', $to, $m)) {
            $prospect = Prospect::find($m[1]);
            if ($prospect) return $prospect;
        }

        if (preg_match('/\[PID:([a-f0-9]{8})\]/i', $subject, $m)) {
            $prospect = Prospect::where('id', 'like', "{$m[1]}%")->first();
            if ($prospect) return $prospect;
        }

        return Prospect::where('email', $from)->whereNotNull('conversation_id')->first();
    }
}
