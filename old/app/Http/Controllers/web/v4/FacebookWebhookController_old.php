<?php

namespace App\Http\Controllers\web\v4;

use App\Http\Controllers\Controller;
use App\Jobs\social\FacebookWebhookJob;
use App\Jobs\social\InstagramWebhookJob;
use App\Models\Social\SocialEvent;
use App\Services\Social\Facebook\FacebookWebhookSecurityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

class FacebookWebhookController extends Controller
{
    public function __construct(
        protected FacebookWebhookSecurityService $security
    ) {}

    /**
     * Meta verification (Facebook + Instagram)
     */
    public function verify(Request $request)
    {
        $mode      = $request->query('hub_mode')         ?? $request->query('hub.mode');
        $token     = $request->query('hub_verify_token') ?? $request->query('hub.verify_token');
        $challenge = $request->query('hub_challenge')    ?? $request->query('hub.challenge');

        Log::info('[Meta][Webhook] VERIFY', [
            'mode'      => $mode,
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
     * Receive events (Facebook + Instagram)
     *
     * Meta envoie TOUT sur le même endpoint webhook,
     * qu'il s'agisse de Facebook (object=page) ou
     * d'Instagram (object=instagram). On route ici.
     */
    public function handle(Request $request)
    {
        $payload   = $request->getContent();
        $signature = $request->header('X-Hub-Signature-256');

        if (!$this->security->isValid($payload, $signature)) {

            Log::warning('[Meta][Webhook] Signature invalide', [
                'ip'         => $request->ip(),
                'user_agent' => $request->userAgent(),
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

            $object = $body['object'] ?? 'unknown';

            Log::info('[Meta][Webhook] Event reçu', [
                'object' => $object,
                'ip'     => $request->ip(),
            ]);

            // ✅ Router selon le type d'objet Meta
            match ($object) {
                'page'      => $this->handleFacebook($body, $payload, $request),
                'instagram' => $this->handleInstagram($body, $payload, $request),
                default     => Log::warning('[Meta][Webhook] Object inconnu ignoré', [
                    'object' => $object,
                ]),
            };

        } catch (Throwable $e) {

            report($e);

            // ✅ Important : Meta doit toujours recevoir 200,
            // sinon il retente indéfiniment.
        }

        return response()->json(['success' => true]);
    }

    // ─────────────────────────────────────────────────────────
    // FACEBOOK (object = page)
    // ─────────────────────────────────────────────────────────

    private function handleFacebook(array $body, string $rawPayload, Request $request): void
    {
        $event = SocialEvent::create([
            'provider'          => 'facebook',
            'event_type'        => $body['object'] ?? 'unknown',
            'external_event_id' => null,
            'payload'           => $body,
            'processing_status' => 'pending',
            'metadata' => [
                'received_at'  => now()->toISOString(),
                'ip'           => $request->ip(),
                'user_agent'   => $request->userAgent(),
                'payload_hash' => hash('sha256', $rawPayload),
            ],
        ]);

        FacebookWebhookJob::dispatch($event->id);

        Log::info('[Facebook][Webhook] Event créé', ['event_id' => $event->id]);
    }

    // ─────────────────────────────────────────────────────────
    // INSTAGRAM (object = instagram)
    // ─────────────────────────────────────────────────────────

    private function handleInstagram(array $body, string $rawPayload, Request $request): void
    {
        $event = SocialEvent::create([
            'provider'          => 'instagram',
            'event_type'        => 'instagram',
            'external_event_id' => null,
            'payload'           => $body,
            'processing_status' => 'pending',
            'metadata' => [
                'received_at'  => now()->toISOString(),
                'ip'           => $request->ip(),
                'user_agent'   => $request->userAgent(),
                'payload_hash' => hash('sha256', $rawPayload),
            ],
        ]);

        InstagramWebhookJob::dispatch($event->id);

        Log::info('[Instagram][Webhook] Event créé', ['event_id' => $event->id]);
    }
}
