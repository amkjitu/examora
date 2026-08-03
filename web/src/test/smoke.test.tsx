import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { renderApp, entitlements, premiumEntitlements } from './harness'

describe('test harness', () => {
  it('renders a component inside the app providers', () => {
    renderApp(<p>hello</p>)
    expect(screen.getByText('hello')).toBeInTheDocument()
  })

  it('builds entitlement fixtures with everything off by default', () => {
    expect(entitlements().features.mock_exam).toBe(false)
    expect(premiumEntitlements().features.mock_exam).toBe(true)
    expect(premiumEntitlements().limits.lessons_per_day.limit).toBeNull()
  })
})
