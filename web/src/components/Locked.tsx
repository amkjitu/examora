import type { ReactNode } from 'react'
import { Card } from './ui'
import { useEntitlements } from '@/lib/entitlements'
import type { FeatureKey } from '@/lib/types'

/**
 * Shown in place of a premium feature, with what it is and where to get it.
 *
 * Deliberately explains the feature rather than just refusing. Someone who
 * cannot tell what they would be buying will not buy it.
 */
export function Locked({
  title,
  children,
  compact = false,
}: {
  title: string
  children: ReactNode
  compact?: boolean
}) {
  const { tierLabel, upgradeUrl } = useEntitlements()

  return (
    <Card className={`border-dashed bg-ink-50 ${compact ? 'p-4' : ''}`}>
      <div className="flex items-start gap-3">
        <span
          aria-hidden
          className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-ink-200 text-ink-600"
        >
          {/* padlock */}
          <svg viewBox="0 0 20 20" fill="currentColor" className="size-4">
            <path d="M10 1a4 4 0 0 0-4 4v2H5a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-1V5a4 4 0 0 0-4-4Zm-2 6V5a2 2 0 1 1 4 0v2H8Z" />
          </svg>
        </span>

        <div className="min-w-0 flex-1">
          <h3 className="font-semibold text-ink-900">{title}</h3>
          <div className="mt-1 text-sm text-ink-600">{children}</div>

          <div className="mt-3 flex flex-wrap items-center gap-3">
            <a
              href={upgradeUrl}
              className="inline-flex items-center rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-700"
            >
              See plans
            </a>
            <span className="text-xs text-ink-400">You're on {tierLabel}</span>
          </div>
        </div>
      </div>
    </Card>
  )
}

/** Renders children when entitled, and a lock otherwise. */
export function Gate({
  feature,
  title,
  description,
  children,
  compact,
}: {
  feature: FeatureKey
  title: string
  description: ReactNode
  children: ReactNode
  compact?: boolean
}) {
  const { can } = useEntitlements()

  if (!can(feature)) {
    return (
      <Locked title={title} compact={compact}>
        {description}
      </Locked>
    )
  }

  return <>{children}</>
}
