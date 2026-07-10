<?php

namespace App\Http\Controllers\web\v4;

use App\Http\Controllers\Controller;
use App\Jobs\social\GmailWebhookJob;
use App\Jobs\social\OutlookWebhookJob;
use App\Models\Social\SocialAccount;
use App\Models\Social\SocialEvent;
use App\Services\Social\Email\OutlookSubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmailWebhookController extends Controller
{
    public function __construct(
        private OutlookSubscriptionService $outlookSubscription,
    ) {}

    // ─────────────────────────────────────────────────────────
    // GMAIL (Google Pub/Sub push)
    // ─────────────────────────────────────────────────────────

    public function handleGmail(Request $request)
    {
        // ✅ Google Pub/Sub envoie le payload en base64 dans message.data
        $rawPayload = $request->getContent();

        try {
            $body    = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
            $message = $body['message'] ?? null;

            if (!$message) {
                Log::warning('[Gmail][Webhook] Payload sans message', $body);
                return response()->json(['success' => true]); // 200 — évite les retries
            }

            // ✅ Décoder le data base64 (contient emailAddress + historyId)
            $data = json_decode(
                base64_decode(strtr($body['message']['data'] ?? '', '-_', '+/')),
                true
            );

            $email     = $data['emailAddress'] ?? null;
            $historyId = $data['historyId']    ?? null;

            if (!$email || !$historyId) {
                Log::warning('[Gmail][Webhook] Data incomplète', $data ?? []);
                return response()->json(['success' => true]);
            }

            $account = SocialAccount::where('provider', 'gmail')
                ->whereJsonContains('metadata->email', $email)
                ->where('is_active', true)
                ->first();

            if (!$account) {
                Log::warning('[Gmail][Webhook] Aucun compte Gmail trouvé', ['email' => $email]);
                return response()->json(['success' => true]);
            }

            // ✅ Déduplication via historyId
            $alreadyProcessed = SocialEvent::where('provider', 'gmail')
                ->where('external_event_id', (string) $historyId)
                ->where('social_account_id', $account->id)
                ->exists();

            if ($alreadyProcessed) {
                return response()->json(['success' => true]);
            }

            $event = SocialEvent::create([
                'social_account_id'  => $account->id,
                'provider'           => 'gmail',
                'event_type'         => 'email_received',
                'external_event_id'  => (string) $historyId,
                'processing_status'  => 'pending',
                'payload' => [
                    'email'      => $email,
                    'history_id' => $historyId,
                    'raw'        => $body,
                ],
                'metadata' => [
                    'received_at'  => now()->toISOString(),
                    'ip'           => $request->ip(),
                    'payload_hash' => hash('sha256', $rawPayload),
                ],
            ]);

            GmailWebhookJob::dispatch($event->id);

            Log::info('[Gmail][Webhook] Event créé', [
                'event_id'   => $event->id,
                'history_id' => $historyId,
                'email'      => $email,
            ]);

        } catch (Throwable $e) {
            report($e);
        }

        return response()->json(['success' => true]);
    }

    // ─────────────────────────────────────────────────────────
    // OUTLOOK (Microsoft Graph push)
    // ─────────────────────────────────────────────────────────

    public function handleOutlook(Request $request)
    {
        // ✅ Microsoft envoie une validation challenge au moment de la création
        // de la subscription — il faut répondre avec le validationToken en texte brut
        if ($request->has('validationToken')) {
            Log::info('[Outlook][Webhook] Validation challenge reçu');
            return response($request->query('validationToken'), 200)
                ->header('Content-Type', 'text/plain');
        }

        $rawPayload = $request->getContent();

        try {
            $body          = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
            $notifications = $body['value'] ?? [];

            foreach ($notifications as $notification) {

                $subscriptionId = $notification['subscriptionId']  ?? null;
                $clientState    = $notification['clientState']     ?? null;
                $resourceData   = $notification['resourceData']    ?? null;
                $messageId      = $resourceData['id']              ?? null;

                if (!$subscriptionId || !$messageId) {
                    continue;
                }

                // ✅ Retrouver le compte via subscription_id
                $account = SocialAccount::where('provider', 'outlook')
                    ->whereJsonContains('metadata->subscription_id', $subscriptionId)
                    ->where('is_active', true)
                    ->first();

                if (!$account) {
                    Log::warning('[Outlook][Webhook] Aucun compte trouvé', [
                        'subscription_id' => $subscriptionId,
                    ]);
                    continue;
                }

                // ✅ Vérifier le clientState pour valider l'authenticité
                $expectedState = $this->outlookSubscription->buildClientState($account);

                if (!hash_equals($expectedState, $clientState ?? '')) {
                    Log::warning('[Outlook][Webhook] ClientState invalide', [
                        'subscription_id' => $subscriptionId,
                        'account_id'      => $account->id,
                    ]);
                    continue;
                }

                // ✅ Déduplication via message_id
                $alreadyProcessed = SocialEvent::where('provider', 'outlook')
                    ->where('external_event_id', $messageId)
                    ->where('social_account_id', $account->id)
                    ->exists();

                if ($alreadyProcessed) {
                    continue;
                }

                $event = SocialEvent::create([
                    'social_account_id'  => $account->id,
                    'provider'           => 'outlook',
                    'event_type'         => 'email_received',
                    'external_event_id'  => $messageId,
                    'processing_status'  => 'pending',
                    'payload' => [
                        'message_id'      => $messageId,
                        'subscription_id' => $subscriptionId,
                        'raw'             => $notification,
                    ],
                    'metadata' => [
                        'received_at'  => now()->toISOString(),
                        'ip'           => $request->ip(),
                        'payload_hash' => hash('sha256', $rawPayload),
                    ],
                ]);

                OutlookWebhookJob::dispatch($event->id);

                Log::info('[Outlook][Webhook] Event créé', [
                    'event_id'   => $event->id,
                    'message_id' => $messageId,
                    'account_id' => $account->id,
                ]);
            }

        } catch (Throwable $e) {
            report($e);
        }

        // ✅ Microsoft attend 202 Accepted
        return response()->json(['success' => true], 202);
    }
}
