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

    'dsco' => [
        'base_url'      => env('DSCO_BASE_URL'),
        'auth_url'      => env('DSCO_AUTH_URL'),
        'client_id'     => env('DSCO_CLIENT_ID'),
        'client_secret' => env('DSCO_CLIENT_SECRET'),
        'username'      => env('DSCO_USERNAME'),
        'password'      => env('DSCO_PASSWORD'),
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
