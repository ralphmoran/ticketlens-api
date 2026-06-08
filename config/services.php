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

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'url' => 'https://api.anthropic.com/v1/messages',
        'version' => '2023-06-01',
        'model' => 'claude-haiku-4-5-20251001',
        'max_tokens' => 256,
    ],

    'groq' => [
        'key' => env('GROQ_API_KEY'),
        'url' => 'https://api.groq.com/openai/v1/chat/completions',
        'model' => 'llama-3.3-70b-versatile',
        'max_tokens' => 256,
    ],

    'lemonsqueezy' => [
        'api_key' => env('LEMONSQUEEZY_API_KEY'),
        'validate_url' => env('LEMONSQUEEZY_VALIDATE_URL', 'https://api.lemonsqueezy.com/v1/licenses/validate'),
    ],

    'slack' => [
        'client_id'      => env('SLACK_CLIENT_ID'),
        'client_secret'  => env('SLACK_CLIENT_SECRET'),
        'signing_secret' => env('SLACK_SIGNING_SECRET'),
        'redirect_uri'   => env('SLACK_REDIRECT_URI'),
    ],

];
