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

    'arxis' => [
        'base_url' => env('ARXIS_BASE_URL', 'https://api.arxis.io/v1'),
        'api_key' => env('ARXIS_API_KEY'),
    ],

    'test_generation' => [
        // Provider: 'openai', 'anthropic', 'local-llm', 'evomaster', or 'fallback'
        'provider' => env('TEST_GENERATION_PROVIDER', 'local-llm'),

        // Shared LLM tuning
        'timeout' => (int) env('LLM_TIMEOUT', 120),
        'max_tokens' => (int) env('LLM_MAX_TOKENS', 1200),

        // OpenAI Configuration
        'openai_url' => env('OPENAI_API_URL', 'https://api.openai.com/v1'),
        'openai_model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'openai_api_key' => env('OPENAI_API_KEY'),

        // Anthropic Configuration
        'anthropic_url' => env('ANTHROPIC_API_URL', 'https://api.anthropic.com/v1'),
        'anthropic_model' => env('ANTHROPIC_MODEL', 'claude-3-5-sonnet-latest'),
        'anthropic_api_key' => env('ANTHROPIC_API_KEY'),
        'anthropic_version' => env('ANTHROPIC_VERSION', '2023-06-01'),
        
        // Local LLM Configuration (Ollama, LM Studio, etc.)
        'llm_url' => env('LLM_API_URL', 'http://localhost:11434'),
        'llm_model' => env('LLM_MODEL', 'mistral'), // mistral, llama2, neural-chat, etc.
        
        // EvoMaster Configuration
        'evomaster_url' => env('EVOMASTER_URL', 'http://localhost'),
        'evomaster_port' => env('EVOMASTER_PORT', '40898'),
    ],

];
