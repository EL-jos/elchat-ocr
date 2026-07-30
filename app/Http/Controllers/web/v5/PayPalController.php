<?php

namespace App\Http\Controllers\web\v5;

use App\Http\Controllers\Controller;
use App\Models\Payment\Plan;
use App\Services\payment\PayPalSubscriptionService;
use App\Traits\JwtAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PayPalController extends Controller
{
    use JwtAuth; // ← Remplace Auth::user() partout

    public function __construct(private PayPalSubscriptionService $paypalService) {}

    /**
     * POST /paypal/subscribe/{planSlug}
     *
     * AVANT : $user = Auth::user(); $account = $user->account;
     * APRÈS : JWT via trait
     */
    public function checkout(Request $request, string $planSlug): RedirectResponse
    {
        $request->validate(['billing_cycle' => ['required', 'in:monthly,annual']]);

        // ✅ JWT
        $user    = $this->jwtUser($request);
        $account = $this->jwtAccount($request);

        if (!$user) {
            return redirect()->route('auth.login')->with('error', 'Veuillez vous connecter.');
        }

        if (!$account) {
            return redirect()->route('auth.register')->with('error', 'Aucun compte trouvé.');
        }

        $plan = Plan::where('slug', $planSlug)->where('is_active', true)->first();

        if (!$plan) {
            return redirect()->route('tarifs')->with('error', 'Plan introuvable.');
        }

        if ($plan->is_enterprise) {
            return redirect()->route('tarifs')->with(
                'info', 'Pour l\'offre Enterprise, contactez-nous à ' . config('stripe.enterprise_email')
            );
        }

        $billingCycle = $request->billing_cycle;

        if (!$plan->getPayPalPlanId($billingCycle)) {
            return redirect()->route('tarifs')->with(
                'error', 'PayPal n\'est pas encore disponible pour ce plan. Utilisez le paiement par carte.'
            );
        }

        try {
            $approvalUrl = $this->paypalService->initiate($account, $plan, $billingCycle);
            return redirect($approvalUrl);
        } catch (\Exception $e) {
            Log::error('PayPalController: Checkout failed', [
                'account_id' => $account->id,
                'error'      => $e->getMessage(),
            ]);
            return redirect()->route('tarifs')->with(
                'error', 'Une erreur est survenue avec PayPal. Veuillez réessayer ou payer par carte.'
            );
        }
    }

    /**
     * GET /paypal/success
     */
    public function success(Request $request)
    {
        $paypalSubscriptionId = $request->input('subscription_id');
        $accountId            = $request->input('account_id');
        $planId               = $request->input('plan_id');
        $billingCycle         = $request->input('cycle', 'monthly');

        if (!$paypalSubscriptionId) {
            Log::warning('PayPalController: Missing subscription_id', $request->all());
            return redirect('/app');
        }

        try {
            $subscription = $this->paypalService->capture(
                $paypalSubscriptionId,
                $accountId,
                $planId,
                $billingCycle
            );

            return view('pages.payment-success', [
                'plan'         => $subscription->plan,
                'billingCycle' => $subscription->billing_cycle,
                'provider'     => 'paypal',
                'subscription' => $subscription,
            ]);
        } catch (\Exception $e) {
            Log::error('PayPalController: Success capture failed', [
                'paypal_sub_id' => $paypalSubscriptionId,
                'error'         => $e->getMessage(),
            ]);
            return redirect('/app');
        }
    }
}
