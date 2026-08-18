<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
        'client_id'       => env('SLACK_CLIENT_ID'),
        'client_secret'   => env('SLACK_CLIENT_SECRET'),
        'redirect'        => env('SLACK_REDIRECT_URI'),
        'signing_secret'  => env('SLACK_SIGNING_SECRET'), // ✅ requis pour SlackWebhookSecurityService
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ], 

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI'),
        'webhook_verify_token' => env(
            'FACEBOOK_VERIFY_TOKEN'
        ),
        'app_secret' => env(
            'FACEBOOK_APP_SECRET'
        ),
        'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v25.0'),
    ],

    'instagram' => [
        'client_id' => env('INSTAGRAM_CLIENT_ID'),
        'client_secret' => env('INSTAGRAM_CLIENT_SECRET'),
        'redirect' => env('INSTAGRAM_REDIRECT_URI'),
    ],

    'telegram' => [
        'bot_api' => env('TELEGRAM_BOT_API', 'https://api.telegram.org'),
    ],

    'gmail' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GMAIL_REDIRECT_URI_EMAIL'),
        'pubsub_topic'  => env('GMAIL_PUBSUB_TOPIC'),
        'pubsub_subscription'  => env('GMAIL_SUBSCRIPTION_TOPIC'),
    ],

    'outlook' => [
        'client_id'     => env('OUTLOOK_CLIENT_ID'),
        'client_secret' => env('OUTLOOK_CLIENT_SECRET'),
        'tenant_id'     => env('OUTLOOK_TENANT_ID', 'common'),
        'redirect'      => env('OUTLOOK_REDIRECT_URI'),
    ],

    'whatsapp' => [
        'app_id'               => env('WHATSAPP_APP_ID'),
        'app_secret'           => env('WHATSAPP_APP_SECRET'),
        'config_id'            => env('WHATSAPP_CONFIG_ID'),
        'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'system_token'         => env('WHATSAPP_SYSTEM_TOKEN'),
        'graph_version'        => env('WHATSAPP_GRAPH_VERSION', 'v21.0'),
    ],
];
