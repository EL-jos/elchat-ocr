<?php

namespace App\Http\Controllers\api\v5;

use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Http\Controllers\Controller;
use App\Models\Payment\PaymentEvent;
use App\Models\Payment\Subscription;
use App\Payment\Gateways\PaypalPaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaypalWebhookController extends Controller
{
    public function __construct(private PaypalPaymentGateway $gateway) {}

    /**
     * POST /webhooks/paypal
     * Route exclue du CSRF/session — voir routes/web.php
     */
    public function handle(Request $request): Response
    {
        $payload = $request->getContent();
        $headers = [
            'paypal-auth-algo'         => $request->header('paypal-auth-algo', ''),
            'paypal-cert-url'          => $request->header('paypal-cert-url', ''),
            'paypal-transmission-id'   => $request->header('paypal-transmission-id', ''),
            'paypal-transmission-sig'  => $request->header('paypal-transmission-sig', ''),
            'paypal-transmission-time' => $request->header('paypal-transmission-time', ''),
        ];

        $event = json_decode($payload, true);

        if (!$event || !isset($event['id'])) {
            return response('Invalid payload.', 400);
        }

        // Idempotence
        if (PaymentEvent::where('provider_event_id', $event['id'])->exists()) {
            return response('Already processed.', 200);
        }

        $snapshot = $this->gateway->handleWebhook($payload, $headers);

        if (!$snapshot) {
            $this->logEvent($event, null, 'failed', 'Signature verification failed or unparseable payload.');
            return response('Signature verification failed.', 400);
        }

        $subscription = Subscription::where('provider_subscription_id', $snapshot->providerSubscriptionId)->first();

        try {
            DB::transaction(function () use ($subscription, $snapshot, $event) {
                if ($subscription) {
                    $subscription->update([
                        'status'                => $snapshot->status,
                        'provider_customer_id'  => $snapshot->providerCustomerId ?? $subscription->provider_customer_id,
                        'current_period_end'    => $snapshot->currentPeriodEnd
                            ? \DateTime::createFromImmutable($snapshot->currentPeriodEnd)
                            : $subscription->current_period_end,
                    ]);

                    if ($event['event_type'] === 'PAYMENT.SALE.COMPLETED') {
                        event(new PaymentSucceeded($subscription, $snapshot->totalAmountCents));
                    }

                    if (in_array($event['event_type'], ['BILLING.SUBSCRIPTION.PAYMENT.FAILED'])) {
                        event(new PaymentFailed($subscription));
                    }
                }

                $this->logEvent($event, $subscription?->id, 'processed');
            });

            return response('Webhook handled.', 200);

        } catch (\Throwable $e) {
            Log::error('PaypalWebhookController: processing failed', ['error' => $e->getMessage()]);
            $this->logEvent($event, $subscription?->id, 'failed', $e->getMessage());
            return response('Processing failed.', 500);
        }
    }

    private function logEvent(array $event, ?string $subscriptionId, string $status, ?string $error = null): void
    {
        PaymentEvent::create([
            'subscription_id'    => $subscriptionId,
            'account_id'          => $subscriptionId ? Subscription::find($subscriptionId)?->account_id : null,
            'provider'            => 'paypal',
            'provider_event_id'   => $event['id'],
            'event_type'          => $event['event_type'] ?? 'unknown',
            'payload'             => $event,
            'status'              => $status,
            'error_message'       => $error,
        ]);
    }
}
