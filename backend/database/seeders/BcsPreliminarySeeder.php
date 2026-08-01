<?php

namespace Database\Seeders;

use App\Models\Examora\Exam;
use App\Models\Examora\PastQuestion;
use App\Models\Examora\Subject;
use App\Models\Examora\Topic;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * BCS Preliminary taxonomy and a representative question corpus.
 *
 * IMPORTANT — the subject/marks distribution below mirrors the published BCS
 * Preliminary structure (200 marks). The questions are *representative sample
 * data* written to exercise the exam-intelligence pipeline end to end; they are
 * not a verified transcription of official past papers. Before launch, replace
 * this seeder's question set with a licensed, verified corpus. Everything
 * downstream — importance scores, star ratings, lessons — is only as
 * trustworthy as this table.
 *
 * @see docs/volume-03-ai/knowledge-engine.md
 */
class BcsPreliminarySeeder extends Seeder
{
    public function run(): void
    {
        $exam = Exam::updateOrCreate(
            ['code' => 'bcs-preliminary'],
            [
                'name' => 'BCS Preliminary',
                'slug' => 'bcs-preliminary',
                'authority' => 'Bangladesh Public Service Commission',
                'locale' => 'en',
                'description' => 'The 200-mark MCQ screening test for the Bangladesh Civil Service.',
                'total_marks' => 200,
                'duration_minutes' => 120,
                'negative_marking' => 0.50,
                'is_active' => true,
            ]
        );

        foreach ($this->structure() as $position => $definition) {
            $subject = Subject::updateOrCreate(
                ['exam_id' => $exam->id, 'slug' => Str::slug($definition['name'])],
                [
                    'name' => $definition['name'],
                    'marks' => $definition['marks'],
                    'position' => $position,
                ]
            );

            foreach ($definition['topics'] as $topicPosition => $topicName) {
                Topic::updateOrCreate(
                    ['subject_id' => $subject->id, 'slug' => Str::slug($topicName)],
                    [
                        'name' => $topicName,
                        'level' => Topic::LEVEL_CHAPTER,
                        'position' => $topicPosition,
                        'is_active' => true,
                    ]
                );
            }
        }

        $this->seedQuestions($exam);
    }

    /** The published BCS Preliminary mark distribution. */
    private function structure(): array
    {
        return [
            ['name' => 'Bangla', 'marks' => 35, 'topics' => [
                'Bangla Literature: Medieval Period',
                'Bangla Literature: Modern Period',
                'Bangla Grammar: Sandhi and Samas',
                'Bangla Grammar: Idioms and Proverbs',
            ]],
            ['name' => 'English', 'marks' => 35, 'topics' => [
                'English Grammar: Parts of Speech',
                'English Grammar: Tense and Voice',
                'English Literature: Elizabethan Period',
                'Vocabulary: Synonyms and Antonyms',
            ]],
            ['name' => 'Bangladesh Affairs', 'marks' => 30, 'topics' => [
                'Liberation War of 1971',
                'Language Movement',
                'Constitution of Bangladesh',
                'Economy of Bangladesh',
            ]],
            ['name' => 'International Affairs', 'marks' => 20, 'topics' => [
                'United Nations and Its Organs',
                'International Treaties and Agreements',
                'Current World Affairs',
            ]],
            ['name' => 'Geography, Environment and Disaster Management', 'marks' => 10, 'topics' => [
                'Rivers of Bangladesh',
                'Climate and Disaster Management',
            ]],
            ['name' => 'General Science', 'marks' => 15, 'topics' => [
                'Human Physiology',
                'Physics: Light and Sound',
                'Chemistry: Elements and Compounds',
            ]],
            ['name' => 'Computer and ICT', 'marks' => 15, 'topics' => [
                'Computer Fundamentals',
                'Computer Networks',
                'Number Systems',
            ]],
            ['name' => 'Mathematical Reasoning', 'marks' => 15, 'topics' => [
                'Arithmetic: Percentage and Ratio',
                'Algebra: Equations',
                'Geometry: Triangles and Circles',
            ]],
            ['name' => 'Mental Ability', 'marks' => 15, 'topics' => [
                'Series and Patterns',
                'Spatial Reasoning',
            ]],
            ['name' => 'Ethics, Values and Good Governance', 'marks' => 10, 'topics' => [
                'Concepts of Good Governance',
                'Values and Social Norms',
            ]],
        ];
    }

    private function seedQuestions(Exam $exam): void
    {
        $topics = Topic::whereHas('subject', fn ($q) => $q->where('exam_id', $exam->id))
            ->get()
            ->keyBy('slug');

        foreach ($this->questions() as $row) {
            $topic = $topics[$row['topic']] ?? null;

            if (! $topic) {
                continue;
            }

            $fingerprint = PastQuestion::fingerprintFor($row['stem'], $row['options']);

            PastQuestion::updateOrCreate(
                [
                    'exam_id' => $exam->id,
                    'fingerprint' => $fingerprint,
                    'year' => $row['year'],
                ],
                [
                    'topic_id' => $topic->id,
                    'session_label' => $row['session'],
                    'stem' => $row['stem'],
                    'options' => $row['options'],
                    'correct_index' => $row['correct'],
                    'explanation' => $row['explanation'] ?? null,
                    'difficulty' => $row['difficulty'] ?? 'medium',
                    'marks' => 1,
                    'source' => 'seed:representative',
                ]
            );
        }
    }

    /**
     * Representative corpus. Repetition across years is intentional — it is the
     * signal the importance scorer is built to detect.
     */
    private function questions(): array
    {
        $liberation = [
            [
                'stem' => 'On which date was the Proclamation of Independence of Bangladesh formally issued?',
                'options' => ['10 April 1971', '26 March 1971', '17 April 1971', '16 December 1971'],
                'correct' => 0,
                'explanation' => 'The Proclamation of Independence was issued on 10 April 1971 and took effect from 26 March 1971. It was publicly read at the Mujibnagar oath-taking on 17 April.',
            ],
            [
                'stem' => 'Where did the Mujibnagar Government take oath?',
                'options' => ['Baidyanathtala, Meherpur', 'Chuadanga', 'Kushtia', 'Jessore'],
                'correct' => 0,
                'explanation' => 'The oath-taking took place on 17 April 1971 at Baidyanathtala in Meherpur, later renamed Mujibnagar.',
            ],
            [
                'stem' => 'How many sectors was Bangladesh divided into during the Liberation War?',
                'options' => ['9', '11', '7', '13'],
                'correct' => 1,
                'explanation' => 'The country was divided into 11 sectors for the conduct of the war.',
            ],
            [
                'stem' => 'Who was the Commander-in-Chief of the Mukti Bahini?',
                'options' => ['Colonel M. A. G. Osmani', 'Major Ziaur Rahman', 'Khaled Mosharraf', 'K. M. Shafiullah'],
                'correct' => 0,
                'explanation' => 'General M. A. G. Osmani served as Commander-in-Chief of the liberation forces.',
            ],
            [
                'stem' => 'Which sector was directly under no sector commander and was known as a training sector?',
                'options' => ['Sector 10', 'Sector 3', 'Sector 5', 'Sector 8'],
                'correct' => 0,
                'explanation' => 'Sector 10 comprised the naval commandos and operated directly under the Commander-in-Chief.',
                'difficulty' => 'hard',
            ],
        ];

        $language = [
            [
                'stem' => 'In which year did the Language Movement reach its climax with the killing of protesters in Dhaka?',
                'options' => ['1948', '1952', '1954', '1956'],
                'correct' => 1,
                'explanation' => 'Police fired on protesting students in Dhaka on 21 February 1952.',
            ],
            [
                'stem' => 'Bangla was recognised as a state language of Pakistan through a constitutional amendment in which year?',
                'options' => ['1952', '1954', '1956', '1962'],
                'correct' => 2,
                'explanation' => 'The 1956 Constitution of Pakistan recognised Bangla as a state language alongside Urdu.',
            ],
        ];

        $constitution = [
            [
                'stem' => 'How many articles did the original Constitution of Bangladesh contain?',
                'options' => ['153', '150', '161', '145'],
                'correct' => 0,
                'explanation' => 'The 1972 Constitution contained 153 articles arranged in 11 parts with 4 schedules.',
            ],
            [
                'stem' => 'The Constitution of Bangladesh was adopted on which date?',
                'options' => ['16 December 1972', '4 November 1972', '26 March 1973', '10 January 1972'],
                'correct' => 1,
                'explanation' => 'It was adopted on 4 November 1972 and came into effect on 16 December 1972.',
            ],
        ];

        $un = [
            [
                'stem' => 'How many permanent members does the United Nations Security Council have?',
                'options' => ['5', '10', '15', '7'],
                'correct' => 0,
                'explanation' => 'China, France, Russia, the United Kingdom and the United States hold permanent seats with veto power.',
            ],
            [
                'stem' => 'In which year did Bangladesh become a member of the United Nations?',
                'options' => ['1972', '1974', '1975', '1971'],
                'correct' => 1,
                'explanation' => 'Bangladesh joined the UN as its 136th member on 17 September 1974.',
            ],
        ];

        $rivers = [
            [
                'stem' => 'Which river is known as the Padma after it enters Bangladesh?',
                'options' => ['The Ganges', 'The Brahmaputra', 'The Meghna', 'The Teesta'],
                'correct' => 0,
                'explanation' => 'The Ganges is called the Padma inside Bangladesh.',
            ],
            [
                'stem' => 'The Brahmaputra is known by which name in Bangladesh after it enters the country?',
                'options' => ['Jamuna', 'Padma', 'Surma', 'Karnaphuli'],
                'correct' => 0,
                'explanation' => 'The main channel of the Brahmaputra flows through Bangladesh as the Jamuna.',
            ],
        ];

        $networks = [
            [
                'stem' => 'Which device operates at the data link layer of the OSI model?',
                'options' => ['Switch', 'Router', 'Repeater', 'Gateway'],
                'correct' => 0,
                'explanation' => 'A switch forwards frames using MAC addresses, which is layer 2 — the data link layer.',
            ],
            [
                'stem' => 'How many layers does the OSI reference model have?',
                'options' => ['7', '5', '4', '6'],
                'correct' => 0,
                'explanation' => 'Physical, data link, network, transport, session, presentation, application.',
            ],
            [
                'stem' => 'Which protocol is used to transfer email between mail servers?',
                'options' => ['SMTP', 'POP3', 'IMAP', 'FTP'],
                'correct' => 0,
                'explanation' => 'SMTP handles server-to-server mail transfer; POP3 and IMAP are retrieval protocols.',
            ],
        ];

        $numbers = [
            [
                'stem' => 'The binary number 1101 is equal to which decimal number?',
                'options' => ['13', '11', '15', '12'],
                'correct' => 0,
                'explanation' => '1×8 + 1×4 + 0×2 + 1×1 = 13.',
            ],
            [
                'stem' => 'How many bits make up one byte?',
                'options' => ['8', '4', '16', '32'],
                'correct' => 0,
                'explanation' => 'One byte is 8 bits.',
                'difficulty' => 'easy',
            ],
        ];

        $physiology = [
            [
                'stem' => 'How many chambers does the human heart have?',
                'options' => ['4', '2', '3', '6'],
                'correct' => 0,
                'explanation' => 'Two atria and two ventricles.',
                'difficulty' => 'easy',
            ],
            [
                'stem' => 'Which vitamin is synthesised in human skin on exposure to sunlight?',
                'options' => ['Vitamin D', 'Vitamin A', 'Vitamin C', 'Vitamin K'],
                'correct' => 0,
                'explanation' => 'Ultraviolet B converts 7-dehydrocholesterol in the skin into vitamin D3.',
            ],
        ];

        $light = [
            [
                'stem' => 'What is the approximate speed of light in vacuum?',
                'options' => ['3 × 10⁸ m/s', '3 × 10⁶ m/s', '3 × 10¹⁰ m/s', '3 × 10⁵ m/s'],
                'correct' => 0,
                'explanation' => 'Roughly 299,792,458 metres per second.',
            ],
        ];

        $percentage = [
            [
                'stem' => 'If the price of a product increases by 20% and then decreases by 20%, what is the net change?',
                'options' => ['4% decrease', 'No change', '4% increase', '2% decrease'],
                'correct' => 0,
                'explanation' => '1.20 × 0.80 = 0.96, a net 4% decrease.',
            ],
            [
                'stem' => 'A sum of money doubles in 8 years at simple interest. What is the annual rate?',
                'options' => ['12.5%', '10%', '8%', '15%'],
                'correct' => 0,
                'explanation' => 'Doubling means interest equals principal: rate = 100/8 = 12.5% per year.',
            ],
        ];

        $partsOfSpeech = [
            [
                'stem' => 'In the sentence "She sings beautifully", the word "beautifully" is a —',
                'options' => ['Adverb', 'Adjective', 'Noun', 'Preposition'],
                'correct' => 0,
                'explanation' => 'It modifies the verb "sings", so it is an adverb.',
                'difficulty' => 'easy',
            ],
        ];

        $governance = [
            [
                'stem' => 'Which of the following is NOT generally listed as a pillar of good governance?',
                'options' => ['Profit maximisation', 'Accountability', 'Transparency', 'Rule of law'],
                'correct' => 0,
                'explanation' => 'Good governance frameworks centre on accountability, transparency, participation and the rule of law.',
            ],
        ];

        // topic slug => [questions, the years each was examined]
        $map = [
            'liberation-war-of-1971' => [$liberation, [[2012, 2018, 2023], [2015, 2021], [2013, 2019, 2024], [2016, 2022], [2020]]],
            'language-movement' => [$language, [[2014, 2021], [2017]]],
            'constitution-of-bangladesh' => [$constitution, [[2015, 2022], [2019]]],
            'united-nations-and-its-organs' => [$un, [[2013, 2018, 2023], [2016, 2021]]],
            'rivers-of-bangladesh' => [$rivers, [[2014, 2020], [2018]]],
            'computer-networks' => [$networks, [[2017, 2022], [2015, 2019, 2023], [2021]]],
            'number-systems' => [$numbers, [[2016, 2020, 2024], [2018]]],
            'human-physiology' => [$physiology, [[2013, 2019], [2017, 2022]]],
            'physics-light-and-sound' => [$light, [[2015, 2021]]],
            'arithmetic-percentage-and-ratio' => [$percentage, [[2016, 2020, 2023], [2018, 2022]]],
            'english-grammar-parts-of-speech' => [$partsOfSpeech, [[2014, 2019, 2024]]],
            'concepts-of-good-governance' => [$governance, [[2020, 2023]]],
        ];

        $rows = [];

        foreach ($map as $topicSlug => [$questions, $yearSets]) {
            foreach ($questions as $index => $question) {
                foreach ($yearSets[$index] ?? [] as $year) {
                    $rows[] = array_merge($question, [
                        'topic' => $topicSlug,
                        'year' => $year,
                        'session' => $this->sessionLabel($year),
                    ]);
                }
            }
        }

        return $rows;
    }

    /** Approximate BCS session numbering for display purposes. */
    private function sessionLabel(int $year): string
    {
        $ordinal = 34 + ($year - 2013);

        return "{$ordinal}th BCS Preliminary ({$year})";
    }
}
