DtD-ai/backend/config/ai.php#L1-120
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default LLM Driver
    |--------------------------------------------------------------------------
    |
    | Which LLM engine should the AI Platform use by default?
    |
    | Supported: "local", "gemini"
    |
    | You can switch this at runtime via .env:
    |   LLM_DRIVER=local
    |   LLM_DRIVER=gemini
    |
    */

    'driver' => env('LLM_DRIVER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Local LLM (Ollama) Configuration
    |--------------------------------------------------------------------------
    |
    | This is the configuration for the local LLM engine, typically backed by
    | an Ollama instance running in your infra stack (e.g. docker-compose).
    |
    | LOCAL_LLM_BASE_URL should point to the Ollama HTTP endpoint.
    |
    | Example:
    |   LOCAL_LLM_BASE_URL=http://ollama:11434
    |   LOCAL_LLM_MODEL=llama3.1:8b
    |
    */

    'local' => [
        'base_url' => env('LOCAL_LLM_BASE_URL', env('OLLAMA_URL', 'http://ollama:11434')),
        'model'    => env('LOCAL_LLM_MODEL', env('OLLAMA_MODEL', 'llama3.1:8b')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gemini Configuration (Cloud LLM)
    |--------------------------------------------------------------------------
    |
    | Basic configuration for Google Gemini as a remote LLM provider.
    | This is intentionally minimal and you should adapt the endpoint
    | and model names to the exact Gemini API / SDK you are using.
    |
    | Example .env:
    |   GEMINI_API_KEY=your-api-key
    |   GEMINI_MODEL=gemini-1.5-pro
    |   GEMINI_ENDPOINT=https://generativelanguage.googleapis.com/v1beta/models
    |
    */

    'gemini' => [
        'api_key'  => env('GEMINI_API_KEY', ''),
        'model'    => env('GEMINI_MODEL', 'gemini-1.5-pro'),
        'endpoint' => env(
            'GEMINI_ENDPOINT',
            'https://generativelanguage.googleapis.com/v1beta/models'
        ),
    ],

];
