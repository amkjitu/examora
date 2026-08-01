# Roadmap

## Done — the vertical slice

Sign in → pick exam and target date → chapters ranked by exam probability → AI
micro-lesson grounded only in indexed past questions → MCQ check with
explanations → SM-2 scheduling → daily plan and readiness on the dashboard.

Plus: AI gateway with four providers, routing, fallback, caching and a cost
ledger; pgvector RAG with a portable fallback; free-tier metering; 172 passing
tests; Docker infrastructure; CI.

---

## Next — in this order

### 1. Real question corpus (blocking everything)

Nothing downstream is trustworthy until this is done. Star ratings, expected
question counts and readiness scores are all functions of `past_questions`.

- Source and license BCS past papers, ideally 20+ years
- Verify answers and explanations against an authoritative source
- Replace `BcsPreliminarySeeder`'s question set
- Re-run `examora:bootstrap` and sanity-check the resulting rankings against what
  experienced BCS coaches would say — if the top ten chapters look wrong to a
  coach, the scorer weights need tuning, not the coach

### ~~2. Document ingestion~~ — done

PDF upload → text layer or vision transcription → question extraction → topic
classification → fingerprint dedupe → Filament review queue → `past_questions`.
Student uploads are quarantined and can never reach the shared corpus without an
admin marking the document shared. See [`ingestion.md`](ingestion.md).

Private uploads are isolated by `questions.owner_id` behind a global scope, and
surfaced to their owner at `GET /v1/my-questions` with a practice entry point.

Still open on this piece:

- Page-range chunking for PDFs beyond a provider's page limit

### ~~3. Mock exam UI~~ — done

Two modes (strict exam conditions / practice), server-authoritative timer with
resume and auto-submit, answer-sheet grid with flag-for-review, negative marking,
and four-part analysis: subject breakdown, full question review, pacing, and
readiness delta. See [`api.md`](api.md) and D13–D15 in
[`decisions.md`](decisions.md).

### 4. Bangla

Schema is locale-aware; nothing is translated. The BCS audience will expect
Bangla lessons and a Bangla interface. Needs a Bangla-capable model for
generation and a translation pass on the UI.

### ~~5. Prediction engine~~ — done

Poisson forecast adjusted for overdue-ness against each topic's own cycle, with
confidence from sample size and gap regularity, stored evidence, and verbatim
repeat candidates. Backtested on every bootstrap run against a 0.25 baseline.
See [`prediction.md`](prediction.md).

Near-duplicate detection now catches rewordings: a question changed between
sittings still counts as a return, with suspected clusters surfaced in the admin
for confirmation rather than merged.

Still open on this piece:

- Current-affairs coupling — the model sees cycles, not the news, which is
  exactly what International Affairs tracks
- The 0.93 similarity threshold is tuned against mock embeddings; re-measure
  against a real provider and a licensed corpus

### 6. Mobile

React Native over the same `/api/v1` — no new API work, which is why the SPA was
built API-first. Offline lesson caching matters for this audience.

### 7. Payments go-live

Wave ships Stripe and Paddle. Needs: real plans, pricing appropriate to
Bangladesh, local payment methods (bKash/Nagad — likely a custom driver), and the
subscription gates verified end to end.

---

## Later

- Leaderboards, friend challenges, study groups
- AI tutor conversation history and follow-ups
- Second exam vertical (FCPS or medical admission) to prove the platform is
  genuinely exam-agnostic
- Coaching-centre accounts (B2B) — likely the higher-margin channel
- Extract document ingestion into its own service if volume demands

---

## Sequencing principle

Resist adding exams. One exam done exceptionally well is worth more than five
done adequately, because the second exam is content work on a proven engine while
five mediocre verticals are five sets of unhappy students.

The engine is already exam-agnostic. Prove it works for BCS first.
