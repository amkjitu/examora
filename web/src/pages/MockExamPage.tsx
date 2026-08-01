import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'
import { api } from '@/lib/api'
import type { AnswerResult, QuizSession } from '@/lib/types'
import { Button, Card, ErrorNote, Spinner } from '@/components/ui'

export default function MockExamPage() {
  const { sessionId = '' } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const [index, setIndex] = useState(0)
  const [answers, setAnswers] = useState<Record<number, number>>({})
  const [flagged, setFlagged] = useState<Set<number>>(new Set())
  const [feedback, setFeedback] = useState<AnswerResult | null>(null)
  const [seconds, setSeconds] = useState<number | null>(null)
  const [showGrid, setShowGrid] = useState(false)
  const shownAt = useRef(Date.now())
  const submitted = useRef(false)

  const { data: session, isLoading, error } = useQuery({
    queryKey: ['mock', sessionId],
    retry: false,
    queryFn: async () => (await api.get<{ data: QuizSession }>(`/v1/quizzes/${sessionId}`)).data,
  })

  const complete = useMutation({
    mutationFn: async () => api.post(`/v1/quizzes/${sessionId}/complete`),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      navigate(`/mock/${sessionId}/analysis`, { replace: true })
    },
  })

  const submit = useCallback(() => {
    if (submitted.current) return

    submitted.current = true
    complete.mutate()
  }, [complete])

  // Seed local state from the server, including any answers given before the
  // tab was closed — a resumed paper picks up exactly where it was left.
  useEffect(() => {
    if (!session) return

    setSeconds(session.seconds_remaining)

    const restored: Record<number, number> = {}
    for (const question of session.questions) {
      if (question.selected_index !== null) restored[question.id] = question.selected_index
    }
    setAnswers(restored)

    const firstUnanswered = session.questions.findIndex((q) => q.selected_index === null)
    setIndex(firstUnanswered === -1 ? 0 : firstUnanswered)

    if (session.completed_at) navigate(`/mock/${sessionId}/analysis`, { replace: true })
  }, [session, navigate, sessionId])

  // Local countdown for display only. The server rejects late answers regardless
  // of what this shows, so a tampered clock buys nothing.
  useEffect(() => {
    if (seconds === null) return

    if (seconds <= 0) {
      submit()

      return
    }

    const timer = setTimeout(() => setSeconds((value) => (value === null ? null : value - 1)), 1000)

    return () => clearTimeout(timer)
  }, [seconds, submit])

  useEffect(() => {
    shownAt.current = Date.now()
  }, [index])

  const answer = useMutation({
    mutationFn: async ({ questionId, choice }: { questionId: number; choice: number }) =>
      (
        await api.post<{ data: AnswerResult }>(`/v1/quizzes/${sessionId}/answer`, {
          question_id: questionId,
          selected_index: choice,
          time_ms: Date.now() - shownAt.current,
        })
      ).data,
    onSuccess: (result) => {
      // Keep the timer honest with the server on every round trip.
      if (result.seconds_remaining !== null) setSeconds(result.seconds_remaining)
      if (result.mode === 'practice') setFeedback(result)
    },
  })

  const question = session?.questions[index]

  const answeredCount = useMemo(() => Object.keys(answers).length, [answers])

  if (isLoading) return <Spinner label="Setting up your paper" />
  if (error) return <div className="p-5"><ErrorNote>{(error as Error).message}</ErrorNote></div>
  if (!session || !question) return null

  const isStrict = session.mode === 'strict'
  const isLast = index === session.questions.length - 1

  function choose(choice: number) {
    setAnswers((current) => ({ ...current, [question!.id]: choice }))
    answer.mutate({ questionId: question!.id, choice })

    // Under exam conditions there is nothing to read, so move on immediately.
    if (isStrict && !isLast) setTimeout(() => setIndex((value) => value + 1), 120)
  }

  function toggleFlag() {
    setFlagged((current) => {
      const next = new Set(current)

      if (next.has(question!.id)) next.delete(question!.id)
      else next.add(question!.id)

      return next
    })
  }

  function goNext() {
    setFeedback(null)
    if (!isLast) setIndex((value) => value + 1)
  }

  return (
    <div className="mx-auto max-w-3xl px-5 py-6">
      <header className="sticky top-14 z-10 -mx-5 mb-4 border-b border-ink-100 bg-ink-50/95 px-5 py-3 backdrop-blur">
        <div className="flex items-center justify-between gap-4">
          <div className="text-sm font-medium text-ink-600">
            Question <span className="tabular-nums text-ink-900">{index + 1}</span> of{' '}
            <span className="tabular-nums">{session.questions.length}</span>
          </div>

          <Clock seconds={seconds} />

          <div className="flex items-center gap-2">
            <Button variant="secondary" onClick={() => setShowGrid((value) => !value)}>
              Sheet · {answeredCount}/{session.questions.length}
            </Button>
            <Button variant="primary" loading={complete.isPending} onClick={submit}>
              Submit
            </Button>
          </div>
        </div>

        {showGrid && (
          <AnswerSheet
            questions={session.questions}
            answers={answers}
            flagged={flagged}
            current={index}
            onJump={(target) => {
              setIndex(target)
              setFeedback(null)
              setShowGrid(false)
            }}
          />
        )}
      </header>

      {isStrict && session.negative_marking > 0 && (
        <p className="mb-3 text-xs text-ink-400">
          {session.negative_marking} mark deducted per wrong answer. Skipping costs nothing — a
          guess you are unsure of is worse than a blank.
        </p>
      )}

      <Card>
        <div className="flex items-start justify-between gap-4">
          <h1 className="text-lg font-semibold text-ink-900">{question.stem}</h1>
          <button
            type="button"
            onClick={toggleFlag}
            aria-pressed={flagged.has(question.id)}
            className={`shrink-0 rounded-lg px-2.5 py-1 text-xs font-semibold transition ${
              flagged.has(question.id)
                ? 'bg-amber-100 text-amber-700'
                : 'bg-ink-100 text-ink-600 hover:bg-ink-200'
            }`}
          >
            {flagged.has(question.id) ? 'Flagged' : 'Flag'}
          </button>
        </div>

        <div className="mt-4 space-y-2">
          {question.options.map((option, optionIndex) => {
            const chosen = answers[question.id] === optionIndex

            let tone = 'border-ink-200 bg-white hover:border-brand-400'

            if (feedback && feedback.correct_index === optionIndex) {
              tone = 'border-emerald-500 bg-emerald-50'
            } else if (feedback && chosen && !feedback.is_correct) {
              tone = 'border-red-400 bg-red-50'
            } else if (chosen) {
              tone = 'border-brand-500 bg-brand-50'
            }

            return (
              <button
                key={optionIndex}
                type="button"
                disabled={Boolean(feedback)}
                onClick={() => choose(optionIndex)}
                className={`flex w-full items-start gap-3 rounded-xl border p-3.5 text-left text-sm transition disabled:cursor-default ${tone}`}
              >
                <span className="mt-px flex size-6 shrink-0 items-center justify-center rounded-lg bg-ink-100 text-xs font-bold text-ink-600">
                  {String.fromCharCode(65 + optionIndex)}
                </span>
                <span className="text-ink-800">{option}</span>
              </button>
            )
          })}
        </div>

        {feedback && feedback.mode === 'practice' && (
          <div className="mt-5 rounded-xl border border-ink-100 bg-ink-50 p-4">
            <div
              className={`text-sm font-bold ${feedback.is_correct ? 'text-emerald-700' : 'text-red-700'}`}
            >
              {feedback.is_correct ? 'Correct' : 'Not quite'}
            </div>
            {feedback.explanation && (
              <p className="mt-1.5 text-sm text-ink-800">{feedback.explanation}</p>
            )}
            {feedback.previously_examined && feedback.previously_examined.length > 0 && (
              <p className="mt-2 text-xs text-ink-400">
                Previously examined: {feedback.previously_examined.join(', ')}
              </p>
            )}
          </div>
        )}
      </Card>

      <div className="mt-4 flex items-center justify-between gap-3">
        <Button
          variant="secondary"
          disabled={index === 0}
          onClick={() => {
            setFeedback(null)
            setIndex((value) => value - 1)
          }}
        >
          Previous
        </Button>

        {isLast ? (
          <Button loading={complete.isPending} onClick={submit}>
            Submit paper
          </Button>
        ) : (
          <Button variant={feedback ? 'primary' : 'secondary'} onClick={goNext}>
            {answers[question.id] === undefined ? 'Skip' : 'Next'}
          </Button>
        )}
      </div>
    </div>
  )
}

function Clock({ seconds }: { seconds: number | null }) {
  if (seconds === null) return null

  const minutes = Math.floor(seconds / 60)
  const rest = seconds % 60
  const critical = seconds <= 60
  const low = seconds <= 300

  return (
    <div
      role="timer"
      aria-live={critical ? 'assertive' : 'off'}
      className={`rounded-lg px-3 py-1.5 text-lg font-bold tabular-nums ${
        critical ? 'bg-red-100 text-red-700' : low ? 'bg-amber-100 text-amber-700' : 'text-ink-900'
      }`}
    >
      {minutes}:{String(rest).padStart(2, '0')}
    </div>
  )
}

function AnswerSheet({
  questions,
  answers,
  flagged,
  current,
  onJump,
}: {
  questions: QuizSession['questions']
  answers: Record<number, number>
  flagged: Set<number>
  current: number
  onJump: (index: number) => void
}) {
  return (
    <div className="mt-3 grid grid-cols-8 gap-1.5 sm:grid-cols-12">
      {questions.map((question, index) => {
        const answered = answers[question.id] !== undefined
        const isFlagged = flagged.has(question.id)

        let tone = 'bg-white text-ink-400 ring-1 ring-ink-200'
        if (answered) tone = 'bg-brand-600 text-white'
        if (isFlagged) tone = 'bg-amber-400 text-amber-950'
        if (index === current) tone += ' ring-2 ring-ink-900'

        return (
          <button
            key={question.id}
            type="button"
            onClick={() => onJump(index)}
            aria-label={`Question ${index + 1}${answered ? ', answered' : ''}${isFlagged ? ', flagged' : ''}`}
            className={`rounded-md py-1.5 text-xs font-semibold tabular-nums transition ${tone}`}
          >
            {index + 1}
          </button>
        )
      })}
    </div>
  )
}
