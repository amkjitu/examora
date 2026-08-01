# Learning engine

Five services. Each is deliberately explainable — a student sees *why* a chapter
is recommended, and a content editor can disagree with the reasoning.

---

## 1. TopicImportanceScorer

Answers: *how likely is this chapter to be worth marks?*

Three signals, normalised **within an exam** so "★★★★★" always means "top of this
exam" rather than an absolute count that drifts as the corpus grows:

| Signal | Weight | Definition |
|---|---|---|
| Frequency | 0.55 | Questions per year of coverage, relative to the exam's busiest topic |
| Recency | 0.30 | Exponentially decayed toward recent sessions (`0.85^years_ago`) — syllabi drift |
| Subject weight | 0.15 | Marks the parent subject carries in the exam |

Stars are **quintile ranks**, not fixed score cut-offs:

| Percentile | Stars |
|---|---|
| top 10% | ★★★★★ |
| top 30% | ★★★★ |
| top 60% | ★★★ |
| top 85% | ★★ |
| rest | ★ |

Quantiles keep the star distribution stable as questions are added. Fixed
thresholds would slowly turn everything five-star.

Study time is estimated from **distinct facts**, not question rows: 1.5 minutes
per fact, clamped to 3–15 minutes. A question asked in four different years is
one thing to learn, not four.

> **Reading `expected_questions`.** It is derived from the historical rate per
> session, so a five-star chapter on a thin corpus can legitimately show `0-2`:
> it appears most years, but not every year. That is honest. It narrows as the
> corpus grows.

Recompute: `php artisan examora:bootstrap` (or `TopicImportanceScorer::recomputeExam`).

---

## 2. LessonGenerator

Answers: *what exactly should I read, and nothing more?*

The model is **never asked to decide what matters**. Retrieval decides; the model
only phrases. That inversion is the whole product.

1. **Gather facts.** Past questions for the chapter, grouped by `fingerprint` —
   a normalised hash of stem + sorted options, so the same question printed in
   2012, 2018 and 2023 collapses into one fact with three year-stamps. Repetition
   across sessions is the single strongest importance signal, so facts are
   ordered by times-examined, then recency.

2. **Convert Q&A into declarative facts.** `"Who was the Commander-in-Chief?"` +
   `"M. A. G. Osmani"` becomes `"Who was the Commander-in-Chief — M. A. G.
   Osmani."` Recognition is not recall; a student who has only seen the question
   form can pick the answer from four options but cannot produce it.

3. **Prompt.** Instructions plus a `PromptContext` block carrying the facts and
   the chapter's statistics, with a JSON schema attached.

4. **Persist.** Lesson + ordered cards, then promote the chapter's past questions
   into the practice bank bound to this lesson — so the post-lesson check uses
   real examined questions, not generated approximations.

**Caching:** keyed on `(topic, exam, locale, prompt version)`. Bump
`LessonGenerator::PROMPT_VERSION` to invalidate every lesson after a prompt
change; old versions stay in the table for comparison.

---

## 3. RevisionScheduler (SM-2)

Answers: *what will I have forgotten by exam day?*

Two deliberate departures from textbook SM-2:

**Quality is inferred, not asked.** Interrupting a student to self-rate recall
0–5 is a large source of drop-off in study apps. Quality is derived instead:

| Situation | Quality |
|---|---|
| Correct, fast (≤8s), confident | 5 |
| Correct, normal | 4 |
| Correct, slow (≥30s) or hesitant | 3 |
| Wrong, but was confident | 1 |
| Wrong | 2 |

**Learning steps before graduation.** Raw SM-2 sends a brand-new item to 1 day
then 6 days, which loses items a student has only just met. New items get short
in-session steps (1 min, 10 min) first, then enter the curve:

```
interval:  0 → 1 day → 6 days → interval × ease_factor
ease:      2.5 start, SM-2 update, floor 1.30
lapse:     interval → 0, ease −0.20, state → relearning, due in 1 minute
```

`retrievability()` returns `exp(−elapsed / interval)` — the standard forgetting
curve, using the item's own interval as the stability constant. This feeds the
readiness estimate.

---

## 4. DailyPlanner

Answers: *what should I do in the next 20 minutes?*

Priority order, non-negotiable — this is what separates a coach from a content
library:

1. **Due revision.** Forgetting what you already learned is the most expensive
   thing a candidate can do.
2. **New chapters**, highest probability first, filtered by time remaining:

   | Days left | Minimum stars considered |
   |---|---|
   | ≤ 14 | ★★★★ |
   | ≤ 45 | ★★★ |
   | more / unknown | all |

   With ten days left, covering three-star material is a worse use of the time
   than securing the five-star material. The planner enforces that.
3. **Practice** on weak-but-important chapters (mastery < 0.6, ordered by
   importance).
4. **Mock** when the exam is within 30 days and time remains in the budget.

Every item carries a `reason` string that is shown verbatim in the UI —
*"6 questions from this chapter have appeared before (★★★★★)"*. A plan the
student does not trust is a plan they will not follow.

---

## 5. ProgressTracker

Answers: *am I ready?*

**Mastery** is not accuracy. Two correct answers on an easy pair is not mastery
of a chapter:

```
mastery = 0.45 × accuracy      how often they are right
        + 0.25 × coverage      share of the chapter's bank actually seen
        + 0.30 × retention     mean retrievability of their memory items
```

**Readiness** weights mastery by what the exam actually asks:

```
readiness = Σ (expected_questions × mastery) / Σ expected_questions  × 100
```

Ignoring a five-star chapter costs far more than ignoring a one-star one — which
is the entire premise of the product, expressed as arithmetic.

**XP:** 10 correct, 2 incorrect (effort still counts, or students stop trying),
25 per lesson, 15 streak bonus. Levels cost 20% more each, starting at 100 XP —
slow enough to stay meaningful, fast enough to reward the first session.

---

## Recomputation

| What | When | How |
|---|---|---|
| `topic_statistics` | New papers ingested | `examora:bootstrap` |
| `knowledge_chunks` | New source material | `Indexer::indexPastQuestions` (checksum-skipped if unchanged) |
| `topic_mastery` | After every quiz | `ProgressTracker::afterQuiz` |
| `study_plans` | First dashboard load each day | `DailyPlanner::planFor` |
| `readiness` | Every dashboard load | `ProgressTracker::readiness` |
