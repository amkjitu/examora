import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'
import { api } from '@/lib/api'
import type { AnswerResult, QuizResult, QuizSession } from '@/lib/types'
import { Button, Card, ErrorNote, Progress, Spinner } from '@/components/ui'

export default function QuizPage() {
  const { sessionId = '' } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const [index, setIndex] = useState(0)
  const [selected, setSelected] = useState<number | null>(null)
  const [result, setResult] = useState<AnswerResult | null>(null)
  const [summary, setSummary] = useState<QuizResult | null>(null)
  const shownAt = useRef<number>(Date.now())

  const { data: session, isLoading, error } = useQuery({
    queryKey: ['quiz', sessionId],
    retry: false,
    queryFn: async () => (await api.get<{ data: QuizSession }>(`/v1/quizzes/${sessionId}`)).data,
  })

  const question = session?.questions[index]

  // Answer latency feeds the SM-2 quality score, so the clock restarts on each
  // question rather than running for the whole session.
  useEffect(() => {
    shownAt.current = Date.now()
  }, [index])

  const answer = useMutation({
    mutationFn: async (choice: number) =>
      (
        await api.post<{ data: AnswerResult }>(`/v1/quizzes/${sessionId}/answer`, {
          question_id: question!.id,
          selected_index: choice,
          time_ms: Date.now() - shownAt.current,
        })
      ).data,
    onSuccess: setResult,
  })

  const complete = useMutation({
    mutationFn: async () =>
      (await api.post<{ data: QuizResult }>(`/v1/quizzes/${sessionId}/complete`)).data,
    onSuccess: async (data) => {
      setSummary(data)
      await queryClient.invalidateQueries({ queryKey: ['dashboard'] })
    },
  })

  if (isLoading) return <Spinner label="Preparing questions" />
  if (error) return <div className="p-5"><ErrorNote>{(error as Error).message}</ErrorNote></div>
  if (!session) return null

  if (summary) return <ResultScreen summary={summary} onDone={() => navigate('/')} />

  if (!question) {
    return (
      <div className="mx-auto max-w-2xl px-5 py-10">
        <Card>
          <p className="text-sm text-ink-600">This session has no questions left.</p>
          <Button className="mt-4" onClick={() => navigate('/')}>
            Back to today
          </Button>
        </Card>
      </div>
    )
  }

  const isLast = index === session.questions.length - 1

  function next() {
    setResult(null)
    setSelected(null)

    if (isLast) complete.mutate()
    else setIndex((value) => value + 1)
  }

  return (
    <div className="mx-auto max-w-2xl px-5 py-8">
      <Progress
        value={index + 1}
        max={session.questions.length}
        label={`Question ${index + 1} of ${session.questions.length}`}
      />

      <Card className="mt-4">
        <h1 className="text-lg font-semibold text-ink-900">{question.stem}</h1>

        <div className="mt-4 space-y-2">
          {question.options.map((option, optionIndex) => {
            const chosen = selected === optionIndex
            const isCorrect = result && optionIndex === result.correct_index
            const isWrongChoice = result && chosen && !result.is_correct

            let tone = 'border-ink-200 bg-white hover:border-brand-400'
            if (isCorrect) tone = 'border-emerald-500 bg-emerald-50'
            else if (isWrongChoice) tone = 'border-red-400 bg-red-50'
            else if (chosen && !result) tone = 'border-brand-500 bg-brand-50'

            return (
              <button
                key={optionIndex}
                type="button"
                disabled={Boolean(result) || answer.isPending}
                onClick={() => {
                  setSelected(optionIndex)
                  answer.mutate(optionIndex)
                }}
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

        {result && (
          <div className="mt-5 rounded-xl border border-ink-100 bg-ink-50 p-4">
            <div
              className={`text-sm font-bold ${result.is_correct ? 'text-emerald-700' : 'text-red-700'}`}
            >
              {result.is_correct ? 'Correct' : 'Not quite'}
            </div>

            {result.explanation && (
              <p className="mt-1.5 text-sm text-ink-800">{result.explanation}</p>
            )}

            {/* These are absent under exam conditions. This screen never runs in
                strict mode, but the type is shared with the mock runner. */}
            {result.previously_examined && result.previously_examined.length > 0 && (
              <p className="mt-2 text-xs text-ink-400">
                Previously examined: {result.previously_examined.join(', ')}
              </p>
            )}

            {result.next_review_in_days != null && (
              <p className="mt-1 text-xs text-ink-400">
                {result.next_review_in_days > 0
                  ? `Scheduled again in ${result.next_review_in_days} day${result.next_review_in_days === 1 ? '' : 's'}.`
                  : 'Scheduled again shortly — this one needs another pass.'}
              </p>
            )}

            <Button className="mt-4" loading={complete.isPending} onClick={next}>
              {isLast ? 'Finish' : 'Next question'}
            </Button>
          </div>
        )}
      </Card>
    </div>
  )
}

function ResultScreen({ summary, onDone }: { summary: QuizResult; onDone: () => void }) {
  const accuracy = Math.round(summary.score)

  return (
    <div className="mx-auto max-w-md px-5 py-12 text-center">
      <div className="text-6xl font-bold tabular-nums text-brand-600">{accuracy}%</div>
      <p className="mt-2 text-sm text-ink-600">
        {summary.correct} of {summary.total} correct
      </p>

      <Card className="mt-6 text-left">
        <div className="grid grid-cols-3 gap-4 text-center">
          <div>
            <div className="text-xs uppercase tracking-wide text-ink-400">XP</div>
            <div className="text-xl font-bold tabular-nums">{summary.xp}</div>
          </div>
          <div>
            <div className="text-xs uppercase tracking-wide text-ink-400">Level</div>
            <div className="text-xl font-bold tabular-nums">{summary.level}</div>
          </div>
          <div>
            <div className="text-xs uppercase tracking-wide text-ink-400">Streak</div>
            <div className="text-xl font-bold tabular-nums">{summary.streak_days}</div>
          </div>
        </div>

        <div className="mt-4">
          <Progress
            value={summary.level_progress.into}
            max={summary.level_progress.needed}
            label={`Level ${summary.level + 1}`}
          />
        </div>
      </Card>

      <Button className="mt-6 w-full" onClick={onDone}>
        Back to today
      </Button>
    </div>
  )
}
