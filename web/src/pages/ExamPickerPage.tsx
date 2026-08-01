import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { api } from '@/lib/api'
import type { Exam } from '@/lib/types'
import { Button, Card, ErrorNote, Spinner } from '@/components/ui'

export default function ExamPickerPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const [selected, setSelected] = useState<Exam | null>(null)
  const [targetSession, setTargetSession] = useState('BCS 48')
  const [targetDate, setTargetDate] = useState(defaultTargetDate())
  const [minutes, setMinutes] = useState(20)

  const { data: exams, isLoading } = useQuery({
    queryKey: ['exams'],
    queryFn: async () => (await api.get<{ data: Exam[] }>('/v1/exams')).data,
  })

  const enroll = useMutation({
    mutationFn: async (exam: Exam) =>
      api.post(`/v1/exams/${exam.slug}/enroll`, {
        target_session: targetSession || null,
        target_date: targetDate || null,
        daily_minutes_goal: minutes,
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries()
      navigate('/')
    },
  })

  if (isLoading) return <Spinner label="Loading exams" />

  return (
    <div className="mx-auto max-w-3xl px-5 py-10">
      <h1 className="text-2xl font-bold tracking-tight text-ink-900">Which exam are you sitting?</h1>
      <p className="mt-1 text-sm text-ink-600">
        Everything — lessons, practice, revision — is built around this one target.
      </p>

      <div className="mt-6 space-y-3">
        {exams?.map((exam) => (
          <button
            key={exam.id}
            type="button"
            onClick={() => setSelected(exam)}
            className={`w-full rounded-2xl border p-5 text-left transition ${
              selected?.id === exam.id
                ? 'border-brand-500 bg-brand-50 ring-2 ring-brand-100'
                : 'border-ink-100 bg-white hover:border-ink-200'
            }`}
          >
            <div className="flex items-start justify-between gap-4">
              <div>
                <div className="font-semibold text-ink-900">{exam.name}</div>
                <div className="text-sm text-ink-600">{exam.authority}</div>
              </div>
              <div className="shrink-0 text-right text-xs text-ink-400">
                <div>{exam.total_marks} marks</div>
                <div>{exam.duration_minutes} min</div>
              </div>
            </div>

            <p className="mt-2 text-sm text-ink-600">{exam.description}</p>

            <div className="mt-3 flex flex-wrap gap-2 text-xs">
              <span className="rounded-full bg-ink-100 px-2.5 py-1 font-medium text-ink-600">
                {exam.past_questions_count} past questions indexed
              </span>
              {exam.subjects.slice(0, 4).map((subject) => (
                <span key={subject.id} className="rounded-full bg-ink-100 px-2.5 py-1 text-ink-600">
                  {subject.name} · {subject.marks}
                </span>
              ))}
            </div>
          </button>
        ))}
      </div>

      {selected && (
        <Card className="mt-6">
          <h2 className="font-semibold text-ink-900">Set your target</h2>
          <p className="mt-1 text-sm text-ink-600">
            The planner uses the days remaining to decide how selective to be. With little time left it
            will skip low-probability chapters entirely.
          </p>

          <div className="mt-4 grid gap-4 sm:grid-cols-3">
            <label className="block">
              <span className="mb-1.5 block text-sm font-medium text-ink-600">Session</span>
              <input
                value={targetSession}
                onChange={(event) => setTargetSession(event.target.value)}
                className={inputClass}
                placeholder="BCS 48"
              />
            </label>

            <label className="block">
              <span className="mb-1.5 block text-sm font-medium text-ink-600">Exam date</span>
              <input
                type="date"
                value={targetDate}
                onChange={(event) => setTargetDate(event.target.value)}
                className={inputClass}
              />
            </label>

            <label className="block">
              <span className="mb-1.5 block text-sm font-medium text-ink-600">Minutes a day</span>
              <input
                type="number"
                min={5}
                max={240}
                value={minutes}
                onChange={(event) => setMinutes(Number(event.target.value))}
                className={inputClass}
              />
            </label>
          </div>

          {enroll.isError && (
            <div className="mt-4">
              <ErrorNote>{(enroll.error as Error).message}</ErrorNote>
            </div>
          )}

          <Button
            className="mt-5"
            loading={enroll.isPending}
            onClick={() => enroll.mutate(selected)}
          >
            Start preparing for {selected.name}
          </Button>
        </Card>
      )}
    </div>
  )
}

const inputClass =
  'w-full rounded-xl border border-ink-200 bg-white px-3.5 py-2.5 text-sm outline-none transition focus:border-brand-500 focus:ring-2 focus:ring-brand-100'

function defaultTargetDate(): string {
  const date = new Date()
  date.setDate(date.getDate() + 90)

  return date.toISOString().slice(0, 10)
}
