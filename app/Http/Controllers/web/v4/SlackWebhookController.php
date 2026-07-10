<?php

namespace App\Http\Controllers\web\v4;

use App\Http\Controllers\Controller;
use App\Jobs\social\SlackWebhookJob;
use App\Models\Social\SocialEvent;
use App\Services\Social\Slack\SlackWebhookSecurityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class SlackWebhookController extends Controller
{
    public function __construct(
        protected SlackWebhookSecurityService $security
    ) {}

    /**
     * Receive events
     *
     * ⚠️ Slack n'a pas de verify() séparé en GET : le challenge
     * d'URL verification arrive DANS ce POST (type=url_verification).
     */
    public function handle(Request $request)
    {
        $payload   = $request->getContent();
        $signature = $request->header('X-Slack-Signature');
        $timestamp = $request->header('X-Slack-Request-Timestamp');

        if (!$this->security->isValid($payload, $signature, $timestamp)) {

            Log::warning('[Slack][Webhook] Signature invalide', [
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid signature',
            ], 403);
        }

        try {

            $body = json_decode(
                $payload,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            // ✅ URL Verification Challenge (one-time, lors de la
            // configuration de l'Event Subscription dans Slack App config)
            if (($body['type'] ?? null) === 'url_verification') {
                return response($body['challenge'], 200)
                    ->header('Content-Type', 'text/plain');
            }

            // ✅ Slack peut renvoyer le même event plusieurs fois
            // (retry réseau) — déduplication via event_id natif
            $externalEventId = $body['event_id'] ?? null;

            if ($externalEventId) {
                $alreadyExists = SocialEvent::where('provider', 'slack')
                    ->where('external_event_id', $externalEventId)
                    ->exists();

                if ($alreadyExists) {
                    Log::info('[Slack][Webhook] Event dupliqué ignoré', [
                        'event_id' => $externalEventId,
                    ]);
                    return response()->json(['success' => true]);
                }
            }

            $event = SocialEvent::create([
                'provider'           => 'slack',
                'event_type'         => $body['event']['type'] ?? ($body['type'] ?? 'unknown'),
                'external_event_id'  => $externalEventId,
                'payload'            => $body,
                'processing_status'  => 'pending',
                'metadata' => [
                    'received_at'  => now()->toISOString(),
                    'ip'           => $request->ip(),
                    'user_agent'   => $request->userAgent(),
                    'payload_hash' => hash('sha256', $payload),
                    'team_id'      => $body['team_id'] ?? null,
                ],
            ]);

            SlackWebhookJob::dispatch($event->id);

            Log::info('[Slack][Webhook] Event créé', ['event_id' => $event->id]);

        } catch (Throwable $e) {

            report($e);

            // ✅ Important : Slack retente après 3s si pas de 200.
            // On répond toujours 200 pour éviter un flood de retries
            // sur une erreur de parsing qu'on va investiguer via les logs.
        }

        // ✅ CRITIQUE : Slack exige une réponse sous 3 secondes,
        // sinon il considère l'event comme échoué et retente
        // (jusqu'à 3 fois, avec un risque de duplication).
        return response()->json(['success' => true]);
    }
}