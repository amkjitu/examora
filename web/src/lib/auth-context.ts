import { createContext, useContext } from 'react'
import type { Me } from './types'

export type AuthContextValue = {
  me: Me | null
  isLoading: boolean
  isAuthenticated: boolean
  login: (email: string, password: string) => Promise<void>
  register: (name: string, email: string, password: string) => Promise<void>
  logout: () => void
}

export const AuthContext = createContext<AuthContextValue | null>(null)

/**
 * Lives apart from the provider so `auth.tsx` exports components only — mixing
 * component and non-component exports in one module breaks React Fast Refresh.
 */
export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)

  if (!context) throw new Error('useAuth must be used inside an AuthProvider.')

  return context
}
