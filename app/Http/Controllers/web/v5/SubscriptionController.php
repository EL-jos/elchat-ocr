<?php

namespace App\Http\Controllers\web\v5;

use App\Http\Controllers\Controller;
use App\Models\Payment\Plan;
use App\Services\payment\CurrencyService;
use App\Services\payment\StripeService;
use App\Traits\JwtAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    use JwtAuth; // ← Remplace Auth::user() et $user->account partout

    public function __construct(
        private StripeService   $stripeService,
        private CurrencyService $currencyService
    ) {}

    // ─── Checkout Stripe ──────────────────────────────────────────────────────

    /**
     * POST /subscribe/{planSlug}
     *
     * AVANT : $user = Auth::user(); $account = $user->account;
     * APRÈS : $user = $this->jwtUser($request); $account = $this->jwtAccount($request);
     */
    public function checkout(Request $request, string $planSlug): RedirectResponse
    {
        $request->validate(['billing_cycle' => ['required', 'in:monthly,annual']]);

        // ✅ JWT — plus de Auth::user()
        $user    = $this->jwtUser($request);
        $account = $this->jwtAccount($request);

        if (!$user) {
            return redirect()->route('auth.login')
                ->with('error', 'Veuillez vous connecter.');
        }

        if (!$account) {
            return redirect()->route('auth.register')
                ->with('error', 'Aucun compte trouvé.');
        }

        $plan = Plan::where('slug', $planSlug)->where('is_active', true)->first();

        if (!$plan) {
            return redirect()->route('tarifs')->with('error', 'Plan introuvable.');
        }

        if ($plan->is_enterprise) {
            return redirect()->route('tarifs')->with(
                'info',
                'Pour l\'offre Enterprise, contactez-nous à ' . config('stripe.enterprise_email')
            );
        }

        $billingCycle = $request->billing_cycle;

        if (!$plan->getStripePriceId($billingCycle)) {
            return redirect()->route('tarifs')->with(
                'error',
                'Ce plan n\'est pas encore disponible. Réessayez dans quelques instants.'
            );
        }

        $currency = $this->currencyService->detectCurrencyFromIp($request->ip());

        try {
            $session = $this->stripeService->createCheckoutSession($account, $plan, $billingCycle, $currency);
            return redirect($session->url);
        } catch (\Exception $e) {
            Log::error('SubscriptionController: Checkout failed', [
                'account_id' => $account->id,
                'error'      => $e->getMessage(),
            ]);
            return redirect()->route('tarifs')->with(
                'error', 'Une erreur est survenue lors du paiement. Veuillez réessayer.'
            );
        }
    }

    /**
     * GET /payment/success
     */
    public function success(Request $request)
    {
        $provider = $request->input('provider', 'stripe');

        if ($provider === 'paypal') {
            return app(PayPalController::class)->success($request);
        }

        $sessionId = $request->input('session_id');
        if (!$sessionId) return redirect('/app');

        try {
            $session      = $this->stripeService->retrieveCheckoutSession($sessionId);
            $planSlug     = $session->metadata->plan_slug     ?? 'starter';
            $billingCycle = $session->metadata->billing_cycle ?? 'monthly';
            $plan         = Plan::where('slug', $planSlug)->first();

            return view('pages.payment-success', [
                'plan'         => $plan,
                'billingCycle' => $billingCycle,
                'provider'     => 'stripe',
                'session'      => $session,
            ]);
        } catch (\Exception $e) {
            Log::error('SubscriptionController: Success page failed', ['error' => $e->getMessage()]);
            return redirect('/app');
        }
    }

    /**
     * POST /billing/portal
     */
    public function portal(Request $request): RedirectResponse
    {
        // ✅ JWT
        $account = $this->jwtAccount($request);

        if (!$account) return redirect()->route('tarifs');

        try {
            $session = $this->stripeService->createBillingPortalSession($account);
            return redirect($session->url);
        } catch (\Exception $e) {
            Log::error('SubscriptionController: Billing portal failed', ['error' => $e->getMessage()]);
            return back()->with('error', 'Impossible d\'accéder au portail de facturation.');
        }
    }

    /**
     * GET /api/subscription
     * Consommé par Angular via HTTP avec le Bearer token.
     */
    public function current(Request $request)
    {
        // ✅ JWT
        $account      = $this->jwtAccount($request);
        $subscription = $account?->subscription?->load('plan');

        if (!$subscription) {
            return response()->json(['subscription' => null]);
        }

        return response()->json([
            'subscription' => [
                'status'               => $subscription->status,
                'plan'                 => $subscription->plan?->slug,
                'plan_name'            => $subscription->plan?->name,
                'billing_cycle'        => $subscription->billing_cycle,
                'is_active'            => $subscription->isActive(),
                'is_trialing'          => $subscription->isTrialing(),
                'trial_days_remaining' => $subscription->trialDaysRemaining(),
                'trial_ends_at'        => $subscription->trial_ends_at?->toIso8601String(),
                'current_period_end'   => $subscription->current_period_end?->toIso8601String(),
                'days_until_renewal'   => $subscription->daysUntilRenewal(),
                'provider'             => $subscription->payment_provider,
                'limits'               => [
                    'max_sites'                    => $subscription->plan?->max_sites,
                    'max_social_networks_per_site' => $subscription->plan?->max_social_networks_per_site,
                    'max_messages_per_month'       => $subscription->plan?->max_messages_per_month,
                    'max_chunks'                   => $subscription->plan?->max_chunks,
                    'max_tokens'                   => $subscription->plan?->max_tokens,
                ],
            ],
        ]);
    }
}
