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
| Daily planner | 🟠 | **`DailyPlanner` is not named in any test.** Exercised only incidentally through the dashboard |
| Readiness / mastery | 🟠 | **`ProgressTracker` is not named in any test.** The mastery formula (accuracy + coverage + retention) is unasserted |
| Dashboard | 🟡 | 2 calls; the shape is checked, the numbers are not |
| Gamification (XP, streak, level) | 🟡 | Asserted incidentally in the journey test; no direct test of the level curve or streak-break logic |

### Mock exams

| Feature | State | Tests |
|---|---|---|
| Strict / practice modes | 🟢 | `MockExamTest` (12) |
| Server-authoritative clock, resume, auto-submit | 🟢 | Covered including tab-close and expiry |
| Negative marking | 🟢 | Including the negative-score case |
| Answer-key suppression in strict mode | 🟢 | Asserted absent from the payload |
| Four-part analysis | 🟡 | 5 analysis calls cover subjects, review, pacing, readiness — but **`MockAnalyser` has no direct unit test**, so the pacing thresholds and subject arithmetic are only checked end to end |

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
| RAG retrieval | 🟡 | `Retriever` referenced once; **the pgvector path is never asserted**, only the SQLite fallback |

### Monetisation

| Feature | State | Tests |
|---|---|---|
| Tiers, entitlements, feature gating | 🟢 | `EntitlementsTest` (19), both directions |
| Free-tier metering | 🟢 | |
| SPA locks and upgrade paths | 🟠 | No frontend tests at all |
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

**262 passing.** Roughly split:

| | Count |
|---|---|
| Examora's own tests | ~111 |
| Wave's inherited suite | ~107 |
| Pest/Wave scaffolding | remainder |

| File | Tests |
|---|---|
| `Api/EntitlementsTest` | 19 |
| `Domain/CorpusToolingTest` | 19 |
| `Ingestion/IngestionPipelineTest` | 18 |
| `Domain/PredictionEngineTest` | 14 |
| `Api/MockExamTest` | 12 |
| `Domain/NearDuplicateTest` | 8 |
| `Api/StudentJourneyTest` | 6 |
| `Domain/AiGatewayTest` | 5 |
| `Domain/RevisionSchedulerTest` | 4 |
| `Domain/TopicImportanceScorerTest` | 4 |
| `Api/FreeTierQuotaTest` | 2 |

---

## 3. Testing: the actual gaps

Ordered by how much damage the gap can hide.

### 🔴 Priority 1 — no frontend tests whatsoever

```
frontend test files: 0
e2e test files:      0
```

Every UI behaviour is verified by hand and nothing stops a regression. The
riskiest are the ones where the UI carries real correctness:

- **The mock exam timer.** Countdown, auto-submit at zero, resume after a tab
  close, and the answer-sheet grid. A silent break here loses a student a paper.
- **Entitlement locks.** They default to closed while loading; a change that
  flips the default would leak premium features and no test would notice.
- **Strict mode.** The client must not render feedback it does not receive.
- **Quota and 402 handling.** A blank screen instead of an upgrade prompt is a
  lost customer.

**What to add:** Vitest + Testing Library for the timer, locks and 402 handling;
Playwright for one full journey (sign in → lesson → quiz → mock → analysis).

### 🟠 Priority 2 — untested backend classes that produce numbers students act on

| Class | What is unasserted |
|---|---|
| `ProgressTracker` | The mastery blend (0.45 accuracy + 0.25 coverage + 0.30 retention) and the readiness weighting. **These drive the readiness score a student plans around.** |
| `DailyPlanner` | The time-pressure filter — with ≤14 days it should hard-filter to ★★★★+. Nothing checks that it does |
| `MockAnalyser` | Pacing thresholds and subject-breakdown arithmetic |
| `PageExtractor` | The text-layer vs vision decision, and the vision fallback |

Each is a couple of hours and closes a gap where a wrong number would look
perfectly plausible.

### 🟠 Priority 3 — paths that only run in production

- **Real LLM providers.** Request shaping for OpenAI, Gemini and Anthropic has
  never been checked against a live API. Add contract tests using recorded
  fixtures, or a `--live` suite run manually with keys.
- **pgvector retrieval.** CI migrates and seeds against Postgres but never runs
  the suite there, so the ANN path is never exercised. One CI job change.
- **Queue failure handling.** `ProcessSourceDocument::failed()` is never invoked
  in a test.
- **51 Filament classes, 0 tests.** The review queue is where a human prevents a
  bad answer key entering the corpus; if the approve button silently breaks,
  nothing catches it.

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
| 1 | Vitest + Testing Library: timer, locks, 402 handling | ~half a day | Highest-risk untested surface |
| 2 | Direct tests for `ProgressTracker`, `DailyPlanner`, `MockAnalyser` | ~half a day | These produce numbers students plan around |
| 3 | CI job running the full suite on Postgres | ~1 hour | Exercises pgvector; would have caught the `HAVING` bug |
| 4 | Playwright: one end-to-end journey | ~half a day | Catches integration breaks nothing else sees |
| 5 | Filament smoke tests (queue loads, approve works) | ~2 hours | Protects the corpus gate |
| 6 | Provider contract tests with recorded fixtures | ~half a day | The only untested path that costs money when wrong |

Items 1–3 are the ones I would not ship without.

---

## 5. The caveat that outranks all of this

Test coverage measures whether the **machinery** behaves. It cannot tell you
whether the output is **right**, because every number the product shows is a
function of `past_questions` — and that table is representative sample data, not
verified BCS papers.

A perfect test suite over an invented corpus still produces confident, wrong star
ratings. See [`corpus.md`](corpus.md).
