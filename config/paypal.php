<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mode : sandbox (test) ou live (production)
    |--------------------------------------------------------------------------
    */
    'mode' => env('PAYPAL_MODE', 'sandbox'), // 'sandbox' | 'live'

    /*
    |--------------------------------------------------------------------------
    | Credentials Sandbox (test)
    | Récupérés sur : https://developer.paypal.com/dashboard/applications/sandbox
    |--------------------------------------------------------------------------
    */
    'sandbox' => [
        'client_id'     => env('PAYPAL_SANDBOX_CLIENT_ID'),
        'client_secret' => env('PAYPAL_SANDBOX_CLIENT_SECRET'),
        'webhook_id'    => env('PAYPAL_SANDBOX_WEBHOOK_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Credentials Live (production)
    | Récupérés sur : https://developer.paypal.com/dashboard/applications/live
    |--------------------------------------------------------------------------
    */
    'live' => [
        'client_id'     => env('PAYPAL_LIVE_CLIENT_ID'),
        'client_secret' => env('PAYPAL_LIVE_CLIENT_SECRET'),
        'webhook_id'    => env('PAYPAL_LIVE_WEBHOOK_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | URLs de base API PayPal
    |--------------------------------------------------------------------------
    */
    'base_url' => [
        'sandbox' => 'https://api-m.sandbox.paypal.com',
        'live'    => 'https://api-m.paypal.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | URLs de retour après le flux PayPal
    |--------------------------------------------------------------------------
    */
    'return_url' => env('APP_URL') . '/payment/success?provider=paypal',
    'cancel_url' => env('APP_URL') . '/tarifs?payment=canceled&provider=paypal',

    /*
    |--------------------------------------------------------------------------
    | Devise principale PayPal
    | PayPal gère la conversion automatiquement selon le compte PayPal du client
    |--------------------------------------------------------------------------
    */
    'currency' => env('PAYPAL_CURRENCY', 'EUR'),

    /*
    |--------------------------------------------------------------------------
    | Cache TTL pour l'access token PayPal (en secondes)
    | Le token PayPal expire après 32400s (9h) — on cache 8h par sécurité
    |--------------------------------------------------------------------------
    */
    'token_cache_ttl' => (int) env('PAYPAL_TOKEN_CACHE_TTL', 28800),

    /*
    |--------------------------------------------------------------------------
    | Marque affichée dans le flow PayPal
    |--------------------------------------------------------------------------
    */
    'brand_name' => env('PAYPAL_BRAND_NAME', 'ELChat'),

    /*
    |--------------------------------------------------------------------------
    | Webhooks à écouter (référence pour la configuration Dashboard)
    |--------------------------------------------------------------------------
    */
    'webhook_events' => [
        'BILLING.SUBSCRIPTION.CREATED',
        'BILLING.SUBSCRIPTION.ACTIVATED',
        'BILLING.SUBSCRIPTION.UPDATED',
        'BILLING.SUBSCRIPTION.CANCELLED',
        'BILLING.SUBSCRIPTION.SUSPENDED',
        'BILLING.SUBSCRIPTION.PAYMENT.FAILED',
        'PAYMENT.SALE.COMPLETED',
    ],
];
