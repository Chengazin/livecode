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

    'forgejo' => [
        'base_url' => rtrim((string) env('FORGEJO_BASE_URL', ''), '/'),
        'public_url' => rtrim((string) env('FORGEJO_PUBLIC_URL', (string) env('FORGEJO_BASE_URL', '')), '/'),
        'git_base_url' => rtrim((string) env('FORGEJO_GIT_BASE_URL', (string) env('FORGEJO_BASE_URL', '')), '/'),
        'client_id' => env('FORGEJO_CLIENT_ID'),
        'client_secret' => env('FORGEJO_CLIENT_SECRET'),
        'redirect_url' => env('FORGEJO_REDIRECT_URL'),
        'scopes' => env('FORGEJO_OAUTH_SCOPES', 'read:user,write:user,read:repository,write:repository'),
        'auto_link_by_email' => (bool) env('FORGEJO_AUTO_LINK_BY_EMAIL', false),
    ],

    'speech' => [
        'local' => [
            'python_binary' => env('SPEECH_LOCAL_PYTHON', 'python'),
            'script_path' => env('SPEECH_LOCAL_SCRIPT', 'tools/local_transcribe.py'),
            'model' => env('SPEECH_LOCAL_MODEL', 'small'),
            'device' => env('SPEECH_LOCAL_DEVICE', 'cpu'),
            'compute_type' => env('SPEECH_LOCAL_COMPUTE_TYPE', 'int8'),
            'beam_size' => (int) env('SPEECH_LOCAL_BEAM_SIZE', 1),
            'vad_filter' => (bool) env('SPEECH_LOCAL_VAD_FILTER', false),
            'timeout' => (int) env('SPEECH_LOCAL_TIMEOUT', 120),
        ],
    ],

];
