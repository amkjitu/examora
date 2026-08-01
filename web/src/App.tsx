import { BrowserRouter, Link, Navigate, Route, Routes, useLocation } from 'react-router-dom'
import type { ReactNode } from 'react'
import { AuthProvider } from '@/lib/auth'
import { useAuth } from '@/lib/auth-context'
import { Spinner } from '@/components/ui'
import LoginPage from '@/pages/LoginPage'
import ExamPickerPage from '@/pages/ExamPickerPage'
import DashboardPage from '@/pages/DashboardPage'
import TopicsPage from '@/pages/TopicsPage'
import LessonPage from '@/pages/LessonPage'
import QuizPage from '@/pages/QuizPage'
import MockSetupPage from '@/pages/MockSetupPage'
import MockExamPage from '@/pages/MockExamPage'
import MockAnalysisPage from '@/pages/MockAnalysisPage'
import DocumentsPage from '@/pages/DocumentsPage'
import PredictionsPage from '@/pages/PredictionsPage'

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Routes>
          <Route path="/login" element={<LoginPage />} />

          <Route
            path="/*"
            element={
              <RequireAuth>
                <Shell />
              </RequireAuth>
            }
          />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  )
}

function RequireAuth({ children }: { children: ReactNode }) {
  const { isAuthenticated, isLoading } = useAuth()
  const location = useLocation()

  if (!isAuthenticated) return <Navigate to="/login" state={{ from: location }} replace />
  if (isLoading) return <Spinner label="Signing you in" />

  return <>{children}</>
}

function Shell() {
  const { me, logout } = useAuth()

  // A student with no chosen exam has nothing to do: everything downstream is
  // scoped to one target, so send them to the picker rather than an empty home.
  const needsEnrollment = me && !me.active_enrollment

  return (
    <div className="min-h-full">
      <header className="sticky top-0 z-10 border-b border-ink-100 bg-white/90 backdrop-blur">
        <div className="mx-auto flex max-w-4xl items-center justify-between gap-4 px-5 py-3">
          <Link to="/" className="text-lg font-bold tracking-tight text-ink-900">
            Examora
          </Link>

          <nav className="flex items-center gap-1 text-sm">
            {me?.active_enrollment && (
              <>
                <Link
                  to={`/exams/${me.active_enrollment.exam.slug}/topics`}
                  className="rounded-lg px-3 py-1.5 font-medium text-ink-600 hover:bg-ink-100"
                >
                  Chapters
                </Link>
                <Link
                  to="/predictions"
                  className="rounded-lg px-3 py-1.5 font-medium text-ink-600 hover:bg-ink-100"
                >
                  Forecast
                </Link>
                <Link
                  to="/mock"
                  className="rounded-lg px-3 py-1.5 font-medium text-ink-600 hover:bg-ink-100"
                >
                  Mock
                </Link>
                <Link
                  to="/documents"
                  className="rounded-lg px-3 py-1.5 font-medium text-ink-600 hover:bg-ink-100"
                >
                  Upload
                </Link>
              </>
            )}
            <Link to="/exams" className="rounded-lg px-3 py-1.5 font-medium text-ink-600 hover:bg-ink-100">
              Exams
            </Link>
            <button
              type="button"
              onClick={logout}
              className="rounded-lg px-3 py-1.5 font-medium text-ink-400 hover:bg-ink-100"
            >
              Sign out
            </button>
          </nav>
        </div>

        {me && !me.subscription.is_premium && me.quota.remaining !== null && (
          <div className="bg-brand-50 px-5 py-1.5 text-center text-xs text-brand-700">
            Free plan · {me.quota.remaining} of {me.quota.free_lessons_per_day} lessons left today
          </div>
        )}
      </header>

      <main>
        <Routes>
          <Route
            path="/"
            element={needsEnrollment ? <Navigate to="/exams" replace /> : <DashboardPage />}
          />
          <Route path="/exams" element={<ExamPickerPage />} />
          <Route path="/exams/:slug/topics" element={<TopicsPage />} />
          <Route path="/topics/:topicId/lesson" element={<LessonPage />} />
          <Route path="/quiz/:sessionId" element={<QuizPage />} />
          <Route path="/mock" element={<MockSetupPage />} />
          <Route path="/mock/:sessionId" element={<MockExamPage />} />
          <Route path="/mock/:sessionId/analysis" element={<MockAnalysisPage />} />
          <Route path="/documents" element={<DocumentsPage />} />
          <Route path="/predictions" element={<PredictionsPage />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </main>
    </div>
  )
}
