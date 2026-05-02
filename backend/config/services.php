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

    'test_agent' => [
        'url' => env('TEST_AGENT_URL', env('VITE_TEST_AGENT_URL', 'http://localhost:8000')),
    ],

    'test_generation' => [
        // Provider: 'local-llm' (default), 'evomaster', or 'fallback'
        'provider' => env('TEST_GENERATION_PROVIDER', 'local-llm'),
        
        // Local LLM Configuration (Ollama, LM Studio, etc.)
        'llm_url' => env('LLM_API_URL', 'http://localhost:11434'),
        'llm_model' => env('LLM_MODEL', 'mistral'), // mistral, llama2, neural-chat, etc.
        'llm_timeout' => env('LLM_TIMEOUT', 5),
        'llm_temperature' => env('LLM_TEMPERATURE', 0.2),
        
        // EvoMaster Configuration
        'evomaster_url' => env('EVOMASTER_URL', 'http://localhost'),
        'evomaster_port' => env('EVOMASTER_PORT', '40898'),
    ],

];
