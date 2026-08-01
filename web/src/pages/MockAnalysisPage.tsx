import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { api } from '@/lib/api'
import type { MockAnalysis } from '@/lib/types'
import { Button, Card, ErrorNote, Progress, Spinner, Stat } from '@/components/ui'

type Tab = 'subjects' | 'review' | 'pacing' | 'readiness'

export default function MockAnalysisPage() {
  const { sessionId = '' } = useParams()
  const [tab, setTab] = useState<Tab>('subjects')
  const [filter, setFilter] = useState<'all' | 'wrong' | 'skipped'>('all')

  const { data, isLoading, error } = useQuery({
    queryKey: ['mock-analysis', sessionId],
    retry: false,
    queryFn: async () => (await api.get<{ data: MockAnalysis }>(`/v1/quizzes/${sessionId}/analysis`)).data,
  })

  if (isLoading) return <Spinner label="Marking your paper" />
  if (error) return <div className="p-5"><ErrorNote>{(error as Error).message}</ErrorNote></div>
  if (!data) return null

  const { summary, readiness } = data

  return (
    <div className="mx-auto max-w-4xl px-5 py-8">
      <header className="text-center">
        <div className="text-xs font-medium uppercase tracking-wide text-ink-400">
          {summary.mode === 'strict' ? 'Exam conditions' : 'Practice mode'}
        </div>
        <div
          className={`mt-1 text-6xl font-bold tabular-nums ${
            summary.marks < 0 ? 'text-red-600' : 'text-brand-600'
          }`}
        >
          {summary.marks}
        </div>
        <p className="mt-1 text-sm text-ink-600">
          out of {summary.max_marks} marks · {summary.score_percent}%
        </p>
      </header>

      <div className="mt-6 grid gap-4 sm:grid-cols-4">
        <Card>
          <Stat label="Correct" value={summary.correct} />
        </Card>
        <Card>
          <Stat
            label="Wrong"
            value={summary.wrong}
            hint={summary.penalty_marks > 0 ? `−${summary.penalty_marks} marks` : undefined}
          />
        </Card>
        <Card>
          <Stat label="Skipped" value={summary.skipped} hint="No penalty" />
        </Card>
        <Card>
          <Stat
            label="Accuracy"
            value={`${Math.round(summary.accuracy_on_attempted * 100)}%`}
            hint="On what you attempted"
          />
        </Card>
      </div>

      {summary.negative_marking > 0 && summary.penalty_marks > 0 && (
        <Card className="mt-4 bg-amber-50/70">
          <p className="text-sm text-ink-800">
            Wrong answers cost you{' '}
            <strong className="tabular-nums">{summary.penalty_marks} marks</strong>. You'd have
            scored{' '}
            <strong className="tabular-nums">
              {(summary.marks + summary.penalty_marks).toFixed(1)}
            </strong>{' '}
            by leaving those blank. Under negative marking, a guess you're genuinely unsure of is
            worse than a skip.
          </p>
        </Card>
      )}

      <nav className="mt-6 flex flex-wrap gap-2">
        {(
          [
            ['subjects', 'Where the marks went'],
            ['review', 'Question review'],
            ['pacing', 'Pacing'],
            ['readiness', 'Readiness'],
          ] as const
        ).map(([value, label]) => (
          <button
            key={value}
            type="button"
            onClick={() => setTab(value)}
            className={`rounded-full px-3.5 py-1.5 text-sm font-medium transition ${
              tab === value
                ? 'bg-ink-900 text-white'
                : 'bg-white text-ink-600 ring-1 ring-ink-200 hover:bg-ink-100'
            }`}
          >
            {label}
          </button>
        ))}
      </nav>

      <div className="mt-4">
        {tab === 'subjects' && (
          <Card>
            <h2 className="font-semibold text-ink-900">Subject breakdown</h2>
            <p className="mt-1 text-sm text-ink-600">Weakest first — this is what to work on.</p>

            <ul className="mt-4 space-y-4">
              {data.subjects.map((row) => (
                <li key={row.subject}>
                  <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <span className="font-medium text-ink-900">{row.subject}</span>
                    <span className="text-sm tabular-nums text-ink-600">
                      {row.marks} / {row.total}
                      {row.exam_marks && (
                        <span className="text-ink-400"> · {row.exam_marks} marks in the real exam</span>
                      )}
                    </span>
                  </div>

                  <div className="mt-1.5">
                    <Progress value={Math.max(0, row.score_percent)} />
                  </div>

                  <div className="mt-1 text-xs text-ink-400">
                    {row.correct} correct · {row.wrong} wrong · {row.skipped} skipped
                  </div>
                </li>
              ))}
            </ul>
          </Card>
        )}

        {tab === 'review' && (
          <Card>
            <div className="flex flex-wrap items-center justify-between gap-3">
              <h2 className="font-semibold text-ink-900">Question review</h2>
              <div className="flex gap-2">
                {(
                  [
                    ['all', 'All'],
                    ['wrong', 'Wrong only'],
                    ['skipped', 'Skipped'],
                  ] as const
                ).map(([value, label]) => (
                  <button
                    key={value}
                    type="button"
                    onClick={() => setFilter(value)}
                    className={`rounded-lg px-2.5 py-1 text-xs font-medium transition ${
                      filter === value ? 'bg-ink-900 text-white' : 'bg-ink-100 text-ink-600'
                    }`}
                  >
                    {label}
                  </button>
                ))}
              </div>
            </div>

            <ol className="mt-4 space-y-4">
              {data.review
                .filter((item) =>
                  filter === 'all'
                    ? true
                    : filter === 'wrong'
                      ? !item.is_correct && !item.skipped
                      : item.skipped,
                )
                .map((item) => (
                  <li key={item.question_id} className="rounded-xl border border-ink-100 p-4">
                    <div className="flex items-start gap-3">
                      <span
                        className={`flex size-6 shrink-0 items-center justify-center rounded-lg text-xs font-bold ${
                          item.is_correct
                            ? 'bg-emerald-100 text-emerald-700'
                            : item.skipped
                              ? 'bg-ink-100 text-ink-500'
                              : 'bg-red-100 text-red-700'
                        }`}
                      >
                        {item.position}
                      </span>
                      <div className="min-w-0 flex-1">
                        <p className="font-medium text-ink-900">{item.stem}</p>

                        <ul className="mt-2 space-y-1 text-sm">
                          {item.options.map((option, optionIndex) => {
                            const isCorrect = optionIndex === item.correct_index
                            const isYours = optionIndex === item.selected_index

                            return (
                              <li
                                key={optionIndex}
                                className={`rounded-lg px-2.5 py-1.5 ${
                                  isCorrect
                                    ? 'bg-emerald-50 text-emerald-900'
                                    : isYours
                                      ? 'bg-red-50 text-red-900'
                                      : 'text-ink-600'
                                }`}
                              >
                                <span className="font-semibold">
                                  {String.fromCharCode(65 + optionIndex)}.
                                </span>{' '}
                                {option}
                                {isCorrect && <span className="ml-2 text-xs font-semibold">correct</span>}
                                {isYours && !isCorrect && (
                                  <span className="ml-2 text-xs font-semibold">your answer</span>
                                )}
                              </li>
                            )
                          })}
                        </ul>

                        {item.explanation && (
                          <p className="mt-2 text-sm text-ink-700">{item.explanation}</p>
                        )}

                        <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-400">
                          {item.subject && <span>{item.subject}</span>}
                          {item.previously_examined.length > 0 && (
                            <span>Examined in {item.previously_examined.join(', ')}</span>
                          )}
                          {item.time_ms !== null && (
                            <span className="tabular-nums">
                              {(item.time_ms / 1000).toFixed(1)}s
                            </span>
                          )}
                        </div>
                      </div>
                    </div>
                  </li>
                ))}
            </ol>
          </Card>
        )}

        {tab === 'pacing' && (
          <Card>
            <h2 className="font-semibold text-ink-900">Pacing</h2>

            {data.pacing.median_ms === null ? (
              <p className="mt-2 text-sm text-ink-600">No timing data was recorded for this paper.</p>
            ) : (
              <>
                <div className="mt-3 grid gap-4 sm:grid-cols-3">
                  <Stat label="Median" value={`${(data.pacing.median_ms / 1000).toFixed(1)}s`} />
                  <Stat label="Mean" value={`${((data.pacing.mean_ms ?? 0) / 1000).toFixed(1)}s`} />
                  {data.pacing.budget_ms && (
                    <Stat
                      label="Budget"
                      value={`${(data.pacing.budget_ms / 1000).toFixed(1)}s`}
                      hint="Per question"
                    />
                  )}
                </div>

                <PacingList
                  title="Time sinks"
                  hint="Slow and still wrong — these topics need work before the real paper."
                  items={data.pacing.time_sinks}
                  empty="Nothing here. You didn't lose marks to slow thinking."
                />

                <PacingList
                  title="Rushed mistakes"
                  hint="Answered fast and got it wrong — usually carelessness, not knowledge."
                  items={data.pacing.rushed_mistakes}
                  empty="No rushed errors."
                />
              </>
            )}
          </Card>
        )}

        {tab === 'readiness' && (
          <Card>
            <h2 className="font-semibold text-ink-900">Readiness</h2>

            <div className="mt-4 flex flex-wrap items-end gap-6">
              <div>
                <div className="text-xs uppercase tracking-wide text-ink-400">Before</div>
                <div className="text-2xl font-bold tabular-nums text-ink-600">
                  {readiness.before}%
                </div>
              </div>
              <div className="text-2xl text-ink-300">→</div>
              <div>
                <div className="text-xs uppercase tracking-wide text-ink-400">After</div>
                <div className="text-2xl font-bold tabular-nums text-ink-900">
                  {readiness.after}%
                </div>
              </div>
              <div
                className={`rounded-full px-3 py-1 text-sm font-semibold ${
                  readiness.delta >= 0
                    ? 'bg-emerald-100 text-emerald-700'
                    : 'bg-red-100 text-red-700'
                }`}
              >
                {readiness.delta >= 0 ? '+' : ''}
                {readiness.delta}%
              </div>
            </div>

            <p className="mt-3 text-sm text-ink-600">
              Estimated score moved from{' '}
              <strong className="tabular-nums">{readiness.estimated_score_before}</strong> to{' '}
              <strong className="tabular-nums">{readiness.estimated_score_after}</strong>
              {readiness.exam_total_marks && ` of ${readiness.exam_total_marks}`}.
            </p>

            <h3 className="mt-6 text-sm font-semibold text-ink-900">Fix these before the next mock</h3>

            {readiness.fix_next.length === 0 ? (
              <p className="mt-2 text-sm text-ink-600">
                Nothing is dragging you down right now. Keep covering new chapters.
              </p>
            ) : (
              <ul className="mt-2 space-y-2 text-sm">
                {readiness.fix_next.map((row) => (
                  <li
                    key={row.topic.id}
                    className="flex items-center justify-between gap-3 rounded-lg bg-ink-50 px-3 py-2"
                  >
                    <span className="truncate text-ink-800">{row.topic.name}</span>
                    <span className="shrink-0 tabular-nums text-ink-400">
                      {row.accuracy}% correct
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        )}
      </div>

      <div className="mt-6 flex flex-wrap gap-3">
        <Link to="/">
          <Button variant="secondary">Back to today</Button>
        </Link>
        <Link to="/mock">
          <Button>Take another</Button>
        </Link>
      </div>
    </div>
  )
}

function PacingList({
  title,
  hint,
  items,
  empty,
}: {
  title: string
  hint: string
  items: { question_id: number; time_ms: number; stem: string; topic: string | null }[]
  empty: string
}) {
  return (
    <div className="mt-5">
      <h3 className="text-sm font-semibold text-ink-900">{title}</h3>
      <p className="text-xs text-ink-400">{hint}</p>

      {items.length === 0 ? (
        <p className="mt-2 text-sm text-ink-600">{empty}</p>
      ) : (
        <ul className="mt-2 space-y-2 text-sm">
          {items.map((item) => (
            <li
              key={item.question_id}
              className="flex items-start justify-between gap-3 rounded-lg bg-ink-50 px-3 py-2"
            >
              <span className="min-w-0">
                <span className="block truncate text-ink-800">{item.stem}</span>
                {item.topic && <span className="text-xs text-ink-400">{item.topic}</span>}
              </span>
              <span className="shrink-0 tabular-nums text-ink-500">
                {(item.time_ms / 1000).toFixed(0)}s
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
