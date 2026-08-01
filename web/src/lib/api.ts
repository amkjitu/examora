const BASE_URL = import.meta.env.VITE_API_URL ?? ''

const TOKEN_KEY = 'examora.token'

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token: string | null): void {
  if (token) localStorage.setItem(TOKEN_KEY, token)
  else localStorage.removeItem(TOKEN_KEY)
}

export class ApiError extends Error {
  // Declared as fields rather than constructor parameter properties: the build
  // runs with `erasableSyntaxOnly`, which forbids the shorthand.
  status: number
  body: unknown

  constructor(status: number, message: string, body: unknown = null) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.body = body
  }

  /** 402 is the free-tier lesson allowance, not a payment failure. */
  get isQuotaExceeded(): boolean {
    return this.status === 402
  }
}

type RequestOptions = {
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE'
  body?: unknown
  signal?: AbortSignal
}

export async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const token = getToken()

  const response = await fetch(`${BASE_URL}/api${path}`, {
    method: options.method ?? 'GET',
    signal: options.signal,
    headers: {
      Accept: 'application/json',
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: options.body ? JSON.stringify(options.body) : undefined,
  })

  if (response.status === 204) return undefined as T

  const payload = await response.json().catch(() => null)

  if (!response.ok) {
    // An expired or revoked token should drop the session rather than leave the
    // app in a half-authenticated state.
    if (response.status === 401) setToken(null)

    throw new ApiError(
      response.status,
      (payload as { message?: string })?.message ?? `Request failed (${response.status})`,
      payload,
    )
  }

  return payload as T
}

export const api = {
  get: <T,>(path: string, signal?: AbortSignal) => request<T>(path, { signal }),
  post: <T,>(path: string, body?: unknown) => request<T>(path, { method: 'POST', body }),
}
