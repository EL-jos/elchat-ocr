<?php

namespace App\Services\payment;

use App\Mail\SubscriptionConfirmed;
use App\Models\Account;
use App\Models\Payment\Plan;
use App\Models\Payment\Subscription;
use App\Models\Payment\SubscriptionEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\Subscription as StripeSubscription;

class SubscriptionService
{
    public function __construct(private StripeService $stripeService) {}

    // ─── Création trial ───────────────────────────────────────────────────────

    /**
     * Créer un abonnement trial Starter lors de l'inscription.
     * Appelé automatiquement après la création d'un Account.
     */
    public function createTrialSubscription(Account $account): Subscription
    {
        $starterPlan = Plan::where('slug', 'starter')->first();

        if (!$starterPlan) {
            throw new \RuntimeException('Plan Starter introuvable. Exécutez php artisan db:seed --class=PlanSeeder');
        }

        $trialDays = config('stripe.trial_days', 7);

        return DB::transaction(function () use ($account, $starterPlan, $trialDays) {
            $subscription = Subscription::create([
                'account_id'    => $account->id,
                'plan_id'       => $starterPlan->id,
                'status'        => 'trialing',
                'billing_cycle' => 'monthly',
                'trial_ends_at' => now()->addDays($trialDays),
            ]);

            Log::info('SubscriptionService: Trial created', [
                'account_id'    => $account->id,
                'trial_ends_at' => $subscription->trial_ends_at,
                'trial_days'    => $trialDays,
            ]);

            return $subscription;
        });
    }

    // ─── Gestion des webhooks Stripe ──────────────────────────────────────────

    /**
     * Traiter un événement Stripe reçu via webhook.
     * Point d'entrée central — dispatche vers les handlers spécialisés.
     */
    public function handleStripeEvent(\Stripe\Event $event): void
    {
        // Idempotence : ignorer si déjà traité
        if (SubscriptionEvent::where('stripe_event_id', $event->id)->exists()) {
            Log::info('SubscriptionService: Event already processed', ['event_id' => $event->id]);
            return;
        }

        $auditRecord = null;

        try {
            $auditRecord = SubscriptionEvent::create([
                'stripe_event_id'   => $event->id,
                'event_type'        => $event->type,
                'payload'           => $event->toArray(),
                'stripe_created_at' => \Carbon\Carbon::createFromTimestamp($event->created),
                'status'            => 'processed',
            ]);

            match ($event->type) {
                'checkout.session.completed'        => $this->handleCheckoutCompleted($event->data->object),
                'customer.subscription.updated'     => $this->handleSubscriptionUpdated($event->data->object),
                'customer.subscription.deleted'     => $this->handleSubscriptionDeleted($event->data->object),
                'invoice.payment_succeeded'         => $this->handlePaymentSucceeded($event->data->object),
                'invoice.payment_failed'            => $this->handlePaymentFailed($event->data->object),
                default                             => $this->logIgnoredEvent($event->type),
            };

        } catch (\Exception $e) {
            Log::error('SubscriptionService: Webhook handler failed', [
                'event_id'   => $event->id,
                'event_type' => $event->type,
                'error'      => $e->getMessage(),
            ]);

            if ($auditRecord) {
                $auditRecord->update([
                    'status'        => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    // ─── Handlers d'événements ────────────────────────────────────────────────

    private function handleCheckoutCompleted(\Stripe\Checkout\Session $session): void
    {
        $accountId    = $session->metadata->account_id ?? null;
        $planId       = $session->metadata->plan_id ?? null;
        $billingCycle = $session->metadata->billing_cycle ?? 'monthly';

        if (!$accountId || !$planId) {
            Log::error('SubscriptionService: Missing metadata in checkout session', [
                'session_id' => $session->id,
            ]);
            return;
        }

        $account = Account::find($accountId);
        $plan    = Plan::find($planId);

        if (!$account || !$plan) {
            Log::error('SubscriptionService: Account or Plan not found', [
                'account_id' => $accountId,
                'plan_id'    => $planId,
            ]);
            return;
        }

        // Récupérer la subscription Stripe complète
        $stripeSubscription = $this->stripeService->retrieveSubscription($session->subscription);

        DB::transaction(function () use ($account, $plan, $stripeSubscription, $billingCycle, $session) {
            $subscription = $account->subscription;

            $data = [
                'plan_id'                 => $plan->id,
                'stripe_customer_id'      => $stripeSubscription->customer,
                'stripe_subscription_id'  => $stripeSubscription->id,
                'stripe_price_id'         => $stripeSubscription->items->data[0]->price->id ?? null,
                'billing_cycle'           => $billingCycle,
                'status'                  => $stripeSubscription->status,
                'trial_ends_at'           => $stripeSubscription->trial_end
                    ? \Carbon\Carbon::createFromTimestamp($stripeSubscription->trial_end)
                    : null,
                'current_period_start'    => \Carbon\Carbon::createFromTimestamp($stripeSubscription->current_period_start),
                'current_period_end'      => \Carbon\Carbon::createFromTimestamp($stripeSubscription->current_period_end),
                'currency'                => $stripeSubscription->currency,
                'amount'                  => $stripeSubscription->items->data[0]->price->unit_amount ?? null,
            ];

            if ($subscription) {
                $subscription->update($data);
            } else {
                Subscription::create(array_merge(['account_id' => $account->id], $data));
                $subscription = $account->fresh()->subscription;
            }

            // Mettre à jour l'event d'audit avec les IDs
            SubscriptionEvent::where('stripe_event_id', 'like', '%checkout%')
                ->latest()
                ->first()
                ?->update([
                    'subscription_id' => $subscription->id,
                    'account_id'      => $account->id,
                ]);
        });

        // Envoyer l'email de confirmation
        $this->sendConfirmationEmail($account->fresh());

        Log::info('SubscriptionService: Checkout completed', [
            'account_id' => $accountId,
            'plan'       => $plan->slug,
            'cycle'      => $billingCycle,
        ]);
    }

    private function handleSubscriptionUpdated(StripeSubscription $stripeSubscription): void
    {
        $subscription = Subscription::where('stripe_subscription_id', $stripeSubscription->id)->first();

        if (!$subscription) {
            Log::warning('SubscriptionService: Subscription not found for update', [
                'stripe_subscription_id' => $stripeSubscription->id,
            ]);
            return;
        }

        $subscription->update([
            'status'               => $stripeSubscription->status,
            'current_period_start' => \Carbon\Carbon::createFromTimestamp($stripeSubscription->current_period_start),
            'current_period_end'   => \Carbon\Carbon::createFromTimestamp($stripeSubscription->current_period_end),
            'canceled_at'          => $stripeSubscription->canceled_at
                ? \Carbon\Carbon::createFromTimestamp($stripeSubscription->canceled_at)
                : null,
            'ends_at'              => $stripeSubscription->cancel_at
                ? \Carbon\Carbon::createFromTimestamp($stripeSubscription->cancel_at)
                : null,
        ]);

        Log::info('SubscriptionService: Subscription updated', [
            'subscription_id' => $subscription->id,
            'new_status'      => $stripeSubscription->status,
        ]);
    }

    private function handleSubscriptionDeleted(StripeSubscription $stripeSubscription): void
    {
        $subscription = Subscription::where('stripe_subscription_id', $stripeSubscription->id)->first();

        if (!$subscription) return;

        $subscription->update([
            'status'     => 'canceled',
            'ends_at'    => now(),
            'canceled_at'=> now(),
        ]);

        Log::info('SubscriptionService: Subscription deleted/canceled', [
            'subscription_id' => $subscription->id,
        ]);
    }

    private function handlePaymentSucceeded(\Stripe\Invoice $invoice): void
    {
        $subscription = Subscription::where('stripe_subscription_id', $invoice->subscription)->first();

        if (!$subscription) return;

        // Réactiver si past_due
        if ($subscription->status === 'past_due') {
            $subscription->update(['status' => 'active']);
        }

        Log::info('SubscriptionService: Payment succeeded', [
            'subscription_id' => $subscription->id,
            'amount'          => $invoice->amount_paid,
            'currency'        => $invoice->currency,
        ]);
    }

    private function handlePaymentFailed(\Stripe\Invoice $invoice): void
    {
        $subscription = Subscription::where('stripe_subscription_id', $invoice->subscription)->first();

        if (!$subscription) return;

        $subscription->update(['status' => 'past_due']);

        Log::warning('SubscriptionService: Payment failed', [
            'subscription_id' => $subscription->id,
            'amount'          => $invoice->amount_due,
        ]);

        // TODO: Envoyer un email d'alerte paiement échoué
    }

    private function logIgnoredEvent(string $eventType): void
    {
        // Mettre à jour le dernier audit record comme "ignoré"
        SubscriptionEvent::latest()->first()?->update(['status' => 'ignored']);
        Log::debug('SubscriptionService: Event ignored', ['type' => $eventType]);
    }

    // ─── Emails ───────────────────────────────────────────────────────────────

    private function sendConfirmationEmail(Account $account): void
    {
        try {
            $user = $account->owner;
            if ($user && $user->email) {
                Mail::to($user->email)->send(new SubscriptionConfirmed($account));
                Log::info('SubscriptionService: Confirmation email sent', ['email' => $user->email]);
            }
        } catch (\Exception $e) {
            // Ne pas faire planter le webhook si l'email échoue
            Log::error('SubscriptionService: Failed to send confirmation email', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
