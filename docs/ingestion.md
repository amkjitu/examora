# Document ingestion

Turns a PDF into reviewed questions in the corpus.

```
upload → queue job → page text → question extraction → topic classification
       → dedupe → REVIEW QUEUE (human) → past_questions → rescore
```

The arrow that matters is the one in capitals. Everything before it is a
suggestion; nothing crosses into `past_questions` without a person approving it.

---

## Why there is no OCR engine

There is no Tesseract, no Ghostscript, no Imagick. Every provider accepts a PDF
natively, so a scanned page is forwarded as-is and comes back as text.

That is not just convenience. Classical OCR is poor on Bangla script and worse on
the multi-column, mixed-script layouts real BCS papers use — which is the exact
material this pipeline exists to read. A vision model is both more accurate here
*and* one less system dependency on every developer machine and in CI.

Cost is bounded because ingestion is one-off per document and pages already
transcribed are never re-read.

## Two routes to text

`PageExtractor` tries the cheap route first:

| Route | When | Cost |
|---|---|---|
| Text layer (`smalot/pdfparser`) | Typed papers, guide books — most PDFs | Free, instant |
| Vision (`document.ocr` task) | Scans, or any page whose text layer yields under 40 characters | Per page |

Both are recorded on `document_pages.extraction_method`, so you can see which
pages cost money and why.

## Extraction and classification

`QuestionExtractor` runs two model calls per page:

1. **`document.parse`** — pull out the questions. The prompt is explicit that a
   guessed answer key is worse than no key: when the paper does not print an
   answer, `correct_index` comes back null and the row is flagged for a reviewer.
2. **`topic.classify`** — assign one syllabus topic, or null. The prompt says to
   return null rather than force a match, because an unclassified question is
   easy to fix in review while a wrongly classified one silently corrupts topic
   importance.

Both confidences are stored separately (`confidence`, `topic_confidence`). A
clean transcription with a guessed topic is a very different review problem from
a garbled transcription, and the queue lets you filter on each.

**Dedupe.** Every extracted question is fingerprinted (normalised stem + sorted
options) and checked against `past_questions` for the same exam. A match is
marked `duplicate` rather than discarded — a question repeating across sessions
is the strongest importance signal there is, and the reviewer may still want to
record the new year against it.

## The quarantine boundary

Students and admins both upload. Uploads are **always** created private,
regardless of who sends them — `visibility` is not a request parameter.

| | Private | Shared |
|---|---|---|
| Source | Any upload, by default | An admin explicitly marked it shared |
| Can drive that student's own practice | yes | yes |
| Can enter `past_questions` | **no** | yes, after review |
| Affects other students' topic scores | never | yes |

`DocumentReviewer::approve()` refuses outright on a private document. The only
way across the boundary is the *Mark as shared* action in the admin, which is
confirmed and deliberate.

Without this split, one student uploading a coaching centre's guesswork would
move the exam intelligence every other student depends on.

### How private questions are isolated

`questions.owner_id` — null means shared — with `VisibleQuestionScope` applied
as a **global** scope on the model. Every read is filtered to the shared bank
plus the caller's own questions.

The first version of this marked ownership in `meta` and relied on every query
honouring it. None of them did, and one student's uploaded questions appeared in
every other student's practice pool. Making the safe behaviour the default means
a new query site cannot leak by omission — it has to opt out via
`Question::withAllOwners()`, which only the admin, the importance scorer and
maintenance commands do.

Services that already receive a `User` use `Question::visibleTo($userId)` rather
than the global scope's `auth()->id()`. A queue job has no authenticated user,
and a service that silently returns a different question set depending on how it
was invoked is a bug waiting to happen.

Regression tests cover the practice pool, the mock exam, and the
no-authenticated-user case (which must fail closed).

### What a student gets back

Private uploads skip the review queue entirely — there is nothing for a reviewer
to certify, because the material never reaches the shared corpus.
`ProcessSourceDocument` publishes any extraction that has a printed answer key
straight into the student's own bank, and `GET /v1/my-questions` surfaces it
grouped by topic with a practice button. Anything without a key is skipped rather
than guessed at.

## The review queue

Filament → **Examora → Question review**. Navigation carries a badge with the
pending count.

A reviewer can correct the stem, options, key, topic and year on the way through
— that correction is the point of the queue, not an edge case. Approval is a
separate action from saving, because "I fixed the typo" and "I certify this is
exam-accurate" are different claims.

Approval refuses without an answer key, a topic and a year. There is **no bulk
approve**: approving in bulk is approving without reading, which is precisely the
failure mode the queue exists to prevent.

Approving is idempotent on `(exam, fingerprint, year)`, so the same question from
a different session correctly becomes a second occurrence rather than an
overwrite.

After approving a batch, run `php artisan examora:bootstrap` to fold the new
questions into the topic scores.

## Resumability

Both stages skip work already done: a page with text is not re-read, and a
question already staged from a document is not staged twice. A retry after a
provider outage costs only what actually failed. `ProcessSourceDocument` retries
three times with backoff and records the error on the document.

Re-uploading an identical file is detected by checksum and returns the original
document rather than paying to read it twice.

## Working on it offline

The mock provider parses the numbered-question / lettered-option layout real
papers use, including the trap that initials inside an option — *"Colonel M. A.
G. Osmani"* — look exactly like option markers. It resolves that structurally:
markers run A, B, C, D in ascending order, so only the ascending sequence is
kept and option text is sliced between those positions.

So the whole pipeline runs, and is tested, with no API key and no spend.

## Two chunks per question

Indexing writes **two** knowledge chunks for every past question:

| Source type | Contains | Used by |
|---|---|---|
| `past_question` | Stem, answer, explanation, **session label and year** | RAG retrieval |
| `past_question_identity` | Stem and options only | Near-duplicate detection |

Provenance helps retrieval and actively harms comparison — the session label is
precisely what differs between two appearances of the same question. Measured on
the seeded corpus: a reworded pair scores **0.96** on identity text and **0.84**
once provenance is mixed in, while two different questions on one topic score
0.27 and 0.44. The identity text nearly quadruples the margin.

The identity text also excludes the **answer key**, which is subtler and matters
more. If the key were part of the comparison, two copies of one question carrying
*different* keys would score as dissimilar, form no cluster, and never be
surfaced — and that is exactly the data error worth catching. See
[`prediction.md`](prediction.md#near-duplicate-detection).

## Known limits

- **Page-range chunking is not implemented.** A very large PDF is sent whole on
  the vision route. Provider page limits apply (Claude ~100 pages); split larger
  papers before uploading.
- **The offline classifier is lexical, not semantic.** It matches words in the
  question against topic names, so it will confidently file a DNS question under
  *Bangla Grammar* if nothing better overlaps. Real providers do this properly;
  the review queue catches it either way. Private uploads bypass review, so a
  misclassified private question stays misclassified — it only affects that
  student's own practice grouping.
