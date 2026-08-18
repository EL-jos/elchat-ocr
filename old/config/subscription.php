<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Provider de paiement par défaut pour toute nouvelle souscription
    |--------------------------------------------------------------------------
    */
    'default_provider' => env('SUBSCRIPTION_DEFAULT_PROVIDER', 'paypal'),

    /*
    |--------------------------------------------------------------------------
    | Durée du trial (en jours) — configurable
    |--------------------------------------------------------------------------
    */
    'trial_days' => (int) env('SUBSCRIPTION_TRIAL_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Devise unique — pas de conversion, jamais de multi-devise
    |--------------------------------------------------------------------------
    */
    'currency' => 'EUR',

];
