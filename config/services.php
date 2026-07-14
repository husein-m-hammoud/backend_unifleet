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
    ],

    /*
    | Safee Tracking REST Service (v2.2.0.0) — the platform DSCO also runs on.
    | One entry per partner company. Auth URL is derived per-provider as:
    |   {server_uri}/auth/realms/{realm}/protocol/openid-connect/token
    | Add saudiX (and future companies) as new keys under `providers`.
    */
    'safee' => [
        'default'   => env('SAFEE_DEFAULT_PROVIDER', 'alrakeen'),
        'providers' => [
            'alrakeen' => [
                'label'         => 'Alrakeen',
                'server_uri'    => env('SAFEE_ALRAKEEN_SERVER_URI', 'https://tk.alrakeen.sa'),
                'realm'         => env('SAFEE_ALRAKEEN_REALM', 'alrakeen'),
                'client_id'     => env('SAFEE_ALRAKEEN_CLIENT_ID', 'api'),
                'client_secret' => env('SAFEE_ALRAKEEN_CLIENT_SECRET'),
                'username'      => env('SAFEE_ALRAKEEN_USERNAME'),
                'password'      => env('SAFEE_ALRAKEEN_PASSWORD'),
            ],
            // 'saudix' => [
            //     'label'         => 'saudiX',
            //     'server_uri'    => env('SAFEE_SAUDIX_SERVER_URI'),
            //     'realm'         => env('SAFEE_SAUDIX_REALM', 'saudix'),
            //     'client_id'     => env('SAFEE_SAUDIX_CLIENT_ID', 'api'),
            //     'client_secret' => env('SAFEE_SAUDIX_CLIENT_SECRET'),
            //     'username'      => env('SAFEE_SAUDIX_USERNAME'),
            //     'password'      => env('SAFEE_SAUDIX_PASSWORD'),
            // ],
        ],
    ],

    'twilio' => [
        'sid'         => env('TWILIO_SID'),
        'auth_token'  => env('TWILIO_AUTH_TOKEN'),
        'from_number' => env('TWILIO_FROM_NUMBER'),
    ],

    'claude' => [
        'api_key' => env('CLAUDE_API_KEY'),
        'model'   => 'claude-sonnet-4-20250514',
    ],

];
