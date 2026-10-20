import { describe, expect, it } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import { Gate, Locked } from './Locked'
import { entitlements, premiumEntitlements, renderApp, stubFetch } from '@/test/harness'

/**
 * Locks are the paywall. Two ways they can fail, and both are silent:
 *
 *   opening when they should be shut — premium features given away;
 *   flashing open while entitlements load — a feature appears, then vanishes.
 *
 * The second is the one a manual test never catches, because a developer on a
 * fast connection never sees the gap.
 */

const withMe = (data: unknown) =>
  stubFetch([{ match: '/api/v1/v1/me', body: { data } }, { match: '/v1/me', body: { data } }])

describe('Gate', () => {
  it('hides a feature the tier does not include', async () => {
    withMe({ entitlements: entitlements() })

    renderApp(
      <Gate feature="mock_exam" title="Mock exams" description="A full timed paper.">
        <p>the exam itself</p>
      </Gate>,
    )

    expect(await screen.findByText('Mock exams')).toBeInTheDocument()
    expect(screen.queryByText('the exam itself')).not.toBeInTheDocument()
  })

  it('shows a feature the tier includes', async () => {
    withMe({ entitlements: premiumEntitlements() })

    renderApp(
      <Gate feature="mock_exam" title="Mock exams" description="A full timed paper.">
        <p>the exam itself</p>
      </Gate>,
    )

    expect(await screen.findByText('the exam itself')).toBeInTheDocument()
  })

  it('stays shut while entitlements are still loading', async () => {
    // A request that never resolves — the state a slow connection is in.
    globalThis.fetch = (() => new Promise(() => {})) as unknown as typeof fetch

    renderApp(
      <Gate feature="mock_exam" title="Mock exams" description="A full timed paper.">
        <p>the exam itself</p>
      </Gate>,
    )

    // Defaulting open here would flash a premium feature to a free user.
    expect(screen.queryByText('the exam itself')).not.toBeInTheDocument()
    expect(screen.getByText('Mock exams')).toBeInTheDocument()
  })

  it('stays shut when the profile request fails', async () => {
    stubFetch([{ match: '/v1/me', status: 500, body: { message: 'boom' } }])

    renderApp(
      <Gate feature="ai_tutor" title="AI tutor" description="Ask anything.">
        <p>the tutor</p>
      </Gate>,
    )

    await waitFor(() => expect(screen.getByText('AI tutor')).toBeInTheDocument())
    expect(screen.queryByText('the tutor')).not.toBeInTheDocument()
  })

  it('gates each feature independently', async () => {
    withMe({
      entitlements: entitlements({
        features: { ...entitlements().features, revision: true },
      }),
    })

    renderApp(
      <>
        <Gate feature="revision" title="Revision" description="Due items.">
          <p>revision is open</p>
        </Gate>
        <Gate feature="mock_exam" title="Mock exams" description="A timed paper.">
          <p>mocks are open</p>
        </Gate>
      </>,
    )

    expect(await screen.findByText('revision is open')).toBeInTheDocument()
    expect(screen.queryByText('mocks are open')).not.toBeInTheDocument()
  })
})

describe('Locked', () => {
  it('explains the feature and offers a way to buy it', async () => {
    withMe({ entitlements: entitlements() })

    renderApp(<Locked title="Likely repeat questions">Questions already reused verbatim.</Locked>)

    // Someone who cannot tell what they would be buying will not buy it.
    expect(await screen.findByText('Likely repeat questions')).toBeInTheDocument()
    expect(screen.getByText(/already reused verbatim/)).toBeInTheDocument()

    const link = screen.getByRole('link', { name: /See plans/ })
    expect(link).toHaveAttribute('href', '/settings/subscription')
  })

  it('tells the student which tier they are on', async () => {
    withMe({ entitlements: entitlements() })

    renderApp(<Locked title="Mock exams">A timed paper.</Locked>)

    expect(await screen.findByText(/You're on Free/)).toBeInTheDocument()
  })
})
