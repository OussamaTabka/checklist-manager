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
        // Provider: 'openai' (default), 'local-llm', 'evomaster', or 'fallback'
        'provider' => env('TEST_GENERATION_PROVIDER', 'openai'),

        // OpenAI / Codex Configuration
        'openai_api_key' => env('AGENT_CODEX_API_KEY', ''),
        'openai_model' => env('AGENT_OPENAI_MODEL', 'gpt-5.3-codex'),
        'openai_api_url' => env('AGENT_OPENAI_API_URL', 'https://codex.sale/v1'),
        'openai_timeout' => env('AGENT_OPENAI_TIMEOUT_MS', 60000) / 1000,
        'openai_max_tokens' => (int) env('AGENT_OPENAI_MAX_TOKENS', 4096),
        'openai_temperature' => env('TEST_GENERATION_TEMPERATURE', 0.1),

        // Local LLM Configuration (Ollama, LM Studio, etc.)
        'llm_url' => env('LLM_API_URL', 'http://localhost:11434'),
        'llm_model' => env('LLM_MODEL', 'mistral'),
        'llm_timeout' => env('LLM_TIMEOUT', 5),
        'llm_temperature' => env('LLM_TEMPERATURE', 0.2),

        // EvoMaster Configuration
        'evomaster_url' => env('EVOMASTER_URL', 'http://localhost'),
        'evomaster_port' => env('EVOMASTER_PORT', '40898'),
    ],

];
