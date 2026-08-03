import type { ReactElement, ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { render, type RenderOptions } from '@testing-library/react'
import { setToken } from '@/lib/api'
import type { Entitlements, FeatureKey } from '@/lib/types'

/**
 * Test harness.
 *
 * Components under test talk to the API through `fetch`, so tests stub `fetch`
 * per route rather than mocking the modules. That keeps the assertions about
 * behaviour a student would see, not about which function was called.
 */

/** All features off by default — a test opts in to what it needs. */
export function entitlements(overrides: Partial<Entitlements> = {}): Entitlements {
  const allFeatures: FeatureKey[] = [
    'chapter_rankings',
    'lessons',
    'practice',
    'revision',
    'daily_plan',
    'readiness',
    'forecast_topics',
    'mock_exam',
    'ai_tutor',
    'repeat_candidates',
    'document_upload',
  ]

  return {
    tier: 'free',
    label: 'Free',
    is_premium: false,
    on_trial: false,
    upgrade_url: '/settings/subscription',
    features: Object.fromEntries(allFeatures.map((f) => [f, false])) as Record<FeatureKey, boolean>,
    limits: {
      lessons_per_day: { limit: 3, used: 0, remaining: 3, resets_at: '2026-01-02T00:00:00+00:00' },
      documents_per_month: { limit: 0, used: 0, remaining: 0, resets_at: '2026-02-01T00:00:00+00:00' },
    },
    ...overrides,
  }
}

export function premiumEntitlements(): Entitlements {
  const base = entitlements()

  return {
    ...base,
    tier: 'premium',
    label: 'Premium',
    is_premium: true,
    features: Object.fromEntries(
      Object.keys(base.features).map((f) => [f, true]),
    ) as Record<FeatureKey, boolean>,
    limits: {
      ...base.limits,
      lessons_per_day: { limit: null, used: 0, remaining: null, resets_at: base.limits.lessons_per_day.resets_at },
    },
  }
}

type RouteStub = {
  /** Substring matched against the request URL. */
  match: string
  status?: number
  body?: unknown
  method?: string
}

/**
 * Stubs `fetch` against a list of route matchers. Anything unmatched throws,
 * so a test can never silently pass because a call went nowhere.
 */
export function stubFetch(routes: RouteStub[]) {
  const calls: { url: string; method: string; body: unknown }[] = []

  globalThis.fetch = (async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = typeof input === 'string' ? input : input.toString()
    const method = (init?.method ?? 'GET').toUpperCase()

    calls.push({
      url,
      method,
      body: init?.body ? JSON.parse(init.body as string) : null,
    })

    const route = routes.find(
      (r) => url.includes(r.match) && (!r.method || r.method.toUpperCase() === method),
    )

    if (!route) {
      throw new Error(`No stub for ${method} ${url}`)
    }

    return {
      ok: (route.status ?? 200) < 400,
      status: route.status ?? 200,
      json: async () => route.body ?? {},
      headers: new Headers(),
    } as Response
  }) as typeof fetch

  return calls
}

/** Signed-in by default: most screens are behind auth. */
export function renderApp(
  ui: ReactElement,
  { route = '/', path, ...options }: { route?: string; path?: string } & RenderOptions = {},
) {
  setToken('test-token')

  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: 0, staleTime: 0 },
      mutations: { retry: false },
    },
  })

  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[route]}>
        {path ? <Routes><Route path={path} element={children} /></Routes> : children}
      </MemoryRouter>
    </QueryClientProvider>
  )

  return { queryClient, ...render(ui, { wrapper: Wrapper, ...options }) }
}
