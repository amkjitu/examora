# Changelog

All notable changes to Examora are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Nothing is 1.0 until the question corpus is verified — see
[`docs/corpus.md`](docs/corpus.md).

---

## [0.5.0] — 2026-08-03

### Added

- **Frontend test suite.** Vitest, jsdom and Testing Library, with a harness
  (`web/src/test/harness.tsx`) that renders a page inside a router and a fresh
  query client and stubs `fetch` with a route table that throws on an unmatched
  request — a test cannot pass because nothing was called.
  - `MockExamPage.test` (11): countdown, auto-submit at zero and only once,
    strict mode renders no feedback, resume restores answers and trusts the
    server clock, answer sheet.
  - `Locked.test` (7): a gate stays shut while entitlements are loading and when
    the request fails — the failure mode is locked, not open.
  - `LessonPage.test` (4): a 402 arrives as an explanation with a way forward,
    other errors are not disguised as quota.
- **Direct tests for the three services that produce numbers students act on**,
  previously covered only end to end: `ProgressTrackerTest` (11),
  `DailyPlannerTest` (14), `MockAnalyserTest` (11).
- **CI runs the whole backend suite against PostgreSQL + pgvector**, not only
  the migrations. `npm test` added to the frontend job.

### Fixed

- **`DailyPlanner` could not find the plan it had just written.** It matched
  today's plan by string equality on a `date` column. PostgreSQL normalises the
  value so the lookup happened to work; SQLite stored
  `2026-08-03 00:00:00`, missed, and a second call for the same day hit the
  unique constraint. Now `whereDate`.
- **`RolesTableSeeder` deleted every row before reinserting**, but
  `plans.role_id` references `roles`. Re-seeding failed on any database that
  enforces foreign keys — that is, on production. Now an idempotent upsert.
- **Two inherited Wave test files ran `migrate:fresh` inside an open
  transaction**, and `UsernameUpdateTest` depended on a user left behind by
  another file. All three passed on SQLite by accident and failed the moment the
  suite met a real database.

### Changed

- `tests/bootstrap.php` builds the SQLite fixture only when SQLite is the
  driver; against any other connection the caller owns the schema.
- `phpunit.xml` documents why `DB_CONNECTION` is deliberately not forced.

---

## [0.4.0] — 2026-08-02

### Added

- Tiered entitlements as named features rather than subscription checks:
  `Feature` constants, an `Entitlements` service, and `RequiresFeature` /
  `EnforceLessonQuota` middleware.
- Free-tier metering — the tier meters lessons rather than walling the product;
  revision and practice stay open.
- SPA locks, upgrade paths and a plans surface.

### Fixed

- **Premium features were completely ungated.** Every paid surface was reachable
  by any signed-in user.
- **Public routes never resolved the bearer token**, so the chapter list showed
  0% mastery to everyone, signed in or not. Added `OptionalJwtUser`.

---

## [0.3.0] — 2026-08-02

### Added

- `examora:import` for bulk CSV/JSON question import, with provenance and
  answer-key validation.
- `examora:corpus-health`, reporting fatal / problem / warning tiers so the
  corpus can be judged before its numbers are believed.
- [`docs/corpus.md`](docs/corpus.md): the workflow, and the honest caveat about
  what the sample corpus can and cannot tell you.

### Fixed

- **Numeric answer ambiguity in the importer**: an option `"11"` with answer
  `"11"` was read positionally. Text match now runs first.
- **PostgreSQL rejects a select alias in `HAVING`** where SQLite accepts it.

---

## [0.2.0] — 2026-08-01

### Added

- Prediction engine: Poisson topic forecast with a renewal-process overdue
  adjustment, confidence bands, and evidence a student can argue with.
- Verbatim repeat candidates and near-duplicate detection over embeddings.
- Backtest reporting a Brier score, printed by `examora:bootstrap`.
- Document ingestion: upload, checksum dedupe, LLM extraction, quarantine, and a
  Filament review queue promoting questions into `past_questions`.

### Fixed

- **Private questions leaked into the shared bank.** The `meta` marker was never
  enforced. Replaced with an `owner_id` column, a global scope and three
  regression tests.
- **Near-duplicate clustering used identity embeddings** that included both
  provenance and the answer key, so conflicting-answer clusters — the ones worth
  finding — never formed.
- **The mock paper parser over-matched option markers** on stems like
  "Colonel M. A. G. Osmani".

---

## [0.1.0] — 2026-08-01

### Added

- Laravel 12 + Wave SaaS backend, React 19 + TypeScript SPA, PostgreSQL 16 with
  pgvector and a portable in-PHP cosine fallback.
- The core loop: exam enrolment, chapter rankings by exam probability,
  grounded micro-lessons, MCQ quizzes, SM-2 revision with inferred quality,
  daily planner, readiness and mastery.
- Mock exams: strict and practice modes, a server-authoritative clock, resume,
  auto-submit, negative marking, and four-part post-exam analysis.
- AI gateway across OpenAI, Gemini, Anthropic and a deterministic mock, with
  fallback, prompt caching and a cost ledger.
