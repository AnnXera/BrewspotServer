<?php

return [

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

    'paymongo' => [
        'public_key'     => env('PAYMONGO_PUBLIC_KEY'),
        'secret_key'     => env('PAYMONGO_SECRET_KEY'),
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET_KEY'),

        // Where PayMongo returns the owner after checkout. Both point at the frontend,
        // not the API, since the owner lands on them in a browser.
        'success_url'    => env('PAYMONGO_SUCCESS_URL', rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/') . '/owner/subscription?checkout=success'),
        'cancel_url'     => env('PAYMONGO_CANCEL_URL', rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/') . '/owner/subscription?checkout=cancelled'),
    ],

];