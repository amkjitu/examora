import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'
import { ApiError, api } from '@/lib/api'
import type { Lesson, QuizSession } from '@/lib/types'
import { Button, Card, ErrorNote, Progress, Spinner, Stars } from '@/components/ui'

export default function LessonPage() {
  const { topicId = '' } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const [index, setIndex] = useState(0)

  const { data: lesson, isLoading, error } = useQuery({
    queryKey: ['lesson', topicId],
    retry: false,
    queryFn: async () => (await api.get<{ data: Lesson }>(`/v1/topics/${topicId}/lesson`)).data,
  })

  const finish = useMutation({
    mutationFn: async (lessonId: number) => {
      await api.post(`/v1/lessons/${lessonId}/complete`)

      return (
        await api.post<{ data: QuizSession }>('/v1/quizzes', {
          type: 'lesson_check',
          lesson_id: lessonId,
        })
      ).data
    },
    onSuccess: async (session) => {
      await queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      navigate(`/quiz/${session.id}`)
    },
  })

  if (isLoading) return <Spinner label="Writing your lesson" />

  if (error) {
    const quota = error instanceof ApiError && error.isQuotaExceeded

    return (
      <div className="mx-auto max-w-2xl px-5 py-10">
        <ErrorNote>
          {quota
            ? "You've used today's free lessons. Revision and practice are still open, or upgrade for unlimited lessons."
            : (error as Error).message}
        </ErrorNote>
        <Button variant="secondary" className="mt-4" onClick={() => navigate('/')}>
          Back to today
        </Button>
      </div>
    )
  }

  if (!lesson) return null

  const cards = lesson.cards
  const card = cards[index]
  const isLast = index === cards.length - 1

  return (
    <div className="mx-auto max-w-2xl px-5 py-8">
      <div className="text-xs font-medium uppercase tracking-wide text-ink-400">
        {lesson.topic.exam} · {lesson.topic.subject}
      </div>
      <h1 className="mt-1 text-2xl font-bold tracking-tight text-ink-900">{lesson.title}</h1>
      {lesson.summary && <p className="mt-2 text-sm text-ink-600">{lesson.summary}</p>}

      {lesson.exam_intelligence && (
        <Card className="mt-5 bg-brand-50/60">
          <div className="flex flex-wrap items-center justify-between gap-4 text-sm">
            <div>
              <div className="text-xs font-medium uppercase tracking-wide text-ink-400">
                Asked previously
              </div>
              <div className="text-xl font-bold tabular-nums text-ink-900">
                {lesson.exam_intelligence.questions_asked_previously}
              </div>
            </div>

            <div>
              <div className="text-xs font-medium uppercase tracking-wide text-ink-400">
                Probability
              </div>
              <Stars count={lesson.exam_intelligence.probability_stars} />
            </div>

            <div>
              <div className="text-xs font-medium uppercase tracking-wide text-ink-400">
                Expected next
              </div>
              <div className="text-xl font-bold text-ink-900">
                {lesson.exam_intelligence.expected_questions}
              </div>
            </div>

            <div>
              <div className="text-xs font-medium uppercase tracking-wide text-ink-400">Time</div>
              <div className="text-xl font-bold text-ink-900">{lesson.estimated_minutes} min</div>
            </div>
          </div>
        </Card>
      )}

      {lesson.key_points.length > 0 && (
        <Card className="mt-4">
          <h2 className="text-sm font-semibold text-ink-900">
            The {lesson.key_points.length} facts to remember
          </h2>
          <ol className="mt-3 space-y-2 text-sm text-ink-800">
            {lesson.key_points.map((point, i) => (
              <li key={i} className="flex gap-3">
                <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-bold text-brand-700">
                  {i + 1}
                </span>
                <span>{point}</span>
              </li>
            ))}
          </ol>
        </Card>
      )}

      {card && (
        <div className="mt-6">
          <Progress value={index + 1} max={cards.length} label={`Card ${index + 1} of ${cards.length}`} />

          <Card className="mt-3 min-h-44">
            <span className="rounded-full bg-ink-100 px-2.5 py-1 text-xs font-semibold uppercase tracking-wide text-ink-600">
              {card.type}
            </span>
            {card.title && <h3 className="mt-3 font-semibold text-ink-900">{card.title}</h3>}
            <p className="mt-2 whitespace-pre-line text-ink-800">{card.body}</p>
          </Card>

          <div className="mt-4 flex items-center justify-between gap-3">
            <Button
              variant="secondary"
              disabled={index === 0}
              onClick={() => setIndex((value) => value - 1)}
            >
              Back
            </Button>

            {isLast ? (
              <Button loading={finish.isPending} onClick={() => finish.mutate(lesson.id)}>
                Check what stuck →
              </Button>
            ) : (
              <Button onClick={() => setIndex((value) => value + 1)}>Next</Button>
            )}
          </div>
        </div>
      )}

      {lesson.generated_by && (
        <p className="mt-6 text-center text-xs text-ink-400">
          Generated from indexed past questions by {lesson.generated_by}
        </p>
      )}
    </div>
  )
}
