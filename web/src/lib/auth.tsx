import { useCallback, useMemo, useState, type ReactNode } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, getToken, setToken } from './api'
import { AuthContext } from './auth-context'
import type { Me } from './types'

/** Wave's JWT endpoints return the token under `access_token`. */
type TokenResponse = { access_token?: string; token?: string }

export function AuthProvider({ children }: { children: ReactNode }) {
  const [token, setTokenState] = useState<string | null>(getToken())
  const queryClient = useQueryClient()

  const { data: me, isLoading } = useQuery({
    queryKey: ['me', token],
    enabled: Boolean(token),
    retry: false,
    queryFn: async () => (await api.get<{ data: Me }>('/v1/me')).data,
  })

  const persist = useCallback((response: TokenResponse) => {
    const value = response.access_token ?? response.token ?? null

    if (!value) throw new Error('The server did not return an access token.')

    setToken(value)
    setTokenState(value)
  }, [])

  const login = useCallback(
    async (email: string, password: string) => {
      persist(await api.post<TokenResponse>('/login', { email, password }))
    },
    [persist],
  )

  const register = useCallback(
    async (name: string, email: string, password: string) => {
      persist(
        await api.post<TokenResponse>('/register', {
          name,
          email,
          password,
          password_confirmation: password,
        }),
      )
    },
    [persist],
  )

  const logout = useCallback(() => {
    setToken(null)
    setTokenState(null)
    queryClient.clear()
  }, [queryClient])

  const value = useMemo(
    () => ({
      me: me ?? null,
      isLoading: Boolean(token) && isLoading,
      isAuthenticated: Boolean(token),
      login,
      register,
      logout,
    }),
    [me, token, isLoading, login, register, logout],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
