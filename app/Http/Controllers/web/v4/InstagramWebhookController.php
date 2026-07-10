<?php

namespace App\Http\Controllers\web\v4;

use App\Http\Controllers\Controller;
use App\Jobs\social\InstagramWebhookJob;
use App\Models\Social\SocialEvent;
use App\Services\Social\Facebook\FacebookWebhookSecurityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class InstagramWebhookController extends Controller
{
    public function __construct(
        protected FacebookWebhookSecurityService $security
    ) {}

    /**
     * Meta verification
     *
     * ⚠️ Instagram (via Graph API) utilise le MÊME app_secret
     * que Facebook, car Instagram Business est rattaché à l'app Meta.
     */
    public function verify(Request $request)
    {
        $mode = $request->query('hub_mode')
            ?? $request->query('hub.mode');

        $token = $request->query('hub_verify_token')
            ?? $request->query('hub.verify_token');

        $challenge = $request->query('hub_challenge')
            ?? $request->query('hub.challenge');

        Log::info('[Instagram][Webhook] VERIFY', [
            'mode'      => $mode,
            'token'     => $token,
            'challenge' => $challenge,
        ]);

        if (
            $mode !== 'subscribe' ||
            $token !== config('services.facebook.webhook_verify_token')
        ) {
            abort(403);
        }

        return response($challenge, 200);
    }

    /**
     * Receive events
     */
    public function handle(Request $request)
    {
        $payload = $request->getContent();

        $signature = $request->header('X-Hub-Signature-256');

        if (!$this->security->isValid($payload, $signature)) {

            Log::warning('[Instagram][Webhook] Signature invalide', [
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

            $event = SocialEvent::create([

                'provider' => 'instagram',

                'event_type' => $body['object'] ?? 'unknown',

                'external_event_id' => null,

                'payload' => $body,

                'metadata' => [
                    'received_at'  => now()->toISOString(),
                    'ip'           => $request->ip(),
                    'user_agent'   => $request->userAgent(),
                    'payload_hash' => hash('sha256', $payload),
                ],

            ]);

            if ($event) {
                InstagramWebhookJob::dispatch($event->id);
            }

        } catch (Throwable $e) {

            report($e);

            /**
             * Important :
             * Meta doit recevoir 200
             * sinon il retente.
             */
        }

        return response()->json([
            'success' => true,
        ]);
    }
}
