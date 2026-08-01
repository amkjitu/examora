<?php

return [
    'default_exam' => env('EXAMORA_DEFAULT_EXAM', 'bcs-preliminary'),

    'daily_minutes' => (int) env('EXAMORA_DAILY_MINUTES', 20),

    // Free tier allowance. Metered, not blocked — see EnforceLessonQuota.
    'free_lessons_per_day' => (int) env('EXAMORA_FREE_LESSONS_PER_DAY', 3),

    'quiz' => [
        'lesson_check_questions' => 5,
        'practice_questions' => 10,
        'revision_questions' => 15,
        'mock_questions' => 100,
    ],
];
