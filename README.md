# Examora

An exam intelligence platform. Not an AI summariser — the product answers one
question for a candidate:

> **Teach me only what has appeared before, and what is most likely to appear again.**

Launch vertical is **BCS Preliminary**. The platform is exam-agnostic; adding
FCPS, medical/engineering admissions or bank jobs is content work, not a rebuild.

---

## What exists today

A working vertical slice, end to end:

```
sign in  →  pick exam + target date  →  chapters ranked by exam probability
         →  AI micro-lesson built only from indexed past questions
         →  MCQ check with explanations
         →  SM-2 spaced repetition scheduling
         →  daily plan + readiness score on the dashboard
         →  timed mock exam under real conditions, with post-exam analysis
```

Everything runs with **no API keys and no spend** — the AI gateway falls back to
a deterministic `mock` driver that builds its output from real database context.

| Piece | Where | State |
|---|---|---|
| SaaS foundation (auth, billing, roles, admin, marketing site) | `backend/` (Wave / Laravel 12) | Installed, green |
| Exam knowledge schema + BCS taxonomy | `backend/database/migrations`, `BcsPreliminarySeeder` | Working |
| Exam intelligence (importance, ★ probability, expected questions) | `App\Services\Learning\TopicImportanceScorer` | Working |
| AI gateway (OpenAI / Gemini / Claude / mock, routing, fallback, cache, cost ledger) | `App\Services\Ai` | Working |
| RAG retrieval (pgvector, portable fallback) | `App\Services\Knowledge` | Working |
| Learning engine (lessons, quiz, SM-2, planner, readiness) | `App\Services\Learning` | Working |
| Mock exams (two modes, server clock, negative marking, 4-part analysis) | `QuizEngine`, `MockAnalyser` | Working |
| Document ingestion (PDF → text/vision → questions → review queue) | `App\Services\Ingestion` | Working |
| Prediction engine (topic forecast + verbatim repeats, backtested) | `App\Services\Prediction` | Working |
| Tiers, entitlements and feature gating | `App\Support\Entitlements` | Working |
| Payments (Stripe/Paddle credentials, checkout, bKash) | Wave | **Not live** |
| JSON API | `routes/api.php` → `/api/v1/*` | Working |
| React SPA | `web/` | Working |
| Test suite | `backend/tests` | 262 passing |

Not built yet: Bangla, React Native shell, payment go-live. See
[`docs/roadmap.md`](docs/roadmap.md).

---

## Running it

Prerequisites: PHP 8.2+, Composer, Node 20+, Docker.

```bash
# 1. Infrastructure (Postgres + pgvector, Redis, Meilisearch, Mailpit)
cd docker && docker compose up -d

# 2. Backend
cd ../backend
composer install
php artisan key:generate
php artisan jwt:secret
php artisan migrate --seed                       # Wave base data
php artisan db:seed --class=BcsPreliminarySeeder # BCS taxonomy + question corpus
php artisan examora:bootstrap                    # index → score → forecast → lessons
php artisan serve                                # http://localhost:8000

# 3. Queue worker — required for document uploads, easy to forget
cd backend && php artisan queue:work

# 4. Frontend
cd ../web
npm install
npm run dev                                      # http://localhost:5173
```

Then walk the features with [`docs/testing.md`](docs/testing.md).

Sign in with the seeded account: `admin@admin.com` / `password`.

Ports are deliberately offset (Postgres `5433`, Redis `6380`, Meilisearch
`7701`) so Examora can run beside other local projects.

### Turning on a real model

Everything works on the mock driver. To use a real provider, set any of these in
`backend/.env` — the gateway picks up whichever are configured and routes per
task, falling back automatically:

```env
OPENAI_API_KEY=
GEMINI_API_KEY=
ANTHROPIC_API_KEY=
AI_DEFAULT_PROVIDER=gemini
```

Then regenerate: `php artisan examora:bootstrap --force-lessons`.

### Tests

```bash
cd backend && php artisan test     # 262 passing
cd web && npm run build            # type-check + bundle
```

The suite runs on a SQLite file built by `tests/bootstrap.php`; no services
needed. Run `DB_CONNECTION=pgsql php artisan test` against a scratch database to
exercise the pgvector path.

---

## The one idea worth protecting

The moat is not the model. Any competitor can call the same LLMs.

The moat is the **knowledge graph**: past papers, syllabus, topic relationships,
question weighting, and student performance data — plus the scoring layer that
turns them into "study this, ignore that". The LLM is the interface to that
asset, not the asset.

Concretely, this is why `TopicImportanceScorer` is deterministic and explainable
rather than a model call: a student must be able to see *why* a chapter is five
stars, and a content editor must be able to argue with it.

---

## Documentation

| Doc | What it covers |
|---|---|
| [`docs/architecture.md`](docs/architecture.md) | System shape, request paths, why the pieces sit where they do |
| [`docs/database-design.md`](docs/database-design.md) | Every table, and the modelling decisions behind them |
| [`docs/ai-gateway.md`](docs/ai-gateway.md) | Provider routing, fallback, caching, cost accounting, the mock driver |
| [`docs/learning-engine.md`](docs/learning-engine.md) | Importance scoring, lesson generation, SM-2, planner, readiness |
| [`docs/ingestion.md`](docs/ingestion.md) | PDF → questions, the review queue, and the quarantine boundary |
| [`docs/prediction.md`](docs/prediction.md) | The forecast model, its assumptions, and how it is backtested |
| [`docs/api.md`](docs/api.md) | Endpoint reference |
| [`docs/testing.md`](docs/testing.md) | How to run the suite, and a manual walkthrough of every feature |
| [`docs/corpus.md`](docs/corpus.md) | Importing a real question bank, and checking it is fit to score |
| [`docs/saas.md`](docs/saas.md) | Tiers, entitlements, feature gating — and what is still missing for payments |
| [`docs/decisions.md`](docs/decisions.md) | Decision log, including deviations from the original plan |
| [`docs/status.md`](docs/status.md) | Feature-by-feature state, test coverage, and the gaps |
| [`docs/roadmap.md`](docs/roadmap.md) | What is built, what is next, in what order |

---

## Repository layout

```
examora/
├── backend/     Laravel 12 + Wave — API, learning engine, admin, billing
├── web/         React 19 + TypeScript + Vite — student SPA
├── docker/      Postgres (pgvector), Redis, Meilisearch, Mailpit
└── docs/        Architecture and design documentation
```
