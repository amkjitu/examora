# Decision log

Decisions taken while building the vertical slice, including the places where I
departed from the original plan and why.

---

## D1 — JWT instead of Sanctum

**Plan:** API-only Laravel with Sanctum tokens.
**Built:** Wave's existing `tymon/jwt-auth`.

Wave ships JWT auth and its `AuthController` (`/api/login`, `/register`,
`/refresh`, `/logout`) is already wired to it. Adding Sanctum would have meant
two token systems, two guards, and a login endpoint that has to choose. JWT is
also the better fit for the React Native client later: stateless, no cookie or
CSRF handshake.

**Cost:** JWT tokens cannot be revoked server-side without a blocklist. Add one
before launch if session revocation is a requirement.

---

## D2 — Wave kept for the web surface, React for the student app

The plan implied replacing Wave's frontend. Instead the two are split by
responsibility: Wave serves marketing, pricing, blog, billing and the Filament
admin (server-rendered, SEO-relevant); the React SPA serves the student
experience through `/api/v1`. Nothing is duplicated, and the SPA reuses Wave's
identity system.

---

## D3 — Self-referencing topics instead of a `chapters` table

The plan listed subjects → topics → chapters. Different exams nest to different
depths; a fixed fourth table would be empty for some and insufficient for others.
`topics.parent_id` plus a `level` enum covers both. See
[`database-design.md`](database-design.md).

---

## D4 — Lessons are content, not personalisation

One lesson per `(topic, exam, locale, prompt version)`, shared by every student.
Personalisation happens at selection time (which chapter, in what order) and
revision time (which questions, when).

This is the difference between LLM cost scaling with **content** and scaling with
**users**. Per-student generation would make the unit economics of a low-priced
subscription in this market impossible.

---

## D5 — The scorer is deterministic, not a model call

`TopicImportanceScorer` uses explicit weighted signals rather than asking an LLM
to rate importance. Three reasons: a student must be able to see *why* a chapter
is five stars; a content editor must be able to argue with it; and it must be
reproducible across runs. An LLM rating would be none of those, and would cost
money per topic per recompute.

---

## D6 — Star ratings are quantiles, not thresholds

Fixed score cut-offs would slowly turn everything five-star as the corpus grows.
Quintile ranking within an exam keeps the distribution meaningful and makes "★★★★★"
mean "top of this exam" at any corpus size.

---

## D7 — SM-2 quality is inferred, not asked

Textbook SM-2 asks the learner to self-rate recall 0–5 after every card. That
prompt is a large source of drop-off. Quality is derived from correctness, answer
latency and an optional confidence tap instead. Learning steps (1 min, 10 min)
were added before graduation so a just-met item is not lost for a day.

**Cost:** inferred quality is noisier than honest self-report. The confidence tap
is available for clients that want to collect it.

---

## D8 — pgvector with a working portable fallback

`knowledge_chunks.embedding` is `vector(1536)` + HNSW on PostgreSQL and JSON +
in-PHP cosine everywhere else, branched on driver in the migration and at
runtime in the `Retriever`.

The entire test suite and CI therefore run on SQLite with no services, while
production gets real ANN search. The alternative — requiring Postgres for every
test run — slows the feedback loop for no benefit on 95% of tests.

---

## D9 — The mock LLM provider builds real output

Generators embed their retrieved database context in the prompt as a delimited
JSON block. Real models read it as grounding; the mock parses it and answers from
the same real facts. A developer with no API key sees an actual lesson about the
Liberation War, and tests assert on real content rather than placeholder strings.

Mock embeddings use the hashing trick so they carry genuine lexical similarity —
retrieval ranking stays sensible offline.

---

## D10 — Free tier is metered, not walled

`EnforceLessonQuota` returns `402` with an explicit quota body after N lessons
per day, and lets revision and practice through unrestricted. A candidate who
cannot use the product will not subscribe to it; a candidate who runs out of
lessons mid-momentum will.

---

## D11 — Test isolation via a pre-built database, not `RefreshDatabase`

Wave resolves its active theme, settings and Folio page mounts from the database
while its service provider **boots** — earlier than `RefreshDatabase` migrates.
Using it left Wave's own page routes unregistered and 137 of its tests failing.

`tests/bootstrap.php` builds a migrated and seeded SQLite file before PHPUnit
starts (rebuilt automatically when any migration or seeder is newer), and tests
are isolated with `DatabaseTransactions`. Wave boots against a populated database
exactly as it does in development.

Examora's domain tests live in `tests/Feature/Domain` rather than `tests/Unit`,
because they use Eloquent — and because Wave's `PluginAutoloaderTest` asserts on
the global autoloader stack and fails if anything booted the framework earlier in
the same process.

---

## D12 — Two upstream Wave bugs fixed

Both were real, reproducible in the browser, not test artefacts.

**Theme Volt pages 500'd.** Wave's `ThemesServiceProvider` registers each theme's
`pages` directory with Folio but not with Volt. Every theme page using the
`@volt('name')` fragment directive — `/profile/{username}`, all of `/settings`,
`/notifications`, `/admin/media` — died with *"Unable to find component:
volt-anonymous-fragment-…"*.

The fix adds the themes root and Wave's package views to `config('view.paths')`
in `AppServiceProvider`. It deliberately does **not** mount them in Volt: Volt's
`ExtractTemplate` precompiler strips the top-level `<?php ?>` block from every
Blade file in a mounted directory, which would break the plain Folio pages in the
same folder (`/blog`, `/changelog`). Volt's `ComponentResolver` already consults
`view.paths` when resolving fragment aliases, so this satisfies it without
triggering the precompiler.

**Ignition exhausted memory during tests.** `APP_DEBUG=false` in `phpunit.xml`
plus a raised memory limit in the test bootstrap; failures now print assertions
instead of a fatal error.

---

## D13 — The exam clock is the server's

`quiz_sessions.meta.expires_at` is set at creation and `seconds_remaining` is
recomputed from the wall clock on every read. The client counts down locally for
display, but the answer endpoint rejects submissions past expiry regardless of
what the client believes.

Closing the tab therefore gains nothing, and a paper whose clock ran out while
the student was away is auto-submitted on their next visit rather than left in a
state they cannot exit.

**Cost:** a genuine connection drop costs real exam time. That is the same
trade-off a real exam hall makes, and the alternative — a pausable clock — lets a
student stop time to look something up.

---

## D14 — Strict mode withholds the key server-side

In `strict` mode `QuizEngine::answer()` returns `{recorded, mode,
seconds_remaining}` and nothing else. The correct index and explanation are not
sent to the client at all until the paper is submitted.

The tempting alternative — send everything and hide it in the UI — is not a
secret, it is a delay before someone opens the network tab. A test asserts the
absence of those keys.

---

## D15 — Negative marks are shown, not floored

A mock score can be negative and is displayed that way. Under BCS's −0.5 penalty
a candidate who guesses freely genuinely can score below zero, and the analysis
tells them exactly what guessing cost: *"you'd have scored X by leaving those
blank."*

Flooring at zero would hide the single most valuable lesson a first mock
teaches. Skipped questions are never penalised, so the incentive the UI creates
matches the incentive the real exam creates.

---

## D16 — No OCR engine; PDFs go to the model

Every provider accepts PDFs natively, so ingestion has no Tesseract, Ghostscript
or Imagick dependency. Beyond the convenience, classical OCR is poor on Bangla
script and worse on the mixed-script multi-column layouts real BCS papers use —
which is exactly the material this pipeline exists to read.

Text-layer extraction (`smalot/pdfparser`, pure PHP) runs first and is free, so
the paid route is only taken for pages that genuinely need it.

**Cost:** per-page spend on scans, and a provider page limit on very large PDFs.
Bounded because ingestion is one-off per document and transcribed pages are never
re-read.

---

## D17 — Uploads are private by default, for everyone

`visibility` is not an upload parameter. Every document — student or admin —
starts private and can only be made shared through a confirmed admin action.

A private document's questions can drive its owner's own practice; they can never
enter `past_questions`, which is what moves topic importance and readiness for
every other student. `DocumentReviewer::approve()` refuses outright on a private
document, and a test asserts it.

Without the split, one student uploading a coaching centre's guesswork would
corrupt the exam intelligence everyone depends on.

---

## D18 — No bulk approve in the review queue

Deliberately absent. Approving in bulk is approving without reading, which is the
exact failure the queue exists to prevent. Approval also refuses without an
answer key, a topic and a year — a wrong key poisons the importance score, the
lesson built from it and the readiness estimate simultaneously and silently.

---

## D19 — The forecast is a renewal process, not a Poisson one

The overdue adjustment says a topic absent longer than its own usual cycle is
more likely to return. A memoryless Poisson process would say the opposite:
nothing changes. Exam boards rotate through a syllabus, so the renewal assumption
matches how these papers are actually set.

It is damped (α = 0.35) and clamped to [0.5, 2.0] because the rotation is loose,
not a schedule. A topic missing for a decade must not become a certainty.

Stated explicitly in [`prediction.md`](prediction.md) because it is the one
place the model asserts something about the world rather than about the data.

---

## D20 — Predictions inform the plan, they never prune it

Offered three options for how far the forecast should reach; took the middle one.
`DailyPlanner` is untouched by the prediction engine. A forecast changes what a
student studies *first*, never what disappears from their plan.

The aggressive version — letting predictions deprioritise low-probability topics
— saves more time when the forecast is right and costs a candidate their exam
when it is wrong. Not a trade worth making on a model backtested against seeded
data.

---

## D21 — Forecast the session after the archive, never the current year

The engine targets `last_session_in_bank + 1`. Jumping to the current calendar
year would silently treat every unarchived session as one in which nothing was
asked, making every topic look overdue and inflating the entire forecast.

Instead the gap is surfaced: `freshness.is_current` is false and the UI shows a
red banner saying the bank is behind. Found this while reviewing the first real
output — everything was flagged "due", which was the model being wrong, not the
exam being predictable.

---

## D22 — Confidence multiplies by corpus coverage, it does not add

The first version added corpus length as a third additive term, which handed
every topic a flat bonus and rated a topic with four sightings "high confidence".
Coverage is a *ceiling* on certainty, not evidence about a particular topic, so
it multiplies the sample/regularity blend instead.

---

## D23 — The backtest is a product surface, not a test

`GET /predictions/backtest` is public, and `examora:bootstrap` prints
`better than guessing` or `NO BETTER THAN GUESSING` on every run.

A prediction feature that cannot be checked is a prediction feature that gets
trusted past its evidence. Making the score visible means a degrading model is
noticed by whoever runs the bootstrap rather than discovered by a student in an
exam hall.

---

## D24 — Question ownership is a column with a global scope, not a `meta` marker

Found by probing the private-upload path: `publishPrivately()` marked ownership
in `meta` and nothing enforced it, so one student's uploaded questions appeared
in **every** other student's practice pool and mock exams.

Now `questions.owner_id` (null = shared) with `VisibleQuestionScope` applied
globally. The safe behaviour is the default; leaking requires deliberately
calling `withAllOwners()`. Services that receive a `User` use
`visibleTo($userId)` instead of ambient `auth()->id()`, so a queue job and an
HTTP request return the same set.

The lesson generalises: a privacy rule every caller must remember is a privacy
rule that will be forgotten.

---

## D25 — Near-duplicates inform the forecast; they never merge the corpus

Clustering by embedding similarity lets a reworded repeat count as a return.
It deliberately does **not** write to `past_questions`.

A false merge would unify two questions with different answer keys and nobody
would notice — the importance score, the lesson and the readiness estimate would
all shift on a silent error. So clusters feed the repeat forecast and an admin
review page, and a human confirms any actual merge. Threshold is 0.93 within a
topic, set high because the cost of a false positive vastly exceeds the cost of
missing a repeat.

Clusters whose members disagree on the answer are excluded from the forecast
entirely and flagged in red — that is either bad data or two different questions,
and either way a guess must not enter a prediction.

---

## D26 — Identity embeddings exclude both provenance and the answer key

Indexing writes two chunks per past question: one for retrieval (with session
label and year) and one for identity comparison (stem and options only).

Excluding provenance is measurable — a reworded pair scores 0.96 on identity text
versus 0.84 with provenance mixed in, while different questions on one topic
score 0.27 and 0.44. Provenance is exactly what differs between two appearances
of the same question, so including it buries the signal.

Excluding the **answer** was the subtler fix, found when the conflicting-answer
guard never fired. With the key in the comparison, two copies of one question
carrying different keys score as dissimilar, form no cluster, and are never
surfaced — silently hiding the one case most worth catching.

---

## Open questions

- **Question corpus licensing.** The seeded questions are representative sample
  data, not verified past papers. This is the single biggest gap between the
  current build and something a student should pay for.
- **Token revocation** (see D1).
- **Bangla content.** The schema is locale-aware (`lessons.locale`,
  `exams.locale`) but nothing is translated. The BCS audience will expect Bangla.
- **Prediction engine.** `topic_statistics` holds the inputs; the "what will
  appear next" model is not built.
