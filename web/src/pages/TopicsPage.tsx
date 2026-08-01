import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { api } from '@/lib/api'
import type { Exam, TopicSummary } from '@/lib/types'
import { Card, Spinner, Stars } from '@/components/ui'

export default function TopicsPage() {
  const { slug = '' } = useParams()
  const [subject, setSubject] = useState<string>('all')

  const { data: exam } = useQuery({
    queryKey: ['exam', slug],
    queryFn: async () => (await api.get<{ data: Exam }>(`/v1/exams/${slug}`)).data,
  })

  const { data: topics, isLoading } = useQuery({
    queryKey: ['topics', slug],
    queryFn: async () =>
      (await api.get<{ data: TopicSummary[] }>(`/v1/exams/${slug}/topics?limit=200`)).data,
  })

  const subjects = useMemo(
    () => Array.from(new Set((topics ?? []).map((topic) => topic.subject.name))).sort(),
    [topics],
  )

  const visible = useMemo(
    () => (subject === 'all' ? topics ?? [] : (topics ?? []).filter((t) => t.subject.name === subject)),
    [topics, subject],
  )

  if (isLoading) return <Spinner label="Ranking chapters" />

  return (
    <div className="mx-auto max-w-4xl px-5 py-8">
      <h1 className="text-2xl font-bold tracking-tight text-ink-900">
        {exam?.name ?? 'Chapters'}
      </h1>
      <p className="mt-1 text-sm text-ink-600">
        Ordered by how likely each chapter is to be worth marks — most examined first, not
        syllabus order.
      </p>

      <div className="mt-5 flex flex-wrap gap-2">
        <FilterChip active={subject === 'all'} onClick={() => setSubject('all')}>
          All subjects
        </FilterChip>
        {subjects.map((name) => (
          <FilterChip key={name} active={subject === name} onClick={() => setSubject(name)}>
            {name}
          </FilterChip>
        ))}
      </div>

      <div className="mt-5 space-y-3">
        {visible.map((topic) => (
          <Link key={topic.id} to={`/topics/${topic.id}/lesson`} className="block">
            <Card className="transition hover:border-brand-200 hover:shadow-md">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="text-xs font-medium uppercase tracking-wide text-ink-400">
                    {topic.subject.name}
                  </div>
                  <h2 className="mt-0.5 font-semibold text-ink-900">{topic.name}</h2>
                </div>

                <div className="shrink-0 text-right">
                  <Stars count={topic.probability_stars} />
                  <div className="text-xs text-ink-400">
                    {topic.estimated_minutes} min to learn
                  </div>
                </div>
              </div>

              {/* Forecast markers. Stars rank historical importance; these say
                  something different — where this chapter sits in its own cycle
                  right now. */}
              {topic.forecast && (
                <div className="mt-2 flex flex-wrap gap-1.5">
                  {topic.forecast.is_overdue && (
                    <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">
                      Due again
                    </span>
                  )}
                  {topic.forecast.trend === 'rising' && (
                    <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">
                      Trending up
                    </span>
                  )}
                  {topic.forecast.confidence < 0.45 &&
                    (topic.forecast.is_overdue || topic.forecast.trend === 'rising') && (
                      <span className="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700">
                        weak evidence
                      </span>
                    )}
                </div>
              )}

              <dl className="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-xs text-ink-600">
                <div>
                  <dt className="inline text-ink-400">Asked before: </dt>
                  <dd className="inline font-semibold tabular-nums">{topic.question_count}</dd>
                </div>
                {topic.expected_questions && (
                  <div>
                    <dt className="inline text-ink-400">Expected next: </dt>
                    <dd className="inline font-semibold">{topic.expected_questions}</dd>
                  </div>
                )}
                {topic.last_examined && (
                  <div>
                    <dt className="inline text-ink-400">Last seen: </dt>
                    <dd className="inline font-semibold tabular-nums">{topic.last_examined}</dd>
                  </div>
                )}
                {topic.mastery > 0 && (
                  <div>
                    <dt className="inline text-ink-400">Your mastery: </dt>
                    <dd className="inline font-semibold tabular-nums">
                      {Math.round(topic.mastery * 100)}%
                    </dd>
                  </div>
                )}
              </dl>
            </Card>
          </Link>
        ))}

        {visible.length === 0 && (
          <Card>
            <p className="text-sm text-ink-600">
              No chapters scored yet. Run <code className="rounded bg-ink-100 px-1.5 py-0.5">php
              artisan examora:bootstrap</code> to index past questions and rank them.
            </p>
          </Card>
        )}
      </div>
    </div>
  )
}

function FilterChip({
  active,
  onClick,
  children,
}: {
  active: boolean
  onClick: () => void
  children: React.ReactNode
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`rounded-full px-3 py-1.5 text-xs font-medium transition ${
        active ? 'bg-ink-900 text-white' : 'bg-white text-ink-600 ring-1 ring-ink-200 hover:bg-ink-100'
      }`}
    >
      {children}
    </button>
  )
}
