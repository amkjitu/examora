import { describe, expect, it, vi } from 'vitest'
import { screen, waitFor, act } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import MockExamPage from './MockExamPage'
import { renderApp, stubFetch } from '@/test/harness'
import type { QuizSession } from '@/lib/types'

/**
 * The timer carries real correctness. A break here loses a student a paper, and
 * until now nothing but a human eye stood in the way of that.
 */

function session(overrides: Partial<QuizSession> = {}): QuizSession {
  return {
    id: 7,
    type: 'mock',
    mode: 'strict',
    exam_id: 1,
    topic_id: null,
    lesson_id: null,
    total: 2,
    completed_at: null,
    seconds_remaining: 120,
    duration_minutes: 2,
    negative_marking: 0.5,
    questions: [
      {
        id: 101,
        stem: 'Who was the Commander-in-Chief of the Mukti Bahini?',
        options: ['Osmani', 'Zia', 'Khaled', 'Shafiullah'],
        difficulty: 'medium',
        origin: 'past',
        topic_id: 9,
        answered: false,
        selected_index: null,
      },
      {
        id: 102,
        stem: 'How many sectors were there?',
        options: ['11', '9', '7', '13'],
        difficulty: 'medium',
        origin: 'past',
        topic_id: 9,
        answered: false,
        selected_index: null,
      },
    ],
    ...overrides,
  }
}

/**
 * Advances the countdown a second at a time.
 *
 * Each tick schedules the next only after React commits, so one large
 * `advanceTimersByTime` fires the first timeout and then finds nothing else
 * queued. Stepping lets the chain actually run.
 */
async function tick(seconds: number) {
  for (let i = 0; i < seconds; i++) {
    await act(async () => {
      vi.advanceTimersByTime(1000)
    })
  }
}

const render = (data: QuizSession, extraRoutes: Parameters<typeof stubFetch>[0] = []) => {
  const calls = stubFetch([
    { match: '/api/v1/quizzes/7', method: 'GET', body: { data } },
    ...extraRoutes,
  ])

  renderApp(<MockExamPage />, { route: '/mock/7', path: '/mock/:sessionId' })

  return calls
}

describe('mock exam timer', () => {
  it('shows the remaining time the server reported', async () => {
    render(session({ seconds_remaining: 125 }))

    expect(await screen.findByRole('timer')).toHaveTextContent('2:05')
  })

  it('counts down once per second', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })

    render(session({ seconds_remaining: 90 }))

    const timer = await screen.findByRole('timer')
    expect(timer).toHaveTextContent('1:30')

    await tick(3)

    expect(timer).toHaveTextContent('1:27')
  })

  it('auto-submits when the clock reaches zero', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })

    const calls = render(session({ seconds_remaining: 2 }), [
      { match: '/complete', method: 'POST', body: { data: {} } },
    ])

    await screen.findByRole('timer')
    await tick(3)

    // Nobody has to be watching for the paper to be submitted.
    await waitFor(() =>
      expect(calls.some((c) => c.url.includes('/complete') && c.method === 'POST')).toBe(true),
    )
  })

  it('submits only once even as the clock keeps ticking past zero', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })

    const calls = render(session({ seconds_remaining: 1 }), [
      { match: '/complete', method: 'POST', body: { data: {} } },
    ])

    await screen.findByRole('timer')
    await tick(10)

    await waitFor(() => expect(calls.filter((c) => c.url.includes('/complete')).length).toBe(1))
  })

  it('renders no timer at all for an untimed session', async () => {
    render(session({ seconds_remaining: null, type: 'practice', mode: 'practice' }))

    await screen.findByText(/Commander-in-Chief/)
    expect(screen.queryByRole('timer')).not.toBeInTheDocument()
  })
})

describe('strict mode', () => {
  it('never renders feedback the server did not send', async () => {
    const user = userEvent.setup()

    render(session(), [
      {
        match: '/answer',
        method: 'POST',
        // Exactly what a strict-mode server returns: no key, no explanation.
        body: { data: { recorded: true, mode: 'strict', seconds_remaining: 118 } },
      },
    ])

    await screen.findByText(/Commander-in-Chief/)
    await user.click(screen.getByRole('button', { name: /Osmani/ }))

    await waitFor(() => expect(screen.queryByText(/Correct/)).not.toBeInTheDocument())
    expect(screen.queryByText(/Not quite/)).not.toBeInTheDocument()
  })

  it('warns about negative marking so skipping is an informed choice', async () => {
    render(session({ negative_marking: 0.5 }))

    expect(await screen.findByText(/0.5 mark deducted per wrong answer/)).toBeInTheDocument()
    expect(screen.getByText(/Skipping costs nothing/)).toBeInTheDocument()
  })

  it('shows feedback in practice mode', async () => {
    const user = userEvent.setup()

    render(session({ mode: 'practice' }), [
      {
        match: '/answer',
        method: 'POST',
        body: {
          data: {
            recorded: true,
            mode: 'practice',
            is_correct: true,
            correct_index: 0,
            explanation: 'Osmani led the liberation forces.',
            previously_examined: [2019, 2024],
            next_review_in_days: 1,
            seconds_remaining: 118,
          },
        },
      },
    ])

    await screen.findByText(/Commander-in-Chief/)
    await user.click(screen.getByRole('button', { name: /Osmani/ }))

    expect(await screen.findByText('Correct')).toBeInTheDocument()
    expect(screen.getByText(/Osmani led the liberation forces/)).toBeInTheDocument()
    expect(screen.getByText(/2019, 2024/)).toBeInTheDocument()
  })
})

describe('resuming a paper', () => {
  it('restores answers given before the tab was closed', async () => {
    const resumed = session()
    resumed.questions[0].answered = true
    resumed.questions[0].selected_index = 2

    render(resumed)

    // Resumes at the first unanswered question, not back at the start.
    expect(await screen.findByText(/How many sectors/)).toBeInTheDocument()

    const sheet = screen.getByRole('button', { name: /Sheet/ })
    expect(sheet).toHaveTextContent('1/2')
  })

  it('trusts the server clock on resume, not a local guess', async () => {
    // The student closed the tab with two minutes left and came back to 20s.
    render(session({ seconds_remaining: 20 }))

    expect(await screen.findByRole('timer')).toHaveTextContent('0:20')
  })
})

describe('the answer sheet', () => {
  it('jumps to a question and marks flags', async () => {
    const user = userEvent.setup()
    render(session())

    await screen.findByText(/Commander-in-Chief/)
    await user.click(screen.getByRole('button', { name: /Sheet/ }))

    await user.click(screen.getByRole('button', { name: /Question 2/ }))
    expect(await screen.findByText(/How many sectors/)).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Flag' }))
    expect(screen.getByRole('button', { name: 'Flagged' })).toBeInTheDocument()
  })
})
