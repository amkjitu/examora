import type { ButtonHTMLAttributes, ReactNode } from 'react'

export function Stars({ count, max = 5 }: { count: number; max?: number }) {
  return (
    <span
      className="tracking-tight text-gold-500"
      title={`${count} of ${max} — how likely this chapter is to be worth marks`}
      aria-label={`Probability ${count} out of ${max}`}
    >
      {'★'.repeat(count)}
      <span className="text-ink-200">{'★'.repeat(Math.max(0, max - count))}</span>
    </span>
  )
}

export function Card({ children, className = '' }: { children: ReactNode; className?: string }) {
  return (
    <div className={`rounded-2xl border border-ink-100 bg-white p-5 shadow-sm ${className}`}>
      {children}
    </div>
  )
}

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: 'primary' | 'secondary' | 'ghost'
  loading?: boolean
}

export function Button({
  variant = 'primary',
  loading = false,
  className = '',
  children,
  disabled,
  ...props
}: ButtonProps) {
  const styles = {
    primary: 'bg-brand-600 text-white hover:bg-brand-700 disabled:bg-ink-300',
    secondary: 'bg-ink-100 text-ink-800 hover:bg-ink-200 disabled:text-ink-400',
    ghost: 'text-ink-600 hover:bg-ink-100',
  }[variant]

  return (
    <button
      {...props}
      disabled={disabled || loading}
      className={`inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold transition disabled:cursor-not-allowed ${styles} ${className}`}
    >
      {loading && (
        <span
          className="size-4 animate-spin rounded-full border-2 border-current border-t-transparent"
          aria-hidden
        />
      )}
      {children}
    </button>
  )
}

export function Progress({ value, max = 100, label }: { value: number; max?: number; label?: string }) {
  const percent = Math.min(100, Math.round((value / Math.max(1, max)) * 100))

  return (
    <div>
      {label && (
        <div className="mb-1 flex justify-between text-xs font-medium text-ink-400">
          <span>{label}</span>
          <span>{percent}%</span>
        </div>
      )}
      <div
        className="h-2 overflow-hidden rounded-full bg-ink-100"
        role="progressbar"
        aria-valuenow={percent}
        aria-valuemin={0}
        aria-valuemax={100}
      >
        <div className="h-full rounded-full bg-brand-600 transition-all" style={{ width: `${percent}%` }} />
      </div>
    </div>
  )
}

export function Stat({ label, value, hint }: { label: string; value: ReactNode; hint?: string }) {
  return (
    <div>
      <div className="text-xs font-medium uppercase tracking-wide text-ink-400">{label}</div>
      <div className="mt-0.5 text-2xl font-bold text-ink-900">{value}</div>
      {hint && <div className="text-xs text-ink-400">{hint}</div>}
    </div>
  )
}

export function Spinner({ label = 'Loading' }: { label?: string }) {
  return (
    <div className="flex items-center justify-center gap-3 py-16 text-ink-400">
      <span className="size-5 animate-spin rounded-full border-2 border-ink-200 border-t-brand-600" aria-hidden />
      <span className="text-sm">{label}…</span>
    </div>
  )
}

export function ErrorNote({ children }: { children: ReactNode }) {
  return (
    <div role="alert" className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
      {children}
    </div>
  )
}
