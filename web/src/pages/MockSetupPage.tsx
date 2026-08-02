import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { api } from '@/lib/api'
import type { Exam, ExamMode, QuizSession } from '@/lib/types'
import { Button, Card, ErrorNote, Spinner } from '@/components/ui'
import { Locked } from '@/components/Locked'
import { useEntitlements } from '@/lib/entitlements'

const LENGTHS = [
  { count: 25, label: 'Quick', hint: 'A quarter paper' },
  { count: 50, label: 'Half', hint: 'Half the real thing' },
  { count: 100, label: 'Full paper', hint: 'Real exam length' },
]

export default function MockSetupPage() {
  const navigate = useNavigate()

  const { can } = useEntitlements()
  const [mode, setMode] = useState<ExamMode>('strict')
  const [count, setCount] = useState(50)

  const { data: me, isLoading: loadingMe } = useQuery({
    queryKey: ['me'],
    queryFn: async () =>
      (await api.get<{ data: { active_enrollment: { exam: { slug: string } } | null } }>('/v1/me')).data,
  })

  const slug = me?.active_enrollment?.exam.slug

  // /v1/me carries only the exam's identity; the marks, duration and negative
  // marking the student needs to see before starting come from the detail route.
  const { data: exam, isLoading: loadingExam } = useQuery({
    queryKey: ['exam', slug],
    enabled: Boolean(slug),
    queryFn: async () => (await api.get<{ data: Exam }>(`/v1/exams/${slug}`)).data,
  })

  const start = useMutation({
    mutationFn: async (examId: number) =>
      (
        await api.post<{ data: QuizSession }>('/v1/quizzes', {
          type: 'mock',
          exam_id: examId,
          count,
          mode,
        })
      ).data,
    onSuccess: (session) => navigate(`/mock/${session.id}`, { replace: true }),
  })

  if (loadingMe || loadingExam) return <Spinner label="Loading" />

  // Locked before anything is rendered, not after a request comes back 402.
  if (!can('mock_exam')) {
    return (
      <div className="mx-auto max-w-2xl px-5 py-10">
        <Locked title="Mock exams">
          A full timed paper under real exam conditions — server-held clock, negative marking,
          answer-sheet grid — then an analysis of where the marks went, where your time went, and
          what to fix before the next one. The only way to practise pacing.
        </Locked>
      </div>
    )
  }

  if (!exam) {
    return (
      <div className="mx-auto max-w-lg px-5 py-10">
        <ErrorNote>Pick an exam before taking a mock.</ErrorNote>
      </div>
    )
  }

  // The engine scales the clock to the paper, so a shorter mock keeps the same
  // seconds-per-question pressure as the real thing.
  const minutes = Math.max(1, Math.round(exam.duration_minutes * (count / exam.total_marks)))

  return (
    <div className="mx-auto max-w-2xl px-5 py-10">
      <h1 className="text-2xl font-bold tracking-tight text-ink-900">Mock exam</h1>
      <p className="mt-1 text-sm text-ink-600">
        {exam.name} · {exam.total_marks} marks · {exam.duration_minutes} minutes ·{' '}
        {exam.negative_marking} deducted per wrong answer
      </p>

      <Card className="mt-6">
        <h2 className="font-semibold text-ink-900">How do you want to take it?</h2>

        <div className="mt-3 grid gap-3 sm:grid-cols-2">
          <ModeCard
            active={mode === 'strict'}
            onClick={() => setMode('strict')}
            title="Exam conditions"
            body="No feedback until you submit. This is the only way to practise pacing and learn when to skip."
          />
          <ModeCard
            active={mode === 'practice'}
            onClick={() => setMode('practice')}
            title="Practice"
            body="See the answer and explanation after each question. Better for learning, useless for pacing."
          />
        </div>
      </Card>

      <Card className="mt-4">
        <h2 className="font-semibold text-ink-900">How long?</h2>

        <div className="mt-3 grid gap-3 sm:grid-cols-3">
          {LENGTHS.map((option) => (
            <button
              key={option.count}
              type="button"
              onClick={() => setCount(option.count)}
              className={`rounded-xl border p-4 text-left transition ${
                count === option.count
                  ? 'border-brand-500 bg-brand-50 ring-2 ring-brand-100'
                  : 'border-ink-200 bg-white hover:border-ink-300'
              }`}
            >
              <div className="font-semibold text-ink-900">{option.label}</div>
              <div className="text-sm text-ink-600">{option.count} questions</div>
              <div className="mt-1 text-xs text-ink-400">{option.hint}</div>
            </button>
          ))}
        </div>

        <p className="mt-4 text-sm text-ink-600">
          You'll get <strong className="tabular-nums">{minutes} minutes</strong>. The clock runs on
          the server — closing the tab doesn't pause it.
        </p>
      </Card>

      {start.isError && (
        <div className="mt-4">
          <ErrorNote>{(start.error as Error).message}</ErrorNote>
        </div>
      )}

      <Button
        className="mt-5 w-full"
        loading={start.isPending}
        onClick={() => start.mutate(exam.id)}
      >
        Start the exam
      </Button>
    </div>
  )
}

function ModeCard({
  active,
  onClick,
  title,
  body,
}: {
  active: boolean
  onClick: () => void
  title: string
  body: string
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`rounded-xl border p-4 text-left transition ${
        active
          ? 'border-brand-500 bg-brand-50 ring-2 ring-brand-100'
          : 'border-ink-200 bg-white hover:border-ink-300'
      }`}
    >
      <div className="font-semibold text-ink-900">{title}</div>
      <p className="mt-1 text-sm text-ink-600">{body}</p>
    </button>
  )
}
