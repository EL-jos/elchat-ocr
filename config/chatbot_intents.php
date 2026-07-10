<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Available Intents
    |--------------------------------------------------------------------------
    */

    'intents' => [

        'information',
        'pricing',
        'comparison',
        'navigation',
        'transactional',
        'support',
        'lead',
        'booking',
        'download',
    ],


    /*
    |--------------------------------------------------------------------------
    | Capability Sets
    |--------------------------------------------------------------------------
    | Réutilisables pour plusieurs types de sites
    */

    'capabilities' => [

        'content' => [
            'information',
            'navigation',
            'lead',
            'download'
        ],

        'commerce' => [
            'information',
            'pricing',
            'comparison',
            'transactional',
            'support'
        ],

        'product' => [
            'information',
            'pricing',
            'comparison',
            'support',
            'lead',
            'transactional',
            'booking',
            'download'
        ],

        'knowledge' => [
            'information',
            'navigation',
            'support',
            'download'
        ],

        'community' => [
            'information',
            'navigation',
            'support'
        ],

        'corporate' => [
            'information',
            'navigation',
            'lead',
            'booking'
        ],

        'private' => [
            'information',
            'navigation',
            'support'
        ],

    ],


    /*
    |--------------------------------------------------------------------------
    | Site Types Mapping
    |--------------------------------------------------------------------------
    */

    'site_types' => [

        // Content sites
        'blog' => 'content',
        'portfolio' => 'content',
        'site-dactualites' => 'content',
        'site-evenementiel' => 'content',

        // Commerce
        'e-commerce' => 'commerce',
        'marketplace' => 'commerce',
        'comparateur' => 'commerce',

        // Product / Apps
        'saas' => 'product',
        'application-web' => 'product',
        'pwa' => 'product',

        // Knowledge
        'documentation' => 'knowledge',
        'site-educatif' => 'knowledge',

        // Community
        'forum-communaute' => 'community',
        'communaute' => 'community',

        // Corporate
        'site-vitrine' => 'corporate',
        'site-associatif' => 'corporate',
        'portail-institutionnel' => 'corporate',
        'landing-page' => 'corporate',

        // Private systems
        'intranet-extranet' => 'private',
        'extranet' => 'private',
    ],


    /*
    |--------------------------------------------------------------------------
    | Default fallback
    |--------------------------------------------------------------------------
    */

    'default_capability' => 'content',

];
