import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '@/lib/api'
import type { Me, Predictions } from '@/lib/types'
import { Card, ErrorNote, Progress, Spinner } from '@/components/ui'

export default function PredictionsPage() {
  const [tab, setTab] = useState<'topics' | 'repeats'>('topics')
  const [expanded, setExpanded] = useState<number | null>(null)

  const { data: me } = useQuery({
    queryKey: ['me'],
    queryFn: async () => (await api.get<{ data: Me }>('/v1/me')).data,
  })

  const slug = me?.active_enrollment?.exam.slug

  const { data, isLoading } = useQuery({
    queryKey: ['predictions', slug],
    enabled: Boolean(slug),
    queryFn: async () => (await api.get<{ data: Predictions | null }>(`/v1/exams/${slug}/predictions`)).data,
  })

  if (!slug) {
    return (
      <div className="mx-auto max-w-lg px-5 py-10">
        <ErrorNote>Pick an exam to see its forecast.</ErrorNote>
      </div>
    )
  }

  if (isLoading) return <Spinner label="Reading the past papers" />

  if (!data) {
    return (
      <div className="mx-auto max-w-2xl px-5 py-10">
        <Card>
          <p className="text-sm text-ink-600">
            No forecast yet. It needs a question bank with several sessions of history behind it.
          </p>
        </Card>
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-3xl px-5 py-8">
      <h1 className="text-2xl font-bold tracking-tight text-ink-900">
        What {data.target_year} is likely to ask
      </h1>
      <p className="mt-1 text-sm text-ink-600">{data.exam.name}</p>

      {/* The caveat is not a footnote. A student deciding what to skip needs to
          see it before the ranking, not after. */}
      <Card className="mt-4 border-amber-200 bg-amber-50/70">
        <p className="text-sm font-medium text-ink-900">{data.disclaimer}</p>
        <p className="mt-1.5 text-sm text-ink-700">
          This reads patterns in past papers. Examiners are free to ignore them, and a topic ranked
          low here can still appear. Use it to decide what to study <em>first</em>, never what to
          drop.
        </p>
      </Card>

      {!data.freshness.is_current && (
        <Card className="mt-3 border-red-200 bg-red-50/70">
          <p className="text-sm text-ink-900">
            The question bank stops at{' '}
            <strong>{data.freshness.last_session_in_bank ?? 'an unknown session'}</strong>, so this
            forecasts <strong>{data.target_year}</strong>
            {data.freshness.sessions_behind
              ? ` — roughly ${data.freshness.sessions_behind} session${
                  data.freshness.sessions_behind === 1 ? '' : 's'
                } behind today.`
              : '.'}{' '}
            Treat it as historical until newer papers are added.
          </p>
        </Card>
      )}

      <nav className="mt-6 flex gap-2">
        {(
          [
            ['topics', `Topic forecast (${data.topics.length})`],
            ['repeats', `Likely repeats (${data.repeat_candidates.length})`],
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

      {tab === 'topics' && (
        <ul className="mt-4 space-y-3">
          {data.topics.map((row) => {
            const open = expanded === row.topic.id

            return (
              <li key={row.topic.id}>
                <Card>
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                      <div className="text-xs font-medium uppercase tracking-wide text-ink-400">
                        {row.topic.subject}
                      </div>
                      <h2 className="mt-0.5 font-semibold text-ink-900">{row.topic.name}</h2>

                      <div className="mt-1.5 flex flex-wrap gap-1.5">
                        {row.is_overdue && <Tag tone="amber">Due again</Tag>}
                        {row.trend === 'rising' && <Tag tone="emerald">Trending up</Tag>}
                        {row.trend === 'falling' && <Tag tone="gray">Trending down</Tag>}
                        <ConfidenceTag label={row.confidence_label} />
                      </div>
                    </div>

                    <div className="shrink-0 text-right">
                      <div className="text-2xl font-bold tabular-nums text-brand-600">
                        {Math.round(row.appearance_probability * 100)}%
                      </div>
                      <div className="text-xs text-ink-400">chance of appearing</div>
                      <div className="mt-1 text-xs tabular-nums text-ink-600">
                        ~{row.expected_questions.toFixed(1)} question
                        {row.expected_questions === 1 ? '' : 's'}
                      </div>
                      <div className="text-xs tabular-nums text-ink-400">
                        likely {row.expected_range[0].toFixed(1)}–{row.expected_range[1].toFixed(1)}
                      </div>
                    </div>
                  </div>

                  {row.mastery > 0 && (
                    <div className="mt-3">
                      <Progress value={row.mastery * 100} label="Your mastery" />
                    </div>
                  )}

                  <button
                    type="button"
                    onClick={() => setExpanded(open ? null : row.topic.id)}
                    className="mt-3 text-sm font-semibold text-brand-600 hover:text-brand-700"
                  >
                    {open ? 'Hide the reasoning' : 'Why?'}
                  </button>

                  {open && (
                    <div className="mt-2 rounded-xl bg-ink-50 p-3.5">
                      <ul className="space-y-1 text-sm text-ink-700">
                        {row.evidence.map((line, index) => (
                          <li key={index}>· {line}</li>
                        ))}
                      </ul>

                      <Link
                        to={`/topics/${row.topic.id}/lesson`}
                        className="mt-3 inline-block text-sm font-semibold text-brand-600 hover:text-brand-700"
                      >
                        Study this chapter →
                      </Link>
                    </div>
                  )}
                </Card>
              </li>
            )
          })}
        </ul>
      )}

      {tab === 'repeats' && (
        <div className="mt-4">
          <Card className="bg-brand-50/60">
            <p className="text-sm text-ink-800">
              Questions this exam has already reused word for word. Repeats are the highest-value
              thing to memorise — but a question appearing here is not a promise it returns.
            </p>
          </Card>

          {data.repeat_candidates.length === 0 ? (
            <Card className="mt-3">
              <p className="text-sm text-ink-600">
                No question in the bank has repeated yet, so there is nothing to go on.
              </p>
            </Card>
          ) : (
            <ol className="mt-3 space-y-3">
              {data.repeat_candidates.map((row) => (
                <li key={row.past_question_id}>
                  <Card>
                    <div className="flex items-start justify-between gap-3">
                      <p className="font-medium text-ink-900">{row.stem}</p>
                      <span className="shrink-0 rounded-full bg-ink-100 px-2.5 py-1 text-xs font-semibold tabular-nums text-ink-600">
                        ×{row.appearances}
                      </span>
                    </div>

                    {row.answer && (
                      <p className="mt-2 text-sm">
                        <span className="font-semibold text-emerald-700">Answer:</span>{' '}
                        <span className="text-ink-800">{row.answer}</span>
                      </p>
                    )}

                    {row.explanation && (
                      <p className="mt-1 text-sm text-ink-600">{row.explanation}</p>
                    )}

                    <div className="mt-2 text-xs text-ink-400">
                      {row.evidence.join(' ')}
                      {row.topic && ` · ${row.topic.name}`}
                    </div>
                  </Card>
                </li>
              ))}
            </ol>
          )}
        </div>
      )}

      <p className="mt-6 text-center text-xs text-ink-400">
        Model {data.model_version}
        {data.computed_at && ` · computed ${new Date(data.computed_at).toLocaleDateString()}`}
      </p>
    </div>
  )
}

function Tag({ children, tone }: { children: React.ReactNode; tone: 'amber' | 'emerald' | 'gray' }) {
  const styles = {
    amber: 'bg-amber-100 text-amber-800',
    emerald: 'bg-emerald-100 text-emerald-800',
    gray: 'bg-ink-100 text-ink-600',
  }[tone]

  return <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${styles}`}>{children}</span>
}

function ConfidenceTag({ label }: { label: 'high' | 'moderate' | 'low' | 'very low' }) {
  // Low confidence is styled to draw the eye, not to blend in. A weak forecast
  // presented calmly is a weak forecast that gets trusted.
  const styles = {
    high: 'bg-ink-100 text-ink-600',
    moderate: 'bg-ink-100 text-ink-600',
    low: 'bg-red-100 text-red-700',
    'very low': 'bg-red-100 text-red-700',
  }[label]

  return <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${styles}`}>{label} confidence</span>
}
