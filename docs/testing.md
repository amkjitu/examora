# Testing Examora

Two ways in: the automated suite, and a manual walkthrough in the browser.

---

## 1. Automated

```bash
cd backend && php artisan test         # 298 tests
cd backend && ./vendor/bin/pint --test # formatting
cd web && npm run lint && npm test && npm run build   # 24 tests
```

No services needed — the suite builds its own SQLite database
(`tests/bootstrap.php`) and runs the AI gateway on the deterministic mock
provider. Nothing costs money.

### Against PostgreSQL

SQLite forgives SQL that PostgreSQL rejects — a select alias in `HAVING`, a date
column compared as a string — so the same suite runs against a real database
too. Both bugs in that sentence were found this way, not by the SQLite run.

`tests/bootstrap.php` only owns the SQLite file; against any other driver the
schema is the caller's job, because Wave reads its theme and settings while
booting and so needs a populated database before PHPUnit starts:

```bash
cd docker && docker compose up -d
cd ../backend
createdb -h 127.0.0.1 -p 5433 -U examora examora_ci

export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 \
       DB_DATABASE=examora_ci DB_USERNAME=examora DB_PASSWORD=secret
php artisan migrate --force && php artisan db:seed --force
php artisan test
```

CI runs exactly this on `pgvector/pgvector:pg16`, then seeds the BCS corpus and
runs `examora:bootstrap` on top — which is what exercises the real vector
column, the HNSW index and the pgvector cosine search that the in-PHP fallback
stands in for elsewhere.

### What the suite actually protects

| File | Guards |
|---|---|
| `Feature/Api/StudentJourneyTest` | Enrol → lesson → quiz → SM-2 → dashboard, and that the answer key never leaks |
| `Feature/Api/MockExamTest` | Strict-mode key suppression, server clock, resume, expiry, negative marking, subject breakdown, pacing |
| `Feature/Api/FreeTierQuotaTest` | Free tier meters rather than blocks |
| `Feature/Ingestion/IngestionPipelineTest` | Extraction, dedupe, the quarantine boundary, **and three private-question leak regressions** |
| `Feature/Domain/PredictionEngineTest` | Overdue-ness, confidence on thin data, Poisson arithmetic, backtest |
| `Feature/Domain/NearDuplicateTest` | Reworded repeats found, different questions kept apart, conflicting answers excluded, `past_questions` never written |
| `Feature/Domain/RevisionSchedulerTest` | SM-2 curve, lapses, ease floor |
| `Feature/Domain/ProgressTrackerTest` | Mastery is not accuracy alone, readiness weighted by what the exam asks, XP and streaks |
| `Feature/Domain/DailyPlannerTest` | Priority order, the days-left star filter, the minute budget, every item has a reason |
| `Feature/Domain/MockAnalyserTest` | Subject split, a skipped question is not a wrong one, accuracy on attempted, time sinks vs rushed mistakes |
| `Feature/Domain/AiGatewayTest` | Provider fallback, caching, JSON extraction from messy output |

The three domain files above test the numbers a student changes their revision
on the back of. They were previously only covered end to end, which cannot tell
a plausible-looking wrong number from a right one.

### Frontend

```bash
cd web && npm test
```

Vitest with jsdom and Testing Library. `src/test/harness.tsx` renders a page
inside a router and a fresh query client, and stubs `fetch` with a route table
that **throws on an unmatched request** — otherwise a test can pass because
nothing was ever called.

| File | Guards |
|---|---|
| `pages/MockExamPage.test` | Countdown, auto-submit at zero and only once, strict mode shows no feedback, resume restores answers and trusts the server clock, answer sheet |
| `components/Locked.test` | A gate stays shut while entitlements are loading and when the request fails — the failure mode is locked, not open |
| `pages/LessonPage.test` | A 402 arrives as an explanation with a way forward, not a blank screen; other errors are not disguised as quota |

---

## 2. Manual walkthrough

### Start everything

Four processes. **The queue worker is the one people forget** — without it,
uploads sit at "Queued" forever and nothing appears to happen.

```bash
# 1. Infrastructure
cd docker && docker compose up -d

# 2. API                     → http://localhost:8000
cd backend && php artisan serve

# 3. Queue worker            ← required for document uploads
cd backend && php artisan queue:work

# 4. SPA                     → http://localhost:5173
cd web && npm run dev
```

Sign in at **http://localhost:5173** with `admin@admin.com` / `password`.

If the database is empty:

```bash
cd backend
php artisan migrate --seed
php artisan db:seed --class=BcsPreliminarySeeder
php artisan examora:bootstrap
```

---

### A. The core loop

1. **Pick an exam.** BCS Preliminary, target session "BCS 48", a date ~90 days
   out, 20 minutes a day.
2. **Dashboard.** Days left, today's plan, readiness at 0%.
   → *Every plan item carries a reason.* "6 questions from this chapter have
   appeared before (★★★★★)" — a plan a student doesn't trust is one they won't
   follow.
3. **Chapters.** Ordered by exam probability, not syllabus order. Liberation War
   at the top with ★★★★★.
   → *Look for the "Due again" and "Trending up" markers* — those come from the
   forecast, not the star rating. They answer a different question: where this
   chapter sits in its own cycle right now.
4. **Open a lesson.** Generated on demand, cached afterwards.
   → *Every key point is a fact that provably appeared in a past paper.* Nothing
   is invented. The exam-intelligence strip shows how many times it's been asked.
5. **Take the check.** Answer one wrong on purpose.
   → *The explanation cites the sessions the question appeared in*, and tells you
   when it's scheduled to come back.
6. **Back to the dashboard.** XP, streak, readiness all moved.

**Reset your progress** to walk it again:

```bash
cd backend && php artisan tinker --execute="
\$u = App\Models\User::where('email','admin@admin.com')->first();
foreach ([App\Models\Examora\QuizSession::class, App\Models\Examora\MemoryItem::class,
          App\Models\Examora\TopicMastery::class, App\Models\Examora\StudyPlan::class,
          App\Models\Examora\LearnerProfile::class, App\Models\Examora\LearningEvent::class,
          App\Models\Examora\ExamEnrollment::class] as \$m) \$m::where('user_id',\$u->id)->delete();
echo 'reset'.PHP_EOL;"
```

---

### B. Mock exam

**Mock** in the nav → pick a mode → 25 questions.

**Strict mode** is the one to test properly:

| Check | What should happen |
|---|---|
| Answer a question | No feedback at all. Selecting advances you |
| Open devtools → Network → the `/answer` response | `{recorded, mode, seconds_remaining}` — **no `correct_index`, no `explanation`**. The key is not on the client |
| Click "Sheet" | Answer grid, jump anywhere, flag for review |
| **Close the tab, reopen** `/mock/{id}` | Answers restored, **and the clock has kept running**. Closing the tab gains you nothing |
| Let the clock hit 0:00 | Auto-submits |
| Answer everything wrong, submit | **Negative score**, shown as negative. Plus "you'd have scored X by leaving those blank" |

Then the four analysis tabs: subject breakdown weakest-first, full review with
keys, pacing (time sinks = slow *and* wrong), readiness delta.

To test expiry without waiting:

```bash
cd backend && php artisan tinker --execute="
\$s = App\Models\Examora\QuizSession::where('type','mock')->latest()->first();
\$s->update(['meta' => array_merge(\$s->meta, ['expires_at' => now()->subMinute()->toIso8601String()])]);
echo 'session '.\$s->id.' expired — reload the page'.PHP_EOL;"
```

---

### C. Document ingestion

A sample paper is at **`docs/samples/bcs-sample-paper.pdf`**.

**Upload** in the nav → choose the PDF → BCS Preliminary → year 2023 → upload.

With the queue worker running it processes in a second or two. Watch:

- Status goes Queued → Reading → Done
- "3 questions found", and for a private upload, "N ready to practise"
- Below, **From your uploads** — grouped by topic, with a Practise button

**The quarantine is the important bit.** Go to the admin at
**http://localhost:8000/admin** → Examora:

1. **Source documents** — your upload is `private`. That's not a choice the
   upload made; every upload starts private regardless of who sends it.
2. Try to approve one of its questions in **Question review** → refused:
   *"came from a private upload and cannot enter the shared question bank."*
3. **Mark as shared** on the document (confirmed action), then approve. Now it
   lands in `past_questions`.
4. `php artisan examora:bootstrap` to fold it into the topic scores.

**Prove the leak is closed** — a second student must not see the first's
questions:

```bash
cd backend && php artisan tinker --execute="
\$other = App\Models\User::create(['name'=>'Stranger','email'=>'s'.rand().'@x.com','password'=>bcrypt('x')]);
printf('private questions in bank: %d | visible to a stranger: %d'.PHP_EOL,
  App\Models\Examora\Question::withAllOwners()->whereNotNull('owner_id')->count(),
  App\Models\Examora\Question::visibleTo(\$other->id)->whereNotNull('owner_id')->count());"
```

Second number must be **0**.

> **If a document stays at "Queued"**, the queue worker isn't running. Start it,
> or process one inline:
> `php artisan tinker --execute="(new App\Jobs\ProcessSourceDocument(1))->handle(app(App\Services\Ingestion\PageExtractor::class), app(App\Services\Ingestion\QuestionExtractor::class), app(App\Services\Ingestion\DocumentReviewer::class));"`

---

### D. Forecast

**Forecast** in the nav.

Read the caveats first — they're above the ranking on purpose. If the question
bank is behind the current year you'll also get a red banner saying the forecast
is for a session that may already have happened.

Per topic: probability, expected count with a range, confidence band, and a
**"Why?"** that shows the reasoning:

> Asked in 2015, 2017, 2019, 2021, 2022, 2023.
> Comes round roughly every 1.6 years.
> Last seen 2 years ago — overdue against its own cycle.

Low-confidence rows are styled red. Thin evidence adds *"treat this as a hint,
not a plan."*

**Likely repeats** tab: questions already reused verbatim, with the answer.
Labelled "reworded between sittings" when found by near-duplicate clustering
rather than exact match.

**Check the model honestly:**

```bash
curl -s http://localhost:8000/api/v1/exams/bcs-preliminary/predictions/backtest | python3 -m json.tool
```

Brier score below 0.25 means it beats always guessing 50%. Above, and it should
not be shown to anyone. `examora:bootstrap` prints this on every run.

**Test near-duplicate detection** by planting a rewording:

```bash
cd backend && php artisan tinker --execute="
\$src = App\Models\Examora\PastQuestion::whereNotNull('topic_id')->first();
\$stem = str_replace('-', ' ', \$src->stem).' exactly?';
App\Models\Examora\PastQuestion::create([
  'exam_id'=>\$src->exam_id,'topic_id'=>\$src->topic_id,'year'=>\$src->year - 4,
  'session_label'=>'Planted','stem'=>\$stem,'options'=>\$src->options,
  'correct_index'=>\$src->correct_index,'difficulty'=>'medium','marks'=>1,
  'fingerprint'=>App\Models\Examora\PastQuestion::fingerprintFor(\$stem, \$src->options),
]);
echo 'planted a reworded copy — now run: php artisan examora:bootstrap --skip-lessons'.PHP_EOL;"
```

Then **Examora → Suspected duplicates** in the admin shows the cluster with its
similarity score. Nothing is merged — that page exists so a human confirms.

---

### E. Free tier

```bash
cd backend && php artisan tinker --execute="
App\Models\User::where('email','admin@admin.com')->first()->subscriptions()->delete();
echo 'subscription removed'.PHP_EOL;"
```

Open three lessons. The fourth returns `402` with a quota body, and the SPA shows
the allowance message. **Revision and practice still work** — the tier meters,
it doesn't wall.

---

## 3. Testing with a real model

Everything above runs on the mock provider. To use a real one, set any of these
in `backend/.env` and restart:

```env
GEMINI_API_KEY=...
AI_DEFAULT_PROVIDER=gemini
```

Then `php artisan examora:bootstrap --force-lessons`.

Watch what it costs:

```bash
cd backend && php artisan tinker --execute="
foreach (App\Models\Examora\AiRequest::selectRaw('task, provider, count(*) n, sum(cost_usd) cost, sum(case when cache_hit then 1 else 0 end) hits')->groupBy('task','provider')->get() as \$r)
  printf('%-18s %-10s %3d calls  %2d cached  \$%s'.PHP_EOL, \$r->task, \$r->provider, \$r->n, \$r->hits, \$r->cost);"
```

The gateway falls back automatically if a provider fails, and records which one
answered — so a vendor degrading shows up here before it shows up in support.

---

## 4. What you're testing against

Worth saying plainly: **the question corpus is representative sample data**, not
verified BCS past papers. The subject/marks split is accurate; the questions are
written to exercise the pipeline.

So the walkthrough tells you whether the *machinery* works. It cannot tell you
whether the star ratings, forecasts or repeat candidates are *right* — those are
functions of `past_questions`, and validating them needs a real licensed corpus
checked against someone who knows the exam.
