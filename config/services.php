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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    'inforu' => [
        'base_url' => env('INFORU_BASE_URL', 'https://capi.inforu.co.il'),
        'basic_auth' => env('INFORU_BASIC_AUTH'),
        'from_address' => env('INFORU_FROM_ADDRESS', env('MAIL_FROM_ADDRESS')),
        'from_name' => env('INFORU_FROM_NAME', env('MAIL_FROM_NAME')),
        'reply_address' => env('INFORU_REPLY_ADDRESS', ''),
        'campaign_prefix' => env('INFORU_CAMPAIGN_PREFIX', 'kfitz'),
        'timeout' => env('INFORU_TIMEOUT', 20),
    ],

];
