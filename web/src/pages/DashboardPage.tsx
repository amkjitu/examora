import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { api } from '@/lib/api'
import type { Dashboard, PlanItem, QuizSession } from '@/lib/types'
import { Button, Card, ErrorNote, Progress, Spinner, Stat } from '@/components/ui'

export default function DashboardPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const { data, isLoading, error } = useQuery({
    queryKey: ['dashboard'],
    queryFn: async () => (await api.get<{ data: Dashboard | null }>('/v1/dashboard')).data,
  })

  const startRevision = useMutation({
    mutationFn: async (examId: number) =>
      (await api.post<{ data: QuizSession }>('/v1/quizzes', { type: 'revision', exam_id: examId })).data,
    onSuccess: (session) => navigate(`/quiz/${session.id}`),
  })

  const completeItem = useMutation({
    mutationFn: async (itemId: number) => api.post(`/v1/plan-items/${itemId}/complete`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
  })

  if (isLoading) return <Spinner label="Building today's plan" />
  if (error) return <div className="p-5"><ErrorNote>{(error as Error).message}</ErrorNote></div>
  if (!data) return null

  const { target, today, gamification, readiness } = data
  const level = gamification.level_progress

  return (
    <div className="mx-auto max-w-4xl space-y-5 px-5 py-8">
      <header className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <div className="text-xs font-medium uppercase tracking-wide text-ink-400">Target</div>
          <h1 className="text-2xl font-bold tracking-tight text-ink-900">
            {target.session ?? data.exam.name}
          </h1>
        </div>

        {target.days_left !== null && (
          <div className="text-right">
            <div className="text-3xl font-bold tabular-nums text-brand-600">{target.days_left}</div>
            <div className="text-xs font-medium uppercase tracking-wide text-ink-400">days left</div>
          </div>
        )}
      </header>

      <div className="grid gap-4 sm:grid-cols-3">
        <Card>
          <Stat
            label="Exam readiness"
            value={`${Math.round(readiness.readiness)}%`}
            hint={`Estimated score ${readiness.estimated_score} / ${data.exam.total_marks}`}
          />
          <div className="mt-3">
            <Progress value={readiness.readiness} />
          </div>
        </Card>

        <Card>
          <Stat
            label="Streak"
            value={`${gamification.streak_days} day${gamification.streak_days === 1 ? '' : 's'}`}
            hint={`Longest ${gamification.longest_streak}`}
          />
        </Card>

        <Card>
          <Stat label={`Level ${gamification.level}`} value={`${gamification.xp} XP`} />
          <div className="mt-3">
            <Progress value={level.into} max={level.needed} label="To next level" />
          </div>
        </Card>
      </div>

      <Card>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 className="font-semibold text-ink-900">Today's target</h2>
            <p className="text-sm text-ink-600">
              {today.completed_minutes} of {today.target_minutes} minutes done
            </p>
          </div>

          {data.revision_due > 0 && (
            <Button
              variant="secondary"
              loading={startRevision.isPending}
              onClick={() => startRevision.mutate(data.exam.id)}
            >
              Revise {data.revision_due} due
            </Button>
          )}
        </div>

        <div className="mt-3">
          <Progress value={today.completed_minutes} max={today.target_minutes} />
        </div>

        <ul className="mt-5 space-y-3">
          {today.items.length === 0 && (
            <li className="rounded-xl bg-ink-50 p-4 text-sm text-ink-600">
              Nothing scheduled. Browse chapters to get started.
            </li>
          )}

          {today.items.map((item) => (
            <PlanRow
              key={item.id}
              item={item}
              examId={data.exam.id}
              onComplete={() => completeItem.mutate(item.id)}
            />
          ))}
        </ul>
      </Card>

      <div className="grid gap-4 sm:grid-cols-2">
        <Card>
          <h2 className="font-semibold text-ink-900">Weakest topics</h2>
          {data.weakest_topics.length === 0 ? (
            <p className="mt-2 text-sm text-ink-600">
              Answer some questions and your weak spots will show up here.
            </p>
          ) : (
            <ul className="mt-3 space-y-2 text-sm">
              {data.weakest_topics.map((row) => (
                <li key={row.topic.id} className="flex items-center justify-between gap-3">
                  <span className="truncate text-ink-800">{row.topic.name}</span>
                  <span className="shrink-0 tabular-nums text-ink-400">{row.accuracy}% correct</span>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card>
          <h2 className="font-semibold text-ink-900">Coverage</h2>
          <p className="mt-2 text-sm text-ink-600">
            {readiness.covered_topics} of {readiness.total_topics} chapters are above 60% mastery.
          </p>
          <Link
            to={`/exams/${data.exam.slug}/topics`}
            className="mt-4 inline-block text-sm font-semibold text-brand-600 hover:text-brand-700"
          >
            Browse all chapters →
          </Link>
        </Card>
      </div>
    </div>
  )
}

function PlanRow({
  item,
  examId,
  onComplete,
}: {
  item: PlanItem
  examId: number
  onComplete: () => void
}) {
  const navigate = useNavigate()

  const startQuiz = useMutation({
    mutationFn: async (body: Record<string, unknown>) =>
      (await api.post<{ data: QuizSession }>('/v1/quizzes', body)).data,
    onSuccess: (session) => navigate(`/quiz/${session.id}`),
  })

  const label = {
    lesson: 'Lesson',
    practice: 'Practice',
    revision: 'Revision',
    mock: 'Mock exam',
  }[item.type]

  const tone = {
    lesson: 'bg-brand-50 text-brand-700',
    practice: 'bg-amber-50 text-amber-700',
    revision: 'bg-emerald-50 text-emerald-700',
    mock: 'bg-purple-50 text-purple-700',
  }[item.type]

  function start() {
    if (item.type === 'lesson' && item.topic) {
      navigate(`/topics/${item.topic.id}/lesson`)
      return
    }

    if (item.type === 'practice' && item.topic) {
      startQuiz.mutate({ type: 'practice', topic_id: item.topic.id })
      return
    }

    if (item.type === 'revision') {
      startQuiz.mutate({ type: 'revision', exam_id: examId })
      return
    }

    // A mock is a commitment with a clock attached, so it gets its own setup
    // screen rather than starting the moment the student taps a plan item.
    navigate('/mock')
  }

  const done = item.status === 'completed'

  return (
    <li className="flex flex-wrap items-center gap-3 rounded-xl border border-ink-100 p-4">
      <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${tone}`}>{label}</span>

      <div className="min-w-0 flex-1">
        <div className={`truncate font-medium ${done ? 'text-ink-400 line-through' : 'text-ink-900'}`}>
          {item.topic?.name ?? label}
        </div>
        {item.reason && <div className="truncate text-xs text-ink-400">{item.reason}</div>}
      </div>

      <span className="shrink-0 text-xs tabular-nums text-ink-400">{item.estimated_minutes} min</span>

      {done ? (
        <span className="text-sm font-medium text-emerald-600">Done</span>
      ) : (
        <div className="flex gap-2">
          <Button variant="ghost" onClick={onComplete}>
            Skip
          </Button>
          <Button loading={startQuiz.isPending} onClick={start}>
            Start
          </Button>
        </div>
      )}
    </li>
  )
}
