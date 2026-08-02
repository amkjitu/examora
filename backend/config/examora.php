<?php

use App\Support\Feature;

return [
    'default_exam' => env('EXAMORA_DEFAULT_EXAM', 'bcs-preliminary'),

    'daily_minutes' => (int) env('EXAMORA_DAILY_MINUTES', 20),

    'quiz' => [
        'lesson_check_questions' => 5,
        'practice_questions' => 10,
        'revision_questions' => 15,
        'mock_questions' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tiers
    |--------------------------------------------------------------------------
    |
    | What each tier includes, and where its limits sit. Nothing in the
    | application asks "is this user subscribed?" — it asks whether they are
    | entitled to a named feature, which keeps the commercial policy in one
    | readable place instead of scattered through controllers.
    |
    | Two principles behind this split:
    |
    |   The free tier has to be genuinely useful. A candidate who cannot see the
    |   product work will not pay for it, so free includes the thing that proves
    |   the premise — chapters ranked by exam probability — plus real lessons,
    |   unlimited practice and unlimited revision. Forgetting what you learned is
    |   the most expensive thing a candidate can do, so revision is never walled.
    |
    |   What costs money to serve is what gets gated. The AI tutor and document
    |   ingestion carry real per-call LLM cost, and mock exams and repeat
    |   candidates are the highest-value output. Those are premium; the rest is
    |   marginal-cost-zero and stays open.
    |
    */

    'tiers' => [

        'free' => [
            'label' => 'Free',
            'features' => [
                Feature::CHAPTER_RANKINGS,
                Feature::LESSONS,
                Feature::PRACTICE,
                Feature::REVISION,
                Feature::DAILY_PLAN,
                Feature::READINESS,
                // The topic forecast is marketing as much as product — seeing it
                // is the strongest argument for paying. Repeat candidates, the
                // part worth memorising, are not included.
                Feature::FORECAST_TOPICS,
            ],
            'limits' => [
                'lessons_per_day' => (int) env('EXAMORA_FREE_LESSONS_PER_DAY', 3),
                'documents_per_month' => 0,
            ],
        ],

        'premium' => [
            'label' => 'Premium',
            'features' => [
                Feature::CHAPTER_RANKINGS,
                Feature::LESSONS,
                Feature::PRACTICE,
                Feature::REVISION,
                Feature::DAILY_PLAN,
                Feature::READINESS,
                Feature::FORECAST_TOPICS,
                Feature::MOCK_EXAM,
                Feature::AI_TUTOR,
                Feature::REPEAT_CANDIDATES,
                Feature::DOCUMENT_UPLOAD,
            ],
            'limits' => [
                'lessons_per_day' => null,   // null means unlimited
                'documents_per_month' => (int) env('EXAMORA_PREMIUM_DOCUMENTS_PER_MONTH', 30),
            ],
        ],
    ],

    /*
    | Which tier each Wave plan maps to. Plans are matched on their slugified
    | name. Anything unrecognised falls back to `premium` — a paying customer
    | must never be worse off because a plan was renamed.
    */
    'plan_tiers' => [
        'basic' => 'premium',
        'premium' => 'premium',
        'pro' => 'premium',
    ],

    'fallback_tier' => 'free',

    /* Where the SPA sends someone who hits a gate. */
    'upgrade_url' => env('EXAMORA_UPGRADE_URL', '/settings/subscription'),
];
