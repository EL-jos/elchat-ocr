<?php

namespace App\Http\Controllers\web\v5;

use App\Http\Controllers\Controller;
use App\Services\payment\PayPalService;
use App\Services\payment\PayPalSubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class PayPalWebhookController extends Controller
{
    public function __construct(
        private PayPalService             $paypal,
        private PayPalSubscriptionService $subscriptionService
    ) {}

    /**
     * Point d'entrée unique pour tous les webhooks PayPal.
     * POST /paypal/webhook
     *
     * IMPORTANT : Route exclue du middleware CSRF (voir routes/web.php).
     * PayPal vérifie l'authenticité via sa propre signature dans les headers.
     */
    public function handle(Request $request): Response
    {
        $payload = $request->getContent();
        $headers = $this->extractPayPalHeaders($request);
        $event   = json_decode($payload, true);

        // 1. Valider le payload JSON
        if (!$event || !isset($event['event_type'])) {
            Log::warning('PayPalWebhookController: Invalid payload received', [
                'ip'      => $request->ip(),
                'payload' => substr($payload, 0, 200),
            ]);
            return response('Invalid payload.', 400);
        }

        // 2. Vérifier la signature PayPal
        $isValid = $this->paypal->verifyWebhookSignature($payload, $headers);

        if (!$isValid) {
            Log::warning('PayPalWebhookController: Signature verification failed', [
                'event_type' => $event['event_type'] ?? 'unknown',
                'ip'         => $request->ip(),
                'headers'    => $headers,
            ]);
            return response('Signature verification failed.', 400);
        }

        // 3. Traiter l'événement
        try {
            $this->subscriptionService->handleWebhookEvent($event);
            return response('Webhook handled.', 200);

        } catch (\Exception $e) {
            Log::error('PayPalWebhookController: Handler failed', [
                'event_type' => $event['event_type'] ?? 'unknown',
                'event_id'   => $event['id']         ?? 'unknown',
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            // Retourner 500 → PayPal retentera automatiquement
            return response('Webhook processing failed.', 500);
        }
    }

    /**
     * Extraire les headers PayPal nécessaires à la vérification de signature.
     */
    private function extractPayPalHeaders(Request $request): array
    {
        return [
            'paypal-auth-algo'        => $request->header('paypal-auth-algo', ''),
            'paypal-cert-url'         => $request->header('paypal-cert-url', ''),
            'paypal-transmission-id'  => $request->header('paypal-transmission-id', ''),
            'paypal-transmission-sig' => $request->header('paypal-transmission-sig', ''),
            'paypal-transmission-time'=> $request->header('paypal-transmission-time', ''),
        ];
    }
}
