<?php

namespace App\Http\Controllers\web\v5;

use App\Http\Controllers\Controller;
use App\Services\payment\StripeService;
use App\Services\payment\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;

class StripeWebhookController extends Controller
{
    public function __construct(
        private StripeService      $stripeService,
        private SubscriptionService $subscriptionService
    ) {}

    /**
     * Point d'entrée unique pour tous les webhooks Stripe.
     * POST /stripe/webhook
     *
     * IMPORTANT : Cette route doit être exclue du middleware CSRF (voir routes/web.php).
     */
    public function handle(Request $request): Response
    {
        $payload   = $request->getContent();
        $signature = $request->header('Stripe-Signature');

        // 1. Vérifier la signature Stripe (sécurité essentielle)
        try {
            $event = $this->stripeService->constructWebhookEvent($payload, $signature);
        } catch (SignatureVerificationException $e) {
            Log::warning('StripeWebhookController: Invalid signature', [
                'error' => $e->getMessage(),
                'ip'    => $request->ip(),
            ]);
            return response('Invalid signature.', 400);
        } catch (\UnexpectedValueException $e) {
            Log::warning('StripeWebhookController: Invalid payload', ['error' => $e->getMessage()]);
            return response('Invalid payload.', 400);
        }

        // 2. Traiter l'événement
        try {
            $this->subscriptionService->handleStripeEvent($event);
            return response('Webhook handled.', 200);

        } catch (\Exception $e) {
            Log::error('StripeWebhookController: Handler threw exception', [
                'event_id'   => $event->id,
                'event_type' => $event->type,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            // Retourner 500 → Stripe retentera automatiquement
            return response('Webhook processing failed.', 500);
        }
    }
}
