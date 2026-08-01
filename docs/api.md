# API reference

Base URL `/api`. Authentication is JWT bearer, issued by Wave.

```
Authorization: Bearer <access_token>
Accept: application/json
```

---

## Auth (Wave)

| Method | Path | Notes |
|---|---|---|
| POST | `/api/login` | `{email, password}` → `{access_token, token_type, expires_in}` |
| POST | `/api/register` | `{name, email, password, password_confirmation}` |
| POST | `/api/refresh` | Requires a valid (possibly expired) token |
| POST | `/api/logout` | Invalidates the token |

---

## Public

### `GET /api/v1/exams`

Exam catalogue with subjects and indexed question counts.

### `GET /api/v1/exams/{slug}`

### `GET /api/v1/exams/{slug}/topics`

Chapters ranked by exam probability. Query: `subject_id`, `limit` (≤200).
Sending a bearer token additionally returns the caller's mastery per chapter.

```json
{
  "data": [{
    "id": 9,
    "name": "Liberation War of 1971",
    "subject": { "name": "Bangladesh Affairs", "slug": "bangladesh-affairs" },
    "question_count": 11,
    "probability_stars": 5,
    "importance_score": 78.4,
    "estimated_minutes": 15,
    "expected_questions": "0-2",
    "last_examined": 2024,
    "mastery": 0.31,
    "lesson_completed": true
  }]
}
```

### `GET /api/v1/exams/{slug}/predictions`

Forecast for the next sitting, plus verbatim-repeat candidates. Query: `limit`.

The response always carries its own caveats — a client cannot render the ranking
without them:

```json
{
  "target_year": 2025,
  "disclaimer": "A forecast of what past papers suggest, not a syllabus…",
  "freshness": {
    "last_session_in_bank": 2024,
    "forecasting": 2025,
    "sessions_behind": 1,
    "is_current": false
  },
  "topics": [{
    "rank": 1,
    "topic": { "name": "Liberation War of 1971", "subject": "Bangladesh Affairs" },
    "appearance_probability": 0.5876,
    "expected_questions": 0.886,
    "expected_range": [0.0, 2.03],
    "confidence": 0.79,
    "confidence_label": "high",
    "trend": "rising",
    "is_overdue": false,
    "evidence": ["Asked in 2019, 2020, 2021, 2022, 2023, 2024 and earlier.", "…"]
  }],
  "repeat_candidates": [{
    "stem": "How many layers does the OSI reference model have?",
    "answer": "7",
    "years": [2015, 2019, 2023],
    "appearances": 3,
    "mean_gap_years": 4,
    "score": 0.76,
    "evidence": ["Asked verbatim in 2015, 2019, 2023.", "…"]
  }]
}
```

`data` is null with an explanatory message when no forecast has been computed.

### `GET /api/v1/exams/{slug}/predictions/backtest`

Holds out the most recent session, forecasts it from everything before, and
reports the Brier score against a 0.25 always-guess-50% baseline. Public on
purpose: anyone relying on these numbers should be able to check them.

---

## Authenticated

### `GET /api/v1/me`

Profile, subscription state, and remaining free-tier lesson quota.

### `POST /api/v1/exams/{slug}/enroll`

```json
{ "target_session": "BCS 48", "target_date": "2026-11-01", "daily_minutes_goal": 20 }
```

Deactivates any other enrollment — one target at a time, by design. Returns
`days_left`, which drives how selective the planner is.

### `GET /api/v1/dashboard`

Everything the home screen needs in one round trip: target, days left, today's
plan with reasons, streak/XP/level, readiness, revision due, weakest chapters.
Pass `?refresh=1` to regenerate today's plan.

### `POST /api/v1/plan-items/{item}/complete`

### `GET /api/v1/topics/{topic}/lesson`

Fetch or generate the chapter's lesson. Idempotent and cached — the first caller
pays the latency.

Rate-limited by `EnforceLessonQuota` for free users. On exhaustion:

```
402 Payment Required
{
  "message": "You've used all 3 free lessons for today.",
  "code": "lesson_quota_exceeded",
  "quota": { "limit": 3, "used": 3, "resets_at": "2026-08-02T00:00:00+00:00" }
}
```

Successful responses carry `X-Examora-Lesson-Quota-Limit` / `-Used`.

### `POST /api/v1/lessons/{lesson}/complete`

Marks the lesson done, awards XP, touches the streak.

### `POST /api/v1/quizzes`

```json
{ "type": "lesson_check", "lesson_id": 1 }
{ "type": "practice",     "topic_id": 9,  "count": 10 }
{ "type": "revision",     "exam_id": 1,   "count": 15 }
{ "type": "mock",         "exam_id": 1,   "count": 100, "mode": "strict" }
```

Returns the session with its questions. **The answer key is never included** —
`correct_index` and `explanation` are absent until the question is answered.

Mocks additionally accept `mode` (`strict` | `practice`, default `strict`) and
`duration_minutes`. When duration is omitted the clock scales with the paper, so
a 25-question mock keeps the same seconds-per-question pressure as the full one.

A mock session carries the timer and the marking scheme:

```json
{
  "mode": "strict",
  "seconds_remaining": 419,
  "duration_minutes": 7,
  "negative_marking": 0.5
}
```

`seconds_remaining` is recomputed server-side on every read from `started_at`,
so closing the tab does not pause the clock. Treat the client countdown as
display only.

`422` when there is nothing to serve (no due revision, empty bank).

### `GET /api/v1/quizzes/{session}`

Resume a session. Questions come back in the engine's chosen order, each flagged
`answered` / `selected_index`.

### `POST /api/v1/quizzes/{session}/answer`

```json
{ "question_id": 12, "selected_index": 1, "time_ms": 4200, "confidence": 4 }
```

`time_ms` and `confidence` are optional but feed SM-2 quality inference — send
them if the client can.

In practice mode (and every non-mock quiz):

```json
{
  "data": {
    "recorded": true,
    "mode": "practice",
    "is_correct": true,
    "correct_index": 1,
    "explanation": "The country was divided into 11 sectors…",
    "previously_examined": [2019, 2024],
    "next_review_in_days": 1,
    "seconds_remaining": null
  }
}
```

Under **strict** exam conditions the answer is recorded but nothing is revealed:

```json
{ "data": { "recorded": true, "mode": "strict", "seconds_remaining": 405 } }
```

This is enforced server-side. There is no request that returns the key before
submission.

`403` if the session belongs to another user.
`422` once the clock has run out — submit the paper to see the result.

### `POST /api/v1/quizzes/{session}/complete`

Scores the session, updates mastery, awards XP, returns the level/streak state.
Mocks also return `wrong`, `skipped`, `marks`, `max_marks` and `penalty_marks`.

Negative marking applies to mocks only, and `marks` may legitimately be
**negative** — that is the honest signal about guessing, so it is not floored.

### `GET /api/v1/quizzes/{session}/analysis`

Post-exam analysis. `422` with `code: session_incomplete` before submission.
A paper whose clock expired while the tab was closed is submitted automatically
on the next read rather than left stranded.

Four sections:

| Section | Answers |
|---|---|
| `summary` | Marks, attempted/skipped, penalty, accuracy on attempted |
| `subjects` | Score per subject against its real exam weight, weakest first |
| `review` | Every question with your answer, the key, explanation, past sessions, time taken |
| `pacing` | Median/mean/budget, `time_sinks` (slow **and** wrong), `rushed_mistakes` (fast **and** wrong) |
| `readiness` | Estimated score before → after, delta, and the chapters to fix next |

### `GET /api/v1/my-questions`

Questions extracted from the caller's own uploads, grouped by topic and ready to
practise. Query: `topic_id`.

Scoped by ownership, not by a filter the caller passes — there is no request
shape that returns another student's private questions. The answer key is never
included: these feed practice, and a student who can read the answers first is
not practising.

Private questions also appear in that student's own practice and mock papers, and
in **nobody else's** — enforced by a global scope on the model rather than by
each query remembering.

### `POST /api/v1/tutor/ask`

```json
{ "question": "I don't understand osmosis", "exam_id": 1, "topic_id": 22 }
```

Answers at exam level, grounded in retrieved past questions, with sources.

---

## Errors

| Status | Meaning |
|---|---|
| 401 | Missing/expired token. The SPA clears the session and redirects |
| 402 | Free-tier lesson allowance exhausted — metered, not blocked |
| 403 | Resource belongs to another user |
| 422 | Validation failure, or nothing available to serve |
| 429 | Rate limited (60/min per user) |
