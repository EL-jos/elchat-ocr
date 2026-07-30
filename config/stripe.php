<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stripe Keys
    |--------------------------------------------------------------------------
    | Clés disponibles dans le Dashboard Stripe → Developers → API keys
    | En TEST : utiliser les clés sk_test_* et pk_test_*
    | En PROD : utiliser les clés sk_live_* et pk_live_*
    */

    'key'           => env('STRIPE_KEY'),           // Publishable key (pk_*)
    'secret'        => env('STRIPE_SECRET'),        // Secret key (sk_*)
    'webhook_secret'=> env('STRIPE_WEBHOOK_SECRET'),// whsec_* (depuis Stripe CLI ou Dashboard)

    /*
    |--------------------------------------------------------------------------
    | URLs de retour après paiement
    |--------------------------------------------------------------------------
    */

    'success_url'   => env('APP_URL') . '/payment/success?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'    => env('APP_URL') . '/tarifs?payment=canceled',

    /*
    |--------------------------------------------------------------------------
    | Période d'essai
    |--------------------------------------------------------------------------
    */

    'trial_days'    => (int) env('TRIAL_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Devise par défaut
    |--------------------------------------------------------------------------
    */

    'default_currency' => env('STRIPE_DEFAULT_CURRENCY', 'eur'),

    /*
    |--------------------------------------------------------------------------
    | Exchange Rate API (fallback si Stripe indisponible)
    |--------------------------------------------------------------------------
    | Inscription gratuite sur https://www.exchangerate-api.com
    */

    'exchange_rate_api_key' => env('EXCHANGE_RATE_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Cache TTL pour les taux de change (en secondes)
    |--------------------------------------------------------------------------
    */

    'exchange_rate_cache_ttl' => (int) env('EXCHANGE_RATE_CACHE_TTL', 3600), // 1 heure

    /*
    |--------------------------------------------------------------------------
    | Contact Enterprise
    |--------------------------------------------------------------------------
    */

    'enterprise_email' => env('ENTERPRISE_CONTACT_EMAIL', 'contact@elchat.io'),

];
