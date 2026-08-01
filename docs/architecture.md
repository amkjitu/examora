# Architecture

## Shape

```
                 React SPA (web/)                React Native (later)
                        │                                 │
                        └──────────── JWT bearer ─────────┘
                                       │
        ┌──────────────────────────────▼──────────────────────────────┐
        │                    Laravel 12 + Wave                        │
        │                                                             │
        │  Wave: auth · subscriptions · billing · roles · admin ·     │
        │        marketing site · blog                                │
        │                                                             │
        │  Examora:                                                   │
        │    Http/Controllers/Api/V1   thin — validate, delegate      │
        │    Services/Learning         lessons, quiz, SM-2, planner   │
        │    Services/Knowledge        indexing + retrieval (RAG)     │
        │    Services/Ai               the only door to any LLM       │
        └──────────────────────────────┬──────────────────────────────┘
                                       │
        ┌──────────────┬───────────────┼───────────────┬──────────────┐
   PostgreSQL       Redis          Meilisearch     Object store   LLM providers
   + pgvector    cache/queue      (search, later)   (S3, later)   OpenAI/Gemini/
                                                                  Anthropic/mock
```

One Laravel monolith. Deliberately. Splitting into services before there is load
to split would cost velocity now for a scaling problem that does not exist yet.
The seams are drawn at the service-class boundary so extraction later is
mechanical: `Services/Ai` is already an isolated gateway with no domain
knowledge, and `Services/Knowledge` touches only its own tables.

## Why Wave stays, and where it stops

Wave supplies the parts of a SaaS that are tedious and solved: registration,
JWT-authenticated API sessions, Stripe/Paddle subscriptions, role permissions, a
Filament admin, a marketing site and blog. Rebuilding those would be weeks of
work with no product differentiation.

Wave's own presentation layer is Blade + Livewire + Folio. Examora's student
experience is a React SPA. These do not fight because they are separated by
responsibility rather than stacked:

- **Wave web routes** serve the marketing site, pricing, blog, billing pages and
  the Filament admin. Server-rendered, SEO-relevant, rarely interactive.
- **`/api/v1/*`** serves the student app. JSON only.

The SPA authenticates through Wave's existing JWT endpoints (`POST /api/login`),
so there is one identity system and one token — the same one a React Native
client will use later, with no parallel auth to build.

### Deviation from the original plan

The plan specified Sanctum. Wave ships `tymon/jwt-auth` and its API controllers
are already wired to it. Adding Sanctum alongside would mean two token systems,
two guards, and a login endpoint that has to pick. JWT was already there and is
the better fit for a mobile client anyway (stateless, no cookie/CSRF dance).
Recorded in [`decisions.md`](decisions.md).

## Request paths

### Opening a lesson — the expensive path

```
GET /api/v1/topics/{topic}/lesson
  → EnforceLessonQuota          free tier: metered, returns 402 with a quota body
  → LessonController
  → LessonGenerator
       ├─ existing lesson for (topic, exam, locale, prompt version)?  → return it
       ├─ facts(): past questions grouped by fingerprint, so a question repeated
       │           across four sessions becomes ONE fact with four year-stamps
       ├─ prompt = instructions + PromptContext::block(facts + statistics)
       ├─ AiGateway->complete()
       │     ├─ cache hit on prompt hash?             → return, bill zero
       │     ├─ walk configured providers for the task, first success wins
       │     └─ record provider/model/tokens/cost/latency to ai_requests
       └─ persist Lesson + LessonCards, promote past questions into the
          practice bank bound to this lesson
```

The critical property: **lessons are content, not personalisation**. One lesson
per (topic, exam, locale, prompt version), shared by every student.
Personalisation happens at *selection* time (which chapter, in what order) and
at *revision* time (which questions, when) — never at generation time. If
lessons were generated per student, LLM cost would scale with users instead of
with content, and the unit economics would never work.

### Answering a question — the hot path

```
POST /api/v1/quizzes/{session}/answer
  → QuizController                 ownership check
  → QuizEngine::answer
       ├─ record QuizAnswer, update the question's p-value
       └─ RevisionScheduler::record
             ├─ infer SM-2 quality from correctness + latency + confidence
             └─ update or create the memory_item, set the next due_at
```

No LLM call. No network. This runs hundreds of times per student per day, so it
is pure database work.

## Data flow: from a PDF to a ranked chapter

```
past_questions  ──┬─→ Indexer ──→ knowledge_chunks (embedded) ──→ Retriever
                  │                                                    │
                  └─→ TopicImportanceScorer ──→ topic_statistics        │
                                                    │                   │
                                     ★ probability, expected            │
                                     questions, study minutes           │
                                                    │                   │
                                                    ▼                   ▼
                                              DailyPlanner       LessonGenerator
                                                    │                   │
                                                    └──── API ──────────┘
```

`topic_statistics` is a denormalised projection, recomputed by
`examora:bootstrap` (and later by a scheduled job when new papers are ingested).
It exists because "rank every chapter in this exam by probability" is the single
most-hit query in the product and must not be a live aggregate over the whole
question corpus.

## Portability decisions that matter

**pgvector with a working fallback.** `knowledge_chunks.embedding` is a
`vector(1536)` column with an HNSW index on PostgreSQL, and a JSON column with an
in-PHP cosine scan everywhere else. The migration branches on the driver; the
`Retriever` picks the strategy at runtime. Consequence: the entire test suite and
CI run on SQLite with no services, while production gets real ANN search.

**The mock LLM provider is not a stub.** Generators embed their retrieved,
database-sourced context into the prompt as a delimited JSON block
(`PromptContext`). Real models read it as grounding data; the mock parses it and
builds its answer from the same real facts. One prompt, both paths — so the
offline path exercises the same code, and a developer with no API key still sees
a lesson about the Liberation War rather than lorem ipsum.

## Where this goes next

The monolith holds comfortably to the first several thousand paying students.
The first thing that will need to move is document ingestion (OCR + parsing of
uploaded PDFs), because it is CPU-heavy, bursty, and latency-tolerant — a queue
worker, then a separate service if volume demands. Nothing else should move
until it hurts.
