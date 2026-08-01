<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default and fallback providers
    |--------------------------------------------------------------------------
    | The gateway resolves a provider per *task*, not globally. These are only
    | used when a task has no explicit route or when its route is unconfigured.
    */

    'default' => env('AI_DEFAULT_PROVIDER', 'mock'),
    'fallback' => env('AI_FALLBACK_PROVIDER', 'mock'),

    'timeout' => (int) env('AI_TIMEOUT', 60),
    'retries' => 2,

    /*
    |--------------------------------------------------------------------------
    | Response cache
    |--------------------------------------------------------------------------
    | Lesson and question generation are deterministic-ish and shared across all
    | students, so caching on a hash of the rendered prompt removes almost all
    | repeat spend. Zero disables caching.
    */

    'cache_ttl' => (int) env('AI_CACHE_TTL', 604800), // 7 days

    /*
    |--------------------------------------------------------------------------
    | Task routing
    |--------------------------------------------------------------------------
    | Each task names an ordered preference list. The gateway walks the list and
    | uses the first configured provider, then falls back to `fallback`. This is
    | the "AI Gateway decides cheapest / fastest / best reasoning" layer.
    */

    'tasks' => [
        // Long context: reads many past questions at once to write the lesson.
        'lesson.generate' => ['providers' => ['anthropic', 'openai', 'gemini'], 'max_tokens' => 4000, 'temperature' => 0.3],

        // High volume, cost-sensitive.
        'question.generate' => ['providers' => ['gemini', 'openai', 'anthropic'], 'max_tokens' => 3000, 'temperature' => 0.7],

        // Latency-sensitive: a student is waiting.
        'tutor.answer' => ['providers' => ['gemini', 'openai', 'anthropic'], 'max_tokens' => 1200, 'temperature' => 0.4],

        // Transcribing a scanned page. Vision-capable providers only. Gemini
        // leads on cost because ingestion is page-by-page and volume adds up
        // fast; Claude is the fallback because it is the strongest on Bangla
        // script and multi-column exam layouts.
        'document.ocr' => ['providers' => ['gemini', 'anthropic', 'openai'], 'max_tokens' => 8000, 'temperature' => 0.0],

        // Structured extraction of questions from transcribed page text.
        'document.parse' => ['providers' => ['anthropic', 'openai', 'gemini'], 'max_tokens' => 8000, 'temperature' => 0.0],

        'topic.classify' => ['providers' => ['gemini', 'openai'], 'max_tokens' => 500, 'temperature' => 0.0],

        'embed' => ['providers' => ['openai', 'gemini'], 'dimensions' => 1536],
    ],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    | Costs are USD per 1M tokens and are used for the ai_requests ledger, which
    | is what tells you whether a subscription tier is actually profitable.
    */

    'providers' => [

        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
            'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
            'cost' => ['input' => 0.40, 'output' => 1.60, 'embedding' => 0.02],
        ],

        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
            'embedding_model' => env('GEMINI_EMBEDDING_MODEL', 'text-embedding-004'),
            'cost' => ['input' => 0.10, 'output' => 0.40, 'embedding' => 0.00],
        ],

        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5'),
            'version' => '2023-06-01',
            'cost' => ['input' => 3.00, 'output' => 15.00],
        ],

        'mock' => [
            'driver' => 'mock',
            'key' => 'mock',
            'model' => 'examora-mock-v1',
            'cost' => ['input' => 0.0, 'output' => 0.0, 'embedding' => 0.0],
        ],
    ],

    'embeddings' => [
        'dimensions' => 1536,
    ],
];
