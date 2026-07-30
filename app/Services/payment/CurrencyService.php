<?php

namespace App\Services\payment;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CurrencyService
{
    private const SUPPORTED_CURRENCIES = ['eur', 'usd', 'gbp', 'cad', 'chf', 'mad'];
    private const EUR_CURRENCY_CODES = ['EUR'];
    private const USD_CURRENCY_CODES = ['USD'];

    /**
     * Détecter la devise de l'utilisateur via son IP.
     * Retourne 'eur' ou 'usd' (on simplifie à ces deux).
     */
    public function detectCurrencyFromIp(string $ip): string
    {
        // IPs locales → EUR par défaut
        if ($this->isLocalIp($ip)) {
            return 'eur';
        }

        $cacheKey = "currency_ip_{$ip}";

        return Cache::remember($cacheKey, 86400, function () use ($ip) {
            try {
                $response = Http::timeout(3)->get("https://ipapi.co/{$ip}/json/");

                if ($response->successful()) {
                    $data     = $response->json();
                    $currency = strtolower($data['currency'] ?? 'eur');

                    // On supporte EUR et USD ; tout le reste → EUR
                    return in_array($currency, ['eur', 'usd']) ? $currency : 'eur';
                }
            } catch (\Exception $e) {
                Log::warning('CurrencyService: IP detection failed', [
                    'ip'    => $ip,
                    'error' => $e->getMessage(),
                ]);
            }

            return 'eur';
        });
    }

    /**
     * Obtenir le taux EUR → devise cible.
     * Utilise Stripe Exchange Rates si disponible, sinon ExchangeRate-API.
     */
    public function getRate(string $targetCurrency): float
    {
        $targetCurrency = strtolower($targetCurrency);

        if ($targetCurrency === 'eur') {
            return 1.0;
        }

        $cacheKey = "exchange_rate_eur_{$targetCurrency}";
        $cacheTtl = config('stripe.exchange_rate_cache_ttl', 3600);

        return Cache::remember($cacheKey, $cacheTtl, function () use ($targetCurrency) {
            // Tentative 1 : Stripe Exchange Rates
            $rate = $this->getRateFromStripe($targetCurrency);

            // Tentative 2 : ExchangeRate-API (fallback)
            if (!$rate) {
                $rate = $this->getRateFromExchangeRateApi($targetCurrency);
            }

            // Fallback final : taux hardcodé approximatif
            if (!$rate) {
                $rate = $this->getFallbackRate($targetCurrency);
                Log::warning('CurrencyService: Using hardcoded fallback rate', [
                    'currency' => $targetCurrency,
                    'rate'     => $rate,
                ]);
            }

            return $rate;
        });
    }

    /**
     * Convertir un montant en centimes EUR vers une autre devise.
     * Retourne le montant en centimes dans la devise cible.
     */
    public function convert(int $amountCentsEur, string $targetCurrency): int
    {
        $rate   = $this->getRate($targetCurrency);
        $result = $amountCentsEur * $rate;

        return (int) round($result);
    }

    /**
     * Formater un montant en centimes pour l'affichage.
     */
    public function format(int $amountCents, string $currency): string
    {
        $amount   = $amountCents / 100;
        $currency = strtoupper($currency);

        $symbols = [
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            'CAD' => 'CA$',
            'CHF' => 'CHF ',
            'MAD' => 'MAD ',
        ];

        $symbol = $symbols[$currency] ?? $currency . ' ';

        // Format selon la devise
        if (in_array($currency, ['EUR', 'CHF', 'MAD'])) {
            return number_format($amount, 0, ',', ' ') . ' ' . $symbol;
        }

        return $symbol . number_format($amount, 0, '.', ',');
    }

    // ─── Providers de taux ───────────────────────────────────────────────────

    private function getRateFromStripe(string $targetCurrency): ?float
    {
        try {
            $stripe   = new \Stripe\StripeClient(config('stripe.secret'));
            $rates    = $stripe->exchangeRates->retrieve('eur');
            $ratesArr = $rates->rates ?? [];

            if (isset($ratesArr[$targetCurrency])) {
                return (float) $ratesArr[$targetCurrency];
            }
        } catch (\Exception $e) {
            Log::info('CurrencyService: Stripe rate unavailable', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function getRateFromExchangeRateApi(string $targetCurrency): ?float
    {
        $apiKey = config('stripe.exchange_rate_api_key');
        if (!$apiKey) return null;

        try {
            $response = Http::timeout(5)->get(
                "https://v6.exchangerate-api.com/v6/{$apiKey}/pair/EUR/" . strtoupper($targetCurrency)
            );

            if ($response->successful()) {
                $data = $response->json();
                if ($data['result'] === 'success') {
                    return (float) $data['conversion_rate'];
                }
            }
        } catch (\Exception $e) {
            Log::warning('CurrencyService: ExchangeRate-API failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function getFallbackRate(string $currency): float
    {
        // Taux approximatifs de secours (mis à jour manuellement si besoin)
        $fallbacks = [
            'usd' => 1.08,
            'gbp' => 0.86,
            'cad' => 1.46,
            'chf' => 0.97,
            'mad' => 10.80,
        ];

        return $fallbacks[$currency] ?? 1.0;
    }

    private function isLocalIp(string $ip): bool
    {
        return in_array($ip, ['127.0.0.1', '::1'])
            || str_starts_with($ip, '192.168.')
            || str_starts_with($ip, '10.')
            || str_starts_with($ip, '172.');
    }
}
