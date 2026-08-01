# Prediction engine

Forecasts what the next sitting will ask.

This is the most dangerous feature in the product. A student who trusts a
forecast and drops a chapter on the strength of it loses real marks, so every
design decision here is made in favour of being interrogable over being
impressive.

---

## The model

Explainable arithmetic, not a black box — same reasoning as the importance
scorer (D5). A content editor must be able to disagree with it, and a student
must be able to see why a number is what it is.

### Expected question count

A Poisson mean blended from two rates:

```
λ = (1 − w) · base_rate + w · recent_rate          w = 0.6
```

`base_rate` is questions per session across the whole observed history.
`recent_rate` is the same over the last five sessions. Recent behaviour is
weighted more heavily because syllabi drift.

### The overdue adjustment

```
λ' = λ · (1 + α · (overdue_ratio − 1))            α = 0.35
overdue_ratio = years_since_last ÷ mean_gap_between_appearances
```

**This encodes a real assumption and it is worth stating plainly.** A memoryless
Poisson process would say being "overdue" changes nothing — the next draw does
not know about the last one. Exam boards are not memoryless: they rotate through
a syllabus, so a topic absent for longer than its usual cycle is genuinely more
likely to return. That makes this a *renewal* process, not a Poisson one.

The adjustment is damped (α = 0.35) and the ratio is clamped to [0.5, 2.0]
because the rotation is loose, not a schedule. A topic missing for a decade does
not become a certainty.

### Probability of appearing

```
P(≥1 question) = 1 − e^(−λ')
```

## Confidence

Not decoration. It decides how the UI presents the number.

```
confidence = (0.55 · sample + 0.45 · regularity) · coverage

sample     = appearances ÷ (appearances + 3)
regularity = 1 ÷ (1 + coefficient_of_variation_of_gaps)
coverage   = min(1, observed_sessions ÷ 8)
```

`coverage` **multiplies** rather than adds. A short archive is a ceiling on what
any topic can claim; a long archive should not hand out free confidence to a
topic seen twice in it. (An earlier version added coverage as a third term and
inflated every score — a topic with four sightings came out "high confidence",
which it is not.)

Fewer than three appearances caps confidence at 0.6 regardless: one or two
sightings can never be high confidence.

Bands: ≥0.70 high · ≥0.45 moderate · ≥0.25 low · below that very low.

**The expected range widens as confidence falls.** Spread is `√λ` — the Poisson
standard deviation — multiplied by `(1 + (1 − confidence))`. Thin evidence
visibly produces a vaguer answer instead of a precise-looking one.

## Evidence

Every prediction stores the sentences that justify it:

> Asked in 2015, 2017, 2019, 2021, 2022, 2023.
> Comes round roughly every 1.6 years.
> Last seen 2 years ago — overdue against its own cycle.
> Appearing more often in recent sessions than historically.

Below 0.45 confidence it adds: *"Thin evidence — treat this as a hint, not a
plan."*

Evidence is stored, not generated on read, so a prediction can still be explained
after the model changes underneath it.

---

## Repeat candidates

The highest-value output, and the one no general-purpose study tool can produce.

Papers like BCS Preliminary reuse questions **verbatim** across sessions. A
question that has already repeated is worth more to a candidate than another
chapter summary — and finding them needs the fingerprinted past-question corpus.

A question qualifies only with **two or more appearances**. One sighting is not
evidence of anything.

```
score = 0.45 · repetition + 0.35 · cycle + 0.20 · regularity

repetition = min(1, (appearances − 1) ÷ 3)
cycle      = min(1, years_since_last ÷ mean_gap)
regularity = 1 ÷ (1 + cv_of_gaps)
```

Each component is capped so no single one can carry a weak candidate to the top.

### Near-duplicate detection

The `past_questions` fingerprint is exact-match: it normalises case, punctuation
and option order, and nothing else. That strictness is deliberate — a collision
merges two questions into one fact and corrupts the importance scores. But it
means an examiner who changes three words produces what looks like a brand-new
question, and the repeat predictor goes blind to exactly the pattern it exists
to find.

`NearDuplicateDetector` closes that gap using the identity embeddings written
during indexing (see [`ingestion.md`](ingestion.md#two-chunks-per-question)).

- **Threshold 0.93 cosine, within a topic only.** Set high on purpose: questions
  on one topic share names and vocabulary, so a permissive threshold merges
  genuinely different questions. A false positive unifies two answer keys; a
  false negative just misses a repeat, which is the status quo.
- **Single-link agglomeration** by union-find, matching how rewordings actually
  drift across sittings — one edit at a time.
- **Cross-topic matches are ignored.** Two near-identical questions filed under
  different topics is far more likely a classification error than a repeat.
- **Clusters whose answers disagree are excluded from the forecast** and shown
  in red in the admin. That is either a data error or two different questions;
  either way a guess must not enter the prediction.

Candidates assembled from rewordings are labelled `reworded: true` and say
*"asked in 2018, 2023, reworded between sittings"* rather than *"verbatim"*. A
student memorising an exact string deserves to know the wording has drifted
before.

**Nothing is ever merged.** `past_questions` is untouched; there is a test
asserting it. Clusters inform the forecast and appear at
**Examora → Suspected duplicates** for a human to confirm.

---

## Honesty guarantees

These are enforced in code, not left to the client:

**The disclaimer travels with every response.** No client can render a forecast
stripped of *"a forecast of what past papers suggest, not a syllabus."*

**Archive freshness is surfaced, not swallowed.** The engine forecasts the
session *after the last one in the bank* — never the current calendar year.
Skipping ahead would silently treat every unarchived session as one in which
nothing was asked, making every topic look overdue and inflating the whole
forecast. If the bank is behind, `freshness.is_current` is false and the UI shows
a red banner.

**Predictions never remove anything from the study plan.** `DailyPlanner` is
untouched by this engine. The forecast changes what a student studies *first*,
never what disappears from their plan. That was a deliberate choice against the
more aggressive option.

**Low confidence is styled to draw the eye**, in red, not to blend in. A weak
forecast presented calmly is a weak forecast that gets trusted.

---

## Backtesting

`GET /api/v1/exams/{exam}/predictions/backtest` — and printed by
`examora:bootstrap` on every run.

It holds out the most recent session, forecasts it from everything before, and
compares against what that session actually asked:

- **Brier score** — mean squared error of the appearance probability. Lower is
  better. **0.25 is what always guessing 50% would score**, so anything above
  0.25 means the model is worse than useless and should not be shown to anyone.
- **Mean absolute error** — how far off the expected question count was.

Bootstrap prints `better than guessing` or `NO BETTER THAN GUESSING` on every
run, so a degrading model is noticed rather than discovered by a student.

On the current representative corpus: Brier **0.187** against a 0.25 baseline,
MAE **0.40** questions per topic. That is a real signal, but on seeded data —
re-run it against a licensed corpus before trusting it.

---

## Where it surfaces

| Surface | What it shows |
|---|---|
| `/predictions` | Ranked forecast with evidence and confidence, plus repeat candidates |
| Chapter list | *Due again* / *Trending up* markers, with a *weak evidence* flag when confidence is low |
| Mock exam | The 10% prediction slice now draws from the forecast's top topics instead of a random pick of high-star ones |
| API | `GET /v1/exams/{exam}/predictions` and `/backtest` |

## Recomputing

`php artisan examora:bootstrap` — indexes, scores, forecasts, backtests, then
generates lessons. Superseded forecasts for other target years are deleted, so
the table cannot accumulate two live answers for one topic.

## Known limits

- **No current-affairs coupling.** International Affairs and Bangladesh Affairs
  track the news, and the model has no view of the news. It sees only cycles.
- **The corpus is seeded sample data.** Every number above is a function of
  `past_questions`. Validate against a licensed corpus before students see it.
- **Near-duplicate clustering is O(n²) within a topic.** Fine at BCS scale (a few
  hundred questions per topic); would need blocking or an ANN index if a topic
  ever holds tens of thousands.
- **The threshold is tuned against mock embeddings.** 0.93 separates cleanly on
  the hashing-trick vectors the offline provider produces. Real embedding models
  have different similarity distributions — re-measure against a licensed corpus
  and a real provider before trusting the number.
