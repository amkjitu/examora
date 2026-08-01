# Next up: mock exam UI, then document ingestion

Decisions are settled; no implementation has started. This is the spec to build
from.

---

## 1. Mock exam UI

`QuizEngine::startMock()` already composes the paper (70% historical pattern /
20% adjacent / 10% high-probability prediction) and the `quiz_sessions` /
`quiz_answers` tables already carry everything needed. What is missing is the
exam experience and the analysis.

### Decided

**Two modes, chosen on the setup screen.**

| | Strict exam sim | Practice mode |
|---|---|---|
| Feedback | None until submit | Right/wrong + explanation after each answer |
| Timer | Runs, hard auto-submit at 0:00 | Runs, same auto-submit |
| Navigation | Free, flag-for-review, answer-sheet grid | Same |
| Negative marking | Applied (−0.5 per wrong for BCS) | Applied |

Store the choice on `quiz_sessions.meta.mode`. The answer endpoint must **not**
return `correct_index` / `explanation` when `mode === 'strict'` — enforce that
server-side, not by hiding it in the client.

**Resume with elapsed time deducted.** `started_at` is already recorded.
Remaining time is recomputed server-side from the wall clock on every resume, so
closing the tab gains nothing. The client clock is display only; the server is
the authority and rejects answers submitted after expiry.

Add `expires_at` to `quiz_sessions.meta` at creation, and a guard in
`QuizEngine::answer()`.

**Post-exam analysis — all four:**

1. **Subject-wise breakdown** — score per subject against that subject's mark
   weight. Needs a join through `questions → topics → subjects`.
2. **Full question review** — your answer, correct answer, explanation, and the
   past sessions it appeared in (`questions.meta.years`).
3. **Pacing analysis** — time per question from `quiz_answers.time_ms`; flag
   questions where time was burned *and* the answer was wrong. That combination
   is the actionable one.
4. **Readiness delta** — estimated score before vs after, plus the specific
   topics to fix. Snapshot `learner_profiles.readiness` into
   `quiz_sessions.meta.readiness_before` at creation so the delta is real rather
   than recomputed after the fact.

### Build order

1. Backend: mode + expiry on session creation; server-side answer-key
   suppression in strict mode; expiry guard on answer.
2. Backend: `GET /api/v1/quizzes/{session}/analysis` returning the four sections.
3. Frontend: `MockSetupPage` (mode, question count, duration), `MockExamPage`
   (timer, grid, flags, auto-submit), `MockAnalysisPage`.
4. Tests: strict mode leaks nothing; expired session rejects answers; resume
   deducts elapsed time; negative marking arithmetic; subject breakdown sums
   correctly.

### Watch out for

- Negative marking means a score can go below zero. Decide whether to floor it at
  0 for display (recommend: show the true negative, it is the honest signal) and
  make sure `quiz_sessions.score` has room for it — it is currently
  `decimal(6,2)` unsigned by convention only, so verify.
- 100 questions in one payload is fine, but the answer-sheet grid should not
  re-render the whole list on every selection.

---

## 2. Document ingestion (PDF → OCR → question extraction)

Build after the mock exam. Blocked in practice by the licensed question corpus —
see `roadmap.md` item 1.

### Shape

```
upload → queue job → OCR (Bangla + English) → LLM extraction (document.parse task,
already routed in config/ai.php) → topic classification (topic.classify, already
routed) → human review queue in Filament → publish into past_questions
```

**Never auto-publish.** An extracted question with a wrong answer key poisons
the importance scores, the lessons and the readiness estimate simultaneously.
The review queue is the whole point, not a nicety.

New tables needed: `source_documents` (upload, status, page count) and
`extracted_questions` (staging rows with confidence, page reference and a link to
the source, promoted into `past_questions` on approval).

Runs on queue workers — it is bursty, CPU-heavy and latency-tolerant, which is
also why it is the first candidate for extraction into its own service later
(see `architecture.md`).
