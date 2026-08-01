import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '@/lib/auth-context'
import { Button, Card, ErrorNote } from '@/components/ui'

export default function LoginPage() {
  const { login, register } = useAuth()
  const navigate = useNavigate()

  const [mode, setMode] = useState<'login' | 'register'>('login')
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setBusy(true)

    try {
      if (mode === 'login') await login(email, password)
      else await register(name, email, password)

      navigate('/')
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'Something went wrong.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="mx-auto flex min-h-full max-w-md flex-col justify-center px-5 py-12">
      <div className="mb-8 text-center">
        <h1 className="text-3xl font-bold tracking-tight text-ink-900">Examora</h1>
        <p className="mt-2 text-sm text-ink-600">
          Learn only what the exam has actually asked.
        </p>
      </div>

      <Card>
        <div className="mb-5 flex rounded-xl bg-ink-100 p-1 text-sm font-medium">
          {(['login', 'register'] as const).map((value) => (
            <button
              key={value}
              type="button"
              onClick={() => {
                setMode(value)
                setError(null)
              }}
              className={`flex-1 rounded-lg px-3 py-2 transition ${
                mode === value ? 'bg-white text-ink-900 shadow-sm' : 'text-ink-600'
              }`}
            >
              {value === 'login' ? 'Sign in' : 'Create account'}
            </button>
          ))}
        </div>

        <form onSubmit={submit} className="space-y-4">
          {mode === 'register' && (
            <Field label="Name">
              <input
                required
                value={name}
                onChange={(event) => setName(event.target.value)}
                className={inputClass}
                autoComplete="name"
              />
            </Field>
          )}

          <Field label="Email">
            <input
              required
              type="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              className={inputClass}
              autoComplete="email"
            />
          </Field>

          <Field label="Password">
            <input
              required
              type="password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              className={inputClass}
              autoComplete={mode === 'login' ? 'current-password' : 'new-password'}
            />
          </Field>

          {error && <ErrorNote>{error}</ErrorNote>}

          <Button type="submit" loading={busy} className="w-full">
            {mode === 'login' ? 'Sign in' : 'Create account'}
          </Button>
        </form>
      </Card>

      <p className="mt-4 text-center text-xs text-ink-400">
        A seeded development install signs in with admin@admin.com / password.
      </p>
    </div>
  )
}

const inputClass =
  'w-full rounded-xl border border-ink-200 bg-white px-3.5 py-2.5 text-sm outline-none transition focus:border-brand-500 focus:ring-2 focus:ring-brand-100'

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="mb-1.5 block text-sm font-medium text-ink-600">{label}</span>
      {children}
    </label>
  )
}
