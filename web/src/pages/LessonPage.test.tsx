import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import LessonPage from './LessonPage'
import { renderApp, stubFetch } from '@/test/harness'
import { ApiError } from '@/lib/api'

/**
 * What a student sees when they hit a paywall.
 *
 * A 402 that renders as a blank screen or a raw error loses someone who was
 * willing to pay, so the quota case has to arrive as an explanation with a way
 * forward.
 */

const lesson = {
  id: 1,
  title: 'Liberation War of 1971',
  summary: 'Only what has been examined.',
  estimated_minutes: 8,
  key_points: ['Osmani was Commander-in-Chief.'],
  generated_by: 'mock:examora-mock-v1',
  topic: { id: 9, name: 'Liberation War of 1971', slug: 'lw', subject: 'Bangladesh Affairs', exam: 'BCS' },
  exam_intelligence: {
    questions_asked_previously: 11,
    probability_stars: 5,
    expected_questions: '0-2',
    years: null,
    last_examined: 2024,
  },
  cards: [{ id: 1, type: 'fact' as const, title: 'Exam point 1', body: 'Osmani led the forces.' }],
}

const render = (routes: Parameters<typeof stubFetch>[0]) => {
  const calls = stubFetch(routes)
  renderApp(<LessonPage />, { route: '/topics/9/lesson', path: '/topics/:topicId/lesson' })

  return calls
}

describe('the lesson paywall', () => {
  it('explains the quota and points at what is still available', async () => {
    render([
      {
        match: '/lesson',
        status: 402,
        body: {
          message: "You've used all 3 lessons on your Free plan for today.",
          code: 'lesson_quota_exceeded',
          quota: { limit: 3, used: 3, resets_at: '2026-01-02T00:00:00+00:00' },
        },
      },
    ])

    // Not a blank screen, and not a raw status code.
    expect(await screen.findByRole('alert')).toHaveTextContent(/free lessons/i)

    // Revision and practice keep working — say so, or people assume they don't.
    expect(screen.getByText(/Revision and practice are still open/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Back to today/i })).toBeInTheDocument()
  })

  it('shows a real error rather than the quota message for other failures', async () => {
    render([{ match: '/lesson', status: 500, body: { message: 'Something broke.' } }])

    expect(await screen.findByRole('alert')).toHaveTextContent('Something broke.')
    expect(screen.queryByText(/free lessons/i)).not.toBeInTheDocument()
  })

  it('renders the lesson when it is allowed through', async () => {
    render([{ match: '/lesson', body: { data: lesson } }])

    expect(await screen.findByText('Liberation War of 1971')).toBeInTheDocument()
    expect(screen.getByText(/Osmani was Commander-in-Chief/)).toBeInTheDocument()

    // The exam-intelligence strip is the reason to trust the lesson.
    expect(screen.getByText('11')).toBeInTheDocument()
  })
})

describe('ApiError', () => {
  it('recognises a quota response as a quota problem, not a server fault', () => {
    expect(new ApiError(402, 'nope').isQuotaExceeded).toBe(true)
    expect(new ApiError(500, 'nope').isQuotaExceeded).toBe(false)
    expect(new ApiError(403, 'nope').isQuotaExceeded).toBe(false)
  })
})
