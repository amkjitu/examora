import { useQuery } from '@tanstack/react-query'
import { api } from './api'
import type { Entitlements, FeatureKey, Me } from './types'

/**
 * What the signed-in user is allowed to do.
 *
 * Read this before rendering a premium surface, not after a request fails. A
 * feature that looks available and then errors is how a paywall loses someone
 * who was willing to pay; a padlock with a price is an invitation.
 */
export function useEntitlements() {
  const { data, isLoading } = useQuery({
    queryKey: ['me'],
    queryFn: async () => (await api.get<{ data: Me }>('/v1/me')).data,
  })

  const entitlements: Entitlements | null = data?.entitlements ?? null

  return {
    entitlements,
    isLoading,
    /** Defaults to locked while loading, so nothing flashes as available. */
    can: (feature: FeatureKey) => entitlements?.features[feature] ?? false,
    isPremium: entitlements?.is_premium ?? false,
    tierLabel: entitlements?.label ?? 'Free',
    upgradeUrl: entitlements?.upgrade_url ?? '/settings/subscription',
    lessonsRemaining: entitlements?.limits.lessons_per_day.remaining ?? null,
    lessonLimit: entitlements?.limits.lessons_per_day.limit ?? null,
  }
}
