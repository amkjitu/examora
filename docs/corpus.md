# Building the question corpus

Everything Examora shows a student — star ratings, expected question counts,
readiness, the forecast, repeat candidates — is a function of one table:
`past_questions`.

The machinery around it is finished and tested. The corpus is not. Until it is
real, every number the product produces is confident and meaningless. This is the
document for fixing that.

---

## Two ways in

| | Use when | Command |
|---|---|---|
| **Bulk import** | You have questions as CSV or a spreadsheet — typed up, bought from a publisher, scraped from a licensed source | `examora:import` |
| **PDF ingestion** | You have scanned or digital papers | Upload in the app, review in the admin — see [`ingestion.md`](ingestion.md) |

Bulk import is usually the faster path. Most question banks that exist already
exist as a spreadsheet.

---

## Bulk import

Template: [`docs/samples/questions-import-template.csv`](samples/questions-import-template.csv)

```bash
# Always look before you leap. This is the default.
php artisan examora:import questions.csv

# Then commit, and refold the scores
php artisan examora:import questions.csv --commit --bootstrap
```

### Columns

Only four things are required: **year, question, at least two options, and an
answer**. Everything else is optional.

| Field | Accepted column names | Notes |
|---|---|---|
| Question | `question`, `stem`, `question_text`, `text` | Required |
| Options | `option_a`…`option_e`, or a single `options` column | JSON array, or `\|`-separated. Required, min 2 |
| Answer | `answer`, `correct`, `key`, `correct_option`, `correct_index` | Required. See below |
| Year | `year`, `exam_year` | Required |
| Topic | `topic`, `chapter`, `topic_slug` | Matched by slug, exact name, or slugified name |
| Session | `session`, `session_label` | e.g. "44th BCS Preliminary" |
| Explanation | `explanation`, `why`, `note` | |
| Difficulty | `difficulty`, `level` | `easy` / `medium` / `hard`, defaults to medium |

JSON works too — either a bare array or `{"questions": [...]}`.

### How the answer is read

In this order, and the order matters:

1. **Exact match against an option's text** — `answer: 11` with options
   `[11, 9, 7, 13]` means the first option.
2. **A single letter** — `A`, `B`, `C`, `D`.
3. **A bare number**, when nothing matched as text — `3` means the third option.

Text wins first because numeric options are everywhere in this material (years,
counts, "how many sectors"). Reading `11` as a position would silently pick the
wrong option, which is the worst possible outcome.

### What gets rejected, and what does not

**Rejected** — reported with the line number, nothing written:

- No question text, or fewer than two options
- No answer key, or a key matching no option
- An implausible year

A wrong answer key corrupts the importance score, the lesson built from it and
the readiness estimate simultaneously, and nobody notices. Rejecting the row is
always the cheaper failure.

**Imported with a warning:**

- An unrecognised topic → imported unclassified for a reviewer to assign. Losing
  a verified question over a spelling difference would be worse.
- A duplicate of an earlier line in the same file → skipped, reported.
- A question already in the bank for that year → skipped.

The same question in a *different* year is imported, not skipped. That is one
fact examined twice, and it is the single strongest signal the scorer runs on.

---

## Health check

```bash
php artisan examora:corpus-health          # exits 1 when not fit to score
php artisan examora:corpus-health --strict # exits 1 on warnings too
```

Run it after every import. It exits non-zero when the corpus cannot support the
product, so it belongs in CI once a real corpus exists.

### Fatal — the product cannot be built on this

- **Fewer than 5 sessions.** Importance scoring and the forecast have nothing to
  work with.
- **More than 10% of questions unclassified.** Topic scores are computed only
  from classified questions, so every ranking is understated — silently.

### Problems — real damage, fixable

- Missing answer keys, or keys pointing outside their options
- Missing fingerprints (repeats undetectable)
- **A gap in the middle of the year range** — almost always a hole in the
  archive, not a year the exam skipped. The forecast reads it as "nothing was
  asked" and inflates overdue-ness everywhere.
- The newest session being years old — the forecast is then for a sitting that
  has already happened

### Worth knowing

- Topics with fewer than 3 questions (star ratings are guesses)
- Syllabus topics with no questions at all
- **Subjects under-represented against their exam weight.** A subject carrying
  35 marks with 2% of the questions will be systematically under-ranked no matter
  how good the scorer is.

### What the seeded corpus reports today

```
Questions 53 · Sessions 13 (2012–2024) · Classified 100% · Topics 12 of 30

Problems
  · The most recent session is 2024, 2 years ago.
Worth knowing
  · 18 topics have no questions at all.
  · Bangla carries 18% of the exam marks but 0% of the questions.

ERROR  Not fit to score.
```

That verdict is correct and should stay that way until real papers replace it.

---

## The workflow

1. **Source and license the papers.** 15–20 years if you can. This is the part
   that is not a coding problem, and it is the whole bottleneck.
2. **Get them into a spreadsheet**, or into PDFs for the ingestion pipeline.
3. `examora:import papers.csv` — dry run. Fix what it rejects.
4. `examora:import papers.csv --commit --bootstrap`
5. `examora:corpus-health` — resolve everything fatal, then the problems.
6. **Check the output against a human.** Run
   `GET /api/v1/exams/{slug}/predictions` and show the top-20 topic ranking to
   someone who has coached the exam. If they say "roughly right", the weights are
   good. If they say "why is X so high", tune the weights against them — not
   against intuition.
7. `GET /api/v1/exams/{slug}/predictions/backtest` — Brier below 0.25 means the
   forecast beats guessing. Above it, do not show the forecast to anyone.

Steps 6 and 7 are the ones that get skipped, and they are the ones that decide
whether this is a product or a plausible-looking demo.

---

## Adding topics as you go

Import warns about unrecognised topic names rather than failing. To add them:

```bash
php artisan tinker --execute="
\$subject = App\Models\Examora\Subject::where('name','Bangla')->first();
App\Models\Examora\Topic::create([
  'subject_id' => \$subject->id,
  'name' => 'Bangla Literature: Modern Period',
  'slug' => Str::slug('Bangla Literature: Modern Period'),
  'level' => 'chapter',
]);"
```

Then re-run the import — questions already in the bank are skipped, so only the
newly classifiable ones change.

To classify questions already imported without a topic, use the admin at
**Examora → Question review**, or set `topic_id` directly.
