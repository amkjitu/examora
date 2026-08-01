<?php

namespace Tests;

use App\Models\Examora\Exam;
use App\Models\Examora\PastQuestion;
use App\Models\Examora\Subject;
use App\Models\Examora\Topic;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Test fixtures. Deliberately built from the same shape as the real seeder —
 * repeated questions across years — so tests exercise the importance scorer's
 * actual behaviour rather than a degenerate single-question case.
 */
class Examora
{
    public static function exam(array $attributes = []): Exam
    {
        return Exam::create(array_merge([
            'code' => 'test-exam',
            'name' => 'Test Exam',
            'slug' => 'test-exam',
            'total_marks' => 200,
            'duration_minutes' => 120,
            'is_active' => true,
        ], $attributes));
    }

    public static function subject(Exam $exam, string $name = 'General Knowledge', int $marks = 30): Subject
    {
        return Subject::create([
            'exam_id' => $exam->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'marks' => $marks,
        ]);
    }

    public static function topic(Subject $subject, string $name = 'Liberation War'): Topic
    {
        return Topic::create([
            'subject_id' => $subject->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'level' => Topic::LEVEL_CHAPTER,
            'is_active' => true,
        ]);
    }

    /**
     * Adds one logical fact examined in several years.
     *
     * @param  array<int, int>  $years
     * @return array<int, PastQuestion>
     */
    public static function fact(Topic $topic, string $stem, array $options, int $correct, array $years): array
    {
        $fingerprint = PastQuestion::fingerprintFor($stem, $options);

        return array_map(fn (int $year) => PastQuestion::create([
            'exam_id' => $topic->subject->exam_id,
            'topic_id' => $topic->id,
            'year' => $year,
            'session_label' => "Session {$year}",
            'stem' => $stem,
            'options' => $options,
            'correct_index' => $correct,
            'explanation' => 'Because that is the examined fact.',
            'difficulty' => 'medium',
            'marks' => 1,
            'fingerprint' => $fingerprint,
        ]), $years);
    }

    /** A topic with three facts across several sessions — enough to score. */
    public static function populatedTopic(?Exam $exam = null, string $name = 'Liberation War'): Topic
    {
        $exam ??= self::exam();
        $subject = self::subject($exam);
        $topic = self::topic($subject, $name);

        self::fact($topic, 'Who was the Commander-in-Chief?', ['Osmani', 'Zia', 'Khaled', 'Shafiullah'], 0, [2018, 2021, 2024]);
        self::fact($topic, 'How many sectors were there?', ['11', '9', '7', '13'], 0, [2019, 2023]);
        self::fact($topic, 'When was the Proclamation issued?', ['10 April 1971', '26 March 1971', '17 April 1971', '16 December 1971'], 0, [2020]);

        return $topic->fresh(['subject.exam']);
    }

    public static function user(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Test Student',
            'email' => 'student'.Str::random(6).'@example.com',
            'password' => bcrypt('password'),
            'verified' => 1,
        ], $attributes));
    }
}
