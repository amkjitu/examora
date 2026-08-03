# Status

Where every feature actually stands, and what testing it still needs.

Generated from the codebase, not from memory: test counts are per file, coverage
claims are "is this class named in a test", and gaps are things I checked for and
did not find.

**Legend**

| | Meaning |
|---|---|
| 🟢 **Done** | Built, tested, verified running |
| 🟡 **Built, thin tests** | Works and was verified by hand, but automated coverage is partial |
| 🟠 **Built, untested** | Code exists and runs; nothing asserts it stays working |
| 🔴 **Not built** | |
| ⛔ **Blocked** | Cannot be finished without something outside the codebase |

---

## 1. Feature status

### Core learning loop

| Feature | State | Tests |
|---|---|---|
| Auth, registration, JWT sessions | 🟢 | Wave's suite + used by every API test |
| Exam catalogue and enrolment | 🟡 | 1 enrol call; no test of switching exams or clearing an old enrolment |
| Chapter rankings (★ probability) | 🟢 | `TopicImportanceScorerTest` (4), 13 topic-list calls |
| Micro-lesson generation | 🟢 | `StudentJourneyTest`, 10 lesson calls; caching and grounding both asserted |
| MCQ quiz + explanations | 🟢 | 30 quiz calls, 10 answer calls |
| SM-2 spaced repetition | 🟢 | `RevisionSchedulerTest` (4) — curve, lapses, ease floor |
| Daily planner | 🟢 | `DailyPlannerTest` (14) — priority order, the days-left star filter, the minute budget, plan reuse |
| Readiness / mastery | 🟢 | `ProgressTrackerTest` (11) — the mastery blend, and that readiness is weighted by what the exam asks |
| Dashboard | 🟡 | 2 calls; the shape is checked, the numbers are now asserted at the service below it |
| Gamification (XP, streak, level) | 🟢 | `ProgressTrackerTest` — XP split, streak break vs extend, widening level gaps |

### Mock exams

| Feature | State | Tests |
|---|---|---|
| Strict / practice modes | 🟢 | `MockExamTest` (12) |
| Server-authoritative clock, resume, auto-submit | 🟢 | Covered including tab-close and expiry |
| Negative marking | 🟢 | Including the negative-score case |
| Answer-key suppression in strict mode | 🟢 | Asserted absent from the payload |
| Four-part analysis | 🟢 | `MockAnalyserTest` (11) — subject split, a skipped question is not a wrong one, accuracy on attempted, time sinks vs rushed mistakes |

### Document ingestion

| Feature | State | Tests |
|---|---|---|
| Upload, checksum dedupe, quarantine | 🟢 | `IngestionPipelineTest` (18) |
| Question extraction, dedupe, classification | 🟢 | |
| Review queue → `past_questions` | 🟢 | Approve, reject, corrections, idempotency |
| Private-question isolation | 🟢 | 3 regression tests after the leak |
| **Page text / vision extraction** | 🟠 | **`PageExtractor` is not named in any test.** The text-layer vs vision decision, and the vision fallback itself, are unasserted |
| Filament review UI | 🟠 | **0 tests across 51 Filament classes.** Verified by hand only |
| Page-range chunking for large PDFs | 🔴 | Provider page limits apply; split manually |

### Prediction

| Feature | State | Tests |
|---|---|---|
| Topic forecast (Poisson + overdue) | 🟢 | `PredictionEngineTest` (14) |
| Confidence and evidence | 🟢 | Including thin-data and irregular-cycle cases |
| Backtest | 🟢 | Asserted to beat the 0.25 baseline |
| Verbatim repeat candidates | 🟢 | |
| Near-duplicate detection | 🟢 | `NearDuplicateTest` (8) including the false-merge guard |
| Current-affairs coupling | 🔴 | The model sees cycles, not the news |

### Corpus tooling

| Feature | State | Tests |
|---|---|---|
| `examora:import` (CSV/JSON) | 🟢 | `CorpusToolingTest` (19) |
| `examora:corpus-health` | 🟢 | Fatal/problem/warning tiers all asserted |
| `examora:bootstrap` | 🟠 | The command wrapper has no test; the services under it do |

### AI layer

| Feature | State | Tests |
|---|---|---|
| Gateway routing, fallback, cost ledger | 🟢 | `AiGatewayTest` (5) |
| Prompt caching | 🟢 | Including zero-cost billing on a hit |
| Mock provider | 🟢 | Exercised by every other test |
| **Real providers (OpenAI/Gemini/Anthropic)** | 🟠 | **Never called for real.** Request shaping is unverified against live APIs — only the mock path is proven |
| RAG retrieval | 🟡 | `Retriever` referenced once. The suite now runs against pgvector in CI, so the real vector column and HNSW index are exercised — but no test asserts ANN ranking specifically |

### Monetisation

| Feature | State | Tests |
|---|---|---|
| Tiers, entitlements, feature gating | 🟢 | `EntitlementsTest` (19), both directions |
| Free-tier metering | 🟢 | |
| SPA locks and upgrade paths | 🟢 | `Locked.test` (7) — a gate stays shut while loading and on failure; `LessonPage.test` (4) — the 402 path |
| **Stripe / Paddle checkout** | ⛔ | Placeholder keys, `dummy_*` price IDs, never exercised |
| **Webhooks** | ⛔ | Wave has tests; ours are unverified against a real event |
| **bKash / Nagad** | 🔴 | Custom Cashier driver. The largest remaining piece |
| Trials | 🔴 | `onTrial()` grants premium but nothing sets `trial_ends_at` |

### Not started

| Feature | State | Note |
|---|---|---|
| Bangla content and UI | 🔴 | Schema is locale-aware; nothing translated. Table stakes for this audience |
| React Native app | 🔴 | API is already shaped for it |
| Leaderboards, study groups | 🔴 | |
| Second exam vertical | 🔴 | Deliberately deferred until BCS is proven |

---

## 2. Testing: what exists

**298 backend, 24 frontend.** The backend suite runs twice in CI — once on
SQLite, once on PostgreSQL + pgvector — because the two genuinely diverge.

| | Count |
|---|---|
| Examora's own backend tests | ~147 |
| Wave's inherited suite | ~107 |
| Pest/Wave scaffolding | remainder |
| Frontend (Vitest) | 24 |

| File | Tests |
|---|---|
| `Api/EntitlementsTest` | 19 |
| `Domain/CorpusToolingTest` | 19 |
| `Ingestion/IngestionPipelineTest` | 18 |
| `Domain/PredictionEngineTest` | 14 |
| `Domain/DailyPlannerTest` | 14 |
| `Api/MockExamTest` | 12 |
| `Domain/ProgressTrackerTest` | 11 |
| `Domain/MockAnalyserTest` | 11 |
| `Domain/NearDuplicateTest` | 8 |
| `Api/StudentJourneyTest` | 6 |
| `Domain/AiGatewayTest` | 5 |
| `Domain/RevisionSchedulerTest` | 4 |
| `Domain/TopicImportanceScorerTest` | 4 |
| `Api/FreeTierQuotaTest` | 2 |
| `web/pages/MockExamPage.test` | 11 |
| `web/components/Locked.test` | 7 |
| `web/pages/LessonPage.test` | 4 |

### What the new tests caught

Writing them was not a formality:

- **`DailyPlanner` looked up today's plan by string equality on a `date`
  column.** PostgreSQL normalises the value so it matched; SQLite stored
  `2026-08-03 00:00:00`, missed, and tried to insert a duplicate. Fixed with
  `whereDate`.
- **`RolesTableSeeder` deleted every row before reinserting**, which
  `plans.role_id` references. Re-seeding therefore failed on any database that
  enforces foreign keys. Now an upsert.
- **Two Wave test files ran `migrate:fresh` inside a transaction**, and
  `UsernameUpdateTest` depended on a user leaked from another file. All three
  passed on SQLite by accident and failed on PostgreSQL.

---

## 3. Testing: the actual gaps

Ordered by how much damage the gap can hide.

### 🔴 Priority 1 — no end-to-end test

Component tests now cover the timer, the locks and the 402 path, but nothing
exercises a whole journey through the real API. Integration breaks — a changed
response shape, a route that stops resolving — sit between the layers both
suites test.

**What to add:** Playwright, one journey: sign in → lesson → quiz → mock →
analysis.

### 🟠 Priority 2 — remaining untested classes

| Class | What is unasserted |
|---|---|
| `PageExtractor` | The text-layer vs vision decision, and the vision fallback |
| `ProcessSourceDocument::failed()` | Never invoked in a test |
| 51 Filament classes | The review queue is where a human prevents a bad answer key entering the corpus; if the approve button silently breaks, nothing catches it |

### 🟠 Priority 3 — paths that only run in production

- **Real LLM providers.** Request shaping for OpenAI, Gemini and Anthropic has
  never been checked against a live API. Add contract tests using recorded
  fixtures, or a `--live` suite run manually with keys.
- **pgvector ranking.** The suite now runs against pgvector in CI, so the column
  and index are exercised — but no test asserts that ANN retrieval returns the
  right neighbours, only that it returns something.

### 🟡 Priority 4 — thin coverage on working features

- `plan-items/{item}/complete` is **never called in a test**
- Exam switching and enrolment replacement
- Level curve and streak-break logic
- Tutor answer grounding (only the gate is tested, not that answers stay within
  retrieved context)

---

## 4. Suggested order

| # | Work | Effort | Why now |
|---|---|---|---|
| ~~1~~ | ~~Vitest + Testing Library: timer, locks, 402 handling~~ | done | 24 tests |
| ~~2~~ | ~~Direct tests for `ProgressTracker`, `DailyPlanner`, `MockAnalyser`~~ | done | 36 tests; found a real date bug |
| ~~3~~ | ~~CI job running the full suite on Postgres~~ | done | Found three more |
| 4 | Playwright: one end-to-end journey | ~half a day | Catches integration breaks nothing else sees |
| 5 | Filament smoke tests (queue loads, approve works) | ~2 hours | Protects the corpus gate |
| 6 | Provider contract tests with recorded fixtures | ~half a day | The only untested path that costs money when wrong |

Items 1–3 were the ones I would not have shipped without. They are done.

---

## 5. The caveat that outranks all of this

Test coverage measures whether the **machinery** behaves. It cannot tell you
whether the output is **right**, because every number the product shows is a
function of `past_questions` — and that table is representative sample data, not
verified BCS papers.

A perfect test suite over an invented corpus still produces confident, wrong star
ratings. See [`corpus.md`](corpus.md).
