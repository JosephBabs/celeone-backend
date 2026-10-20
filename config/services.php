<?php

return [

    'google' => [
        // This must be the OAuth 2.0 Web client ID configured as webClientId
        // in @react-native-google-signin/google-signin. It is the expected
        // audience (aud) of the Google ID token sent by the mobile app.
        'web_client_id' => env('GOOGLE_WEB_CLIENT_ID', env('GOOGLE_CLIENT_ID_MOBILE')),
    ],

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

'firebase' => [
    'api_key' => env('FIREBASE_API_KEY'),
    'project_id' => env('FIREBASE_PROJECT_ID', 'celeone-e5843'),
],


'google' => [
        'web_client_id' => env(
            'GOOGLE_WEB_CLIENT_ID',
            env('GOOGLE_CLIENT_ID_MOBILE')
        ),
    ],


];
