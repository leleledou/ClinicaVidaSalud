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

    // CU39 Ejecutar comando de voz (RF-40): Servicio de IA (Google Gemini, API generateContent).
    // La clave solo se lee del .env (GEMINI_API_KEY) y viaja en el encabezado x-goog-api-key.
    'gemini' => [
        'clave' => env('GEMINI_API_KEY'),
        // Flash estable con generateContent que responde dentro del timeout (gemini-2.5-flash ya no está disponible
        // para claves nuevas; gemini-3.8-flash superó los 15 s en la mayoría de las pruebas).
        'modelo' => env('GEMINI_MODEL') ?: 'gemini-3.6-flash',
        'url' => 'https://generativelanguage.googleapis.com/v1beta',
        'timeout' => 15,
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
