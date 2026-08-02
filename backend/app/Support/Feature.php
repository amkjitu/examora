<?php

namespace App\Support;

/**
 * The named capabilities a tier can grant.
 *
 * Nothing in the application checks "is this user subscribed?". It asks whether
 * they are entitled to a named feature, so the commercial policy lives in
 * config/examora.php rather than being scattered through controllers — and so a
 * pricing change is a config edit, not a hunt through the codebase.
 */
final class Feature
{
    // ---- free ------------------------------------------------------------

    /** Chapters ranked by exam probability. The proof the premise works. */
    public const CHAPTER_RANKINGS = 'chapter_rankings';

    /** Generated micro-lessons. Metered by the lessons_per_day limit. */
    public const LESSONS = 'lessons';

    public const PRACTICE = 'practice';

    /** Never gated: forgetting what you learned is the costliest thing of all. */
    public const REVISION = 'revision';

    public const DAILY_PLAN = 'daily_plan';

    public const READINESS = 'readiness';

    /** The ranked topic forecast, without the repeat candidates. */
    public const FORECAST_TOPICS = 'forecast_topics';

    // ---- premium ---------------------------------------------------------

    public const MOCK_EXAM = 'mock_exam';

    /** Real per-call LLM cost. */
    public const AI_TUTOR = 'ai_tutor';

    /** The highest-value output: questions already reused verbatim. */
    public const REPEAT_CANDIDATES = 'repeat_candidates';

    /** Real per-page LLM cost. */
    public const DOCUMENT_UPLOAD = 'document_upload';

    /** Human-readable names, used in the 402 body and by the SPA. */
    public const LABELS = [
        self::CHAPTER_RANKINGS => 'Chapter rankings',
        self::LESSONS => 'Lessons',
        self::PRACTICE => 'Practice',
        self::REVISION => 'Revision',
        self::DAILY_PLAN => 'Daily plan',
        self::READINESS => 'Readiness score',
        self::FORECAST_TOPICS => 'Topic forecast',
        self::MOCK_EXAM => 'Mock exams',
        self::AI_TUTOR => 'AI tutor',
        self::REPEAT_CANDIDATES => 'Likely repeat questions',
        self::DOCUMENT_UPLOAD => 'Document upload',
    ];

    public static function label(string $feature): string
    {
        return self::LABELS[$feature] ?? ucfirst(str_replace('_', ' ', $feature));
    }
}
