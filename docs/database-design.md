# Database design

PostgreSQL 16 with pgvector. Four migrations, grouped by concern.

## Taxonomy — `2026_08_01_000100`

```
exams ──< subjects ──< topics ──< topics (self-referencing)
                          │
                          ├──< past_questions
                          └──1 topic_statistics
```

| Table | Purpose |
|---|---|
| `exams` | BCS Preliminary, FCPS, … with marks, duration, negative marking |
| `subjects` | Bangla (35), English (35), Bangladesh Affairs (30)… `marks` is the exam weight and feeds importance scoring |
| `topics` | Self-referencing. Root rows are topics; children are chapters — the atomic learnable unit |
| `past_questions` | The corpus. The single most valuable table in the system |
| `topic_statistics` | Denormalised exam intelligence. Powers "★★★★★" |

**Why self-referencing topics rather than a separate `chapters` table.** The plan
listed subjects → topics → chapters. Different exams nest to different depths;
a fixed fourth table would be empty for some and insufficient for others. A
`parent_id` plus a `level` enum (`topic` | `chapter`) covers both without a
migration per exam format.

**`past_questions.fingerprint`** is a normalised hash of stem + sorted options
(case, punctuation and option order removed). It is what lets the system see that
a question printed in 2012, 2018 and 2023 is *one fact examined three times* —
the strongest importance signal there is. Without it, importance scoring counts
reprints as separate facts and the whole ranking is wrong.

**`topic_statistics` is a projection**, recomputed by `examora:bootstrap`, not a
view. "Rank every chapter in this exam by probability" is the most-hit query in
the product; it must not be a live aggregate over the corpus.

## Knowledge — `2026_08_01_000200`

`knowledge_chunks` — embedded text for retrieval.

The `embedding` column branches on driver:

| Driver | Column | Index | Search |
|---|---|---|---|
| PostgreSQL | `vector(1536)` | HNSW, cosine | `embedding <=> ?::vector` |
| SQLite / other | `json` | none | in-PHP cosine scan, scoped by exam/topic |

HNSW rather than IVFFlat because IVFFlat needs data before it helps, and this
corpus grows continuously.

Chunks store the *fact the examiner tested* — stem, correct answer, explanation,
session — not raw question text. That is what makes retrieval surface
exam-relevant facts rather than phrasing. `checksum` lets re-indexing skip
unchanged rows.

## Learning — `2026_08_01_000300`

| Table | Notes |
|---|---|
| `lessons` | Unique on `(topic, exam, locale, version)`. Content, not personalisation — shared by all students |
| `lesson_cards` | Ordered cards: fact, mnemonic, timeline, comparison, pitfall |
| `questions` | Practice bank. Past questions promoted in, plus AI-generated variants. Tracks `times_served`/`times_correct`/`p_value` |
| `quiz_sessions` | `question_ids` is a JSON array so the engine's chosen order survives the round trip |
| `quiz_answers` | Includes `time_ms` and optional `confidence` — both feed SM-2 quality inference |
| `memory_items` | SM-2 state, one row per `(user, question)`. Indexed on `(user_id, due_at)` — the revision query |

`questions` is separate from `past_questions` on purpose. `past_questions` is
archival source-of-truth evidence about the exam; `questions` is a mutable
serving bank with usage statistics. Mixing them would mean student answer
counters mutating the historical record.

## Student progress — `2026_08_01_000400`

| Table | Notes |
|---|---|
| `exam_enrollments` | One active target per student. Drives the planner's time pressure |
| `topic_mastery` | Blends accuracy, coverage and retention — not just accuracy |
| `study_plans` / `study_plan_items` | Today's plan. Items carry a human-readable `reason` shown in the UI |
| `learner_profiles` | XP, level, coins, streak, readiness, estimated score |
| `learning_events` | Append-only XP/activity log. Also the free-tier quota meter |
| `ai_requests` | Per-call cost ledger — see [`ai-gateway.md`](ai-gateway.md) |

`learning_events` doubles as the quota meter deliberately: counting
`lesson_completed` events for today is one indexed query and there is no separate
counter to drift out of sync.

## Conventions

- Foreign keys are constrained, cascading on delete for owned data and nulling
  for optional references.
- Timestamps everywhere; domain time (`due_at`, `occurred_at`, `plan_date`) is
  explicit and separate from `created_at`.
- JSON columns are used where the shape is genuinely open (`meta`, `payload`,
  `years`) and never where a query needs to filter on it.
- Money is `decimal`, never float. Scores and probabilities are `decimal` with
  declared precision.

## Seed data warning

`BcsPreliminarySeeder` uses the **published BCS Preliminary mark distribution**,
which is accurate. Its **questions are representative sample data** written to
exercise the pipeline — not a verified transcription of official past papers.

Every number the product shows a student — star ratings, expected questions,
readiness — is only as trustworthy as this table. Replace it with a licensed,
verified corpus before launch.
