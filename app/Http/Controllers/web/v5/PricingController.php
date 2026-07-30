<?php

namespace App\Http\Controllers\web\v5;

use App\Http\Controllers\Controller;
use App\Models\Payment\Plan;
use App\Services\payment\CurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PricingController extends Controller
{
    public function __construct(private CurrencyService $currencyService) {}

    /**
     * Page /tarifs — détecte la devise et affiche les plans.
     */
    public function index(Request $request)
    {
        $plans    = Plan::active()->get();
        $clientIp = $request->ip();
        $currency = $this->currencyService->detectCurrencyFromIp($clientIp);
        $rate     = $this->currencyService->getRate($currency);

        // Préparer les données de prix pour la vue
        $plansData = $plans->map(fn ($plan) => $this->formatPlanForView($plan, $currency, $rate));

        return view('pages.abonnements', [
            'plans'        => $plansData,
            'currency'     => strtoupper($currency),
            'currencyRate' => $rate,
            'trialDays'    => config('stripe.trial_days', 7),
        ]);
    }

    /**
     * API endpoint pour récupérer les taux en temps réel (AJAX).
     */
    public function getRates(Request $request): JsonResponse
    {
        $currency = strtolower($request->get('currency', 'eur'));
        $allowed  = ['eur', 'usd', 'gbp', 'cad', 'chf', 'mad'];

        if (!in_array($currency, $allowed)) {
            return response()->json(['error' => 'Devise non supportée'], 422);
        }

        $rate  = $this->currencyService->getRate($currency);
        $plans = Plan::active()->get();

        $data = $plans->map(fn ($plan) => [
            'slug'              => $plan->slug,
            'monthly_price'     => $this->formatPrice($plan->price_monthly_eur, $rate),
            'annual_price'      => $this->formatPrice($plan->price_annual_eur, $rate),
            'monthly_formatted' => $this->currencyService->format(
                (int) round($plan->price_monthly_eur * $rate), $currency
            ),
            'annual_formatted'  => $this->currencyService->format(
                (int) round($plan->price_annual_eur * $rate), $currency
            ),
            'annual_savings'    => $this->currencyService->format(
                (int) round(($plan->price_monthly_eur - $plan->price_annual_eur) * 12 * $rate), $currency
            ),
        ]);

        return response()->json([
            'currency' => strtoupper($currency),
            'rate'     => $rate,
            'plans'    => $data,
        ]);
    }

    /**
     * API endpoint pour détecter la devise de l'utilisateur.
     */
    public function detectCurrency(Request $request): JsonResponse
    {
        $ip       = $request->ip();
        $currency = $this->currencyService->detectCurrencyFromIp($ip);
        $rate     = $this->currencyService->getRate($currency);

        return response()->json([
            'currency' => strtoupper($currency),
            'rate'     => $rate,
        ]);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function formatPlanForView(Plan $plan, string $currency, float $rate): array
    {
        $monthlyConverted = (int) round($plan->price_monthly_eur * $rate);
        $annualConverted  = (int) round($plan->price_annual_eur * $rate);
        $savingsConverted = (int) round(($plan->price_monthly_eur - $plan->price_annual_eur) * 12 * $rate);

        return [
            'id'                          => $plan->id,
            'name'                        => $plan->name,
            'slug'                        => $plan->slug,
            'description'                 => $plan->description,
            'is_enterprise'               => $plan->is_enterprise,
            'has_sla'                     => $plan->has_sla,
            'has_white_label'             => $plan->has_white_label,
            'sort_order'                  => $plan->sort_order,
            'max_sites'                   => $plan->max_sites,
            'max_social_networks_per_site'=> $plan->max_social_networks_per_site,
            'max_messages_per_month'      => $plan->max_messages_per_month,
            'formatted_chunks'            => $plan->formatted_chunks,
            'formatted_tokens'            => $plan->formatted_tokens,

            // Prix formatés dans la devise détectée
            'monthly_price_formatted'     => $this->currencyService->format($monthlyConverted, $currency),
            'annual_price_formatted'      => $this->currencyService->format($annualConverted, $currency),
            'annual_savings_formatted'    => $this->currencyService->format($savingsConverted, $currency),

            // Valeurs numériques brutes pour JS
            'price_monthly_cents'         => $monthlyConverted,
            'price_annual_cents'          => $annualConverted,
        ];
    }

    private function formatPrice(int $centsEur, float $rate): float
    {
        return round(($centsEur * $rate) / 100, 2);
    }
}
