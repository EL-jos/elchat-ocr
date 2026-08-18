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
        // Debug : recalcule manuellement avec le secret en dur
        $secret = config('services.facebook.app_secret');
        $manual = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        $signature = $request->header('X-Hub-Signature-256');
        $received = $signature;

        Log::debug('[Meta][RAW CHECK]', [
            'payload_first_50'  => substr($payload, 0, 50),
            'payload_last_50'   => substr($payload, -50),
            'payload_bytes'     => bin2hex(substr($payload, 0, 20)), // pour détecter BOM ou encoding
            'received'          => $received,
            'manual_expected'   => $manual,
            'match'             => hash_equals($manual, $received ?? ''),
            'secret_length'     => strlen($secret),
            'secret_first4'     => substr($secret, 0, 4),
            'secret_last4'      => substr($secret, -4), // pour croiser avec Meta dashboard
        ]);

        // ✅ Décoder d'abord pour connaître le provider AVANT la vérification
        // (nécessaire pour choisir le bon secret)
        try {
            $body = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            Log::warning('[Meta][Webhook] Payload JSON invalide', ['ip' => $request->ip()]);
            return response()->json(['success' => true]); // 200 quand même
        }

        $object   = $body['object'] ?? 'unknown';
        $provider = $object === 'instagram' ? 'instagram' : 'facebook';

        // ✅ Vérification avec le bon secret selon le provider
        if (!$this->security->isValid($payload, $signature, $provider)) {

            Log::warning('[Meta][Webhook] Signature invalide', [
                'provider'   => $provider,
                'ip'         => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid signature',
            ], 403);
        }

        Log::info('[Meta][Webhook] Event reçu', [
            'object' => $object,
            'ip'     => $request->ip(),
        ]);

        try {

            match ($object) {
                'page'      => $this->handleFacebook($body, $payload, $request),
                'instagram' => $this->handleInstagram($body, $payload, $request),
                default     => Log::warning('[Meta][Webhook] Object inconnu ignoré', [
                    'object' => $object,
                ]),
            };

        } catch (Throwable $e) {

            report($e);
            // Meta doit toujours recevoir 200
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
