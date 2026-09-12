<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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
    ],

    'evolution' => [
        'url' => env('EVOLUTION_API_URL', 'http://192.168.5.254:8082'),
        'api_key' => env('EVOLUTION_API_KEY', 'bmo_secret_evolution_key_9f3a12b48c'),
        'instance' => env('EVOLUTION_INSTANCE', 'bmo'),
        'webhook_secret' => env('EVOLUTION_WEBHOOK_SECRET', 'bmo_wh_secret_7a8b9c'),
    ],

    'receipt_extractor' => [
        'url' => env('RECEIPT_EXTRACTOR_URL', 'http://127.0.0.1:8084/extract'),
    ],

];
