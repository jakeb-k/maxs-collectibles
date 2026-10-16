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

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'fontawesome' => ['kit' => env('FONT_AWESOME_KIT')],
    'google_maps' => [
        'api_key' => env('GOOGLE_MAPS_KEY'),
    ],
    'zoho' => [
        'client_id' => env('ZOHO_CLIENT_ID'),
        'client_secret' => env('ZOHO_CLIENT_SECRET'),
        'account_id' => env('ZOHO_ACCOUNT_ID'),
        'mail_api_url' => env('ZOHO_MAIL_API_URL', in_array(env('APP_ENV'), ['local', 'testing'], true) ? 'https://mail.zoho.com.au' : null),
        'oauth_url' => env('ZOHO_OAUTH_URL', in_array(env('APP_ENV'), ['local', 'testing'], true) ? 'https://accounts.zoho.com.au/oauth/v2/token' : null),
    ],


];
