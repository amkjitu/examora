import { useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { api, getToken } from '@/lib/api'
import type { Exam, MyQuestions, QuizSession, SourceDocument } from '@/lib/types'
import { Button, Card, ErrorNote, Progress, Spinner } from '@/components/ui'

export default function DocumentsPage() {
  const queryClient = useQueryClient()
  const fileInput = useRef<HTMLInputElement>(null)

  const [file, setFile] = useState<File | null>(null)
  const [year, setYear] = useState('')
  const [examId, setExamId] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)

  const { data: exams } = useQuery({
    queryKey: ['exams'],
    queryFn: async () => (await api.get<{ data: Exam[] }>('/v1/exams')).data,
  })

  const { data: documents, isLoading } = useQuery({
    queryKey: ['documents'],
    queryFn: async () => (await api.get<{ data: SourceDocument[] }>('/v1/documents')).data,
    // Ingestion runs on a queue, so poll while anything is still in flight.
    refetchInterval: (query) =>
      (query.state.data ?? []).some((d) => d.status === 'pending' || d.status === 'processing')
        ? 4000
        : false,
  })

  const upload = useMutation({
    mutationFn: async () => {
      if (!file || !examId) throw new Error('Pick an exam and a PDF first.')

      const body = new FormData()
      body.append('file', file)
      body.append('exam_id', String(examId))
      if (year) body.append('year', year)

      // FormData must not carry a JSON content type, so this bypasses the
      // shared JSON client rather than complicating it.
      const response = await fetch('/api/v1/documents', {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${getToken()}` },
        body,
      })

      const payload = await response.json().catch(() => null)

      if (!response.ok) {
        throw new Error(payload?.message ?? `Upload failed (${response.status})`)
      }

      return payload.data as SourceDocument
    },
    onSuccess: async () => {
      setFile(null)
      setYear('')
      setError(null)
      if (fileInput.current) fileInput.current.value = ''
      await queryClient.invalidateQueries({ queryKey: ['documents'] })
    },
    onError: (cause) => setError((cause as Error).message),
  })

  return (
    <div className="mx-auto max-w-3xl px-5 py-8">
      <h1 className="text-2xl font-bold tracking-tight text-ink-900">Your documents</h1>
      <p className="mt-1 text-sm text-ink-600">
        Upload a question paper and Examora will read it, pull out the questions and classify them
        by topic.
      </p>

      <Card className="mt-4 bg-amber-50/70">
        <p className="text-sm text-ink-800">
          Your uploads stay private to you. They never join the shared question bank that drives
          topic rankings for other students — that only happens after a reviewer verifies the
          material.
        </p>
      </Card>

      <Card className="mt-4">
        <h2 className="font-semibold text-ink-900">Upload a PDF</h2>

        <div className="mt-3 grid gap-3 sm:grid-cols-3">
          <label className="block sm:col-span-2">
            <span className="mb-1.5 block text-sm font-medium text-ink-600">File</span>
            <input
              ref={fileInput}
              type="file"
              accept="application/pdf"
              onChange={(event) => setFile(event.target.files?.[0] ?? null)}
              className="w-full rounded-xl border border-ink-200 bg-white px-3 py-2 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-ink-100 file:px-3 file:py-1.5 file:text-sm file:font-medium"
            />
          </label>

          <label className="block">
            <span className="mb-1.5 block text-sm font-medium text-ink-600">Year (optional)</span>
            <input
              type="number"
              value={year}
              min={1950}
              max={2100}
              onChange={(event) => setYear(event.target.value)}
              placeholder="2023"
              className={inputClass}
            />
          </label>
        </div>

        <label className="mt-3 block">
          <span className="mb-1.5 block text-sm font-medium text-ink-600">Exam</span>
          <select
            value={examId ?? ''}
            onChange={(event) => setExamId(Number(event.target.value) || null)}
            className={inputClass}
          >
            <option value="">Choose an exam…</option>
            {exams?.map((exam) => (
              <option key={exam.id} value={exam.id}>
                {exam.name}
              </option>
            ))}
          </select>
        </label>

        {error && (
          <div className="mt-3">
            <ErrorNote>{error}</ErrorNote>
          </div>
        )}

        <Button
          className="mt-4"
          loading={upload.isPending}
          disabled={!file || !examId}
          onClick={() => upload.mutate()}
        >
          Upload and process
        </Button>
      </Card>

      <h2 className="mt-8 font-semibold text-ink-900">Uploaded</h2>

      {isLoading ? (
        <Spinner label="Loading documents" />
      ) : documents?.length === 0 ? (
        <Card className="mt-3">
          <p className="text-sm text-ink-600">Nothing uploaded yet.</p>
        </Card>
      ) : (
        <ul className="mt-3 space-y-3">
          {documents?.map((document) => (
            <li key={document.id}>
              <Card>
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <div className="truncate font-medium text-ink-900">{document.title}</div>
                    <div className="text-xs text-ink-400">
                      {document.original_filename}
                      {document.year && ` · ${document.year}`}
                      {` · ${(document.size_bytes / 1_048_576).toFixed(1)} MB`}
                    </div>
                  </div>

                  <StatusBadge status={document.status} />
                </div>

                {(document.status === 'processing' || document.status === 'pending') && (
                  <div className="mt-3">
                    <Progress
                      value={document.pages_processed}
                      max={Math.max(1, document.page_count)}
                      label={
                        document.page_count > 0
                          ? `Reading page ${document.pages_processed} of ${document.page_count}`
                          : 'Queued'
                      }
                    />
                  </div>
                )}

                {document.status === 'failed' && document.error && (
                  <div className="mt-3">
                    <ErrorNote>{document.error}</ErrorNote>
                  </div>
                )}

                {document.status === 'extracted' && (
                  <div className="mt-3 flex flex-wrap gap-2 text-xs">
                    <Pill>{document.extracted_count ?? 0} questions found</Pill>
                    {document.published_privately ? (
                      <Pill tone="emerald">{document.published_privately} ready to practise</Pill>
                    ) : null}
                    {document.duplicate_count ? (
                      <Pill>{document.duplicate_count} already in the bank</Pill>
                    ) : null}
                  </div>
                )}
              </Card>
            </li>
          ))}
        </ul>
      )}

      <MyQuestionsPanel />

      <p className="mt-6 text-xs text-ink-400">
        Your questions stay yours. Nothing reaches the shared question bank without a reviewer
        verifying it first.
      </p>
    </div>
  )
}

/**
 * What the uploads actually gave the student, and a way to practise it.
 * Without this the upload flow ends in a status badge and nothing else.
 */
function MyQuestionsPanel() {
  const navigate = useNavigate()

  const { data } = useQuery({
    queryKey: ['my-questions'],
    queryFn: async () => (await api.get<{ data: MyQuestions }>('/v1/my-questions')).data,
  })

  const practise = useMutation({
    mutationFn: async (topicId: number) =>
      (await api.post<{ data: QuizSession }>('/v1/quizzes', { type: 'practice', topic_id: topicId })).data,
    onSuccess: (session) => navigate(`/quiz/${session.id}`),
  })

  if (!data || data.total === 0) return null

  return (
    <>
      <h2 className="mt-8 font-semibold text-ink-900">From your uploads</h2>
      <p className="text-sm text-ink-600">
        {data.total} question{data.total === 1 ? '' : 's'} pulled out of your own material, private
        to you.
      </p>

      <ul className="mt-3 space-y-2">
        {data.by_topic.map((row) => (
          <li
            key={row.topic}
            className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-ink-100 bg-white p-4"
          >
            <div className="min-w-0">
              <div className="truncate font-medium text-ink-900">{row.topic}</div>
              {row.subject && <div className="text-xs text-ink-400">{row.subject}</div>}
            </div>

            <div className="flex items-center gap-3">
              <span className="text-sm tabular-nums text-ink-500">{row.count}</span>
              {row.topic_id && (
                <Button
                  variant="secondary"
                  loading={practise.isPending}
                  onClick={() => practise.mutate(row.topic_id!)}
                >
                  Practise
                </Button>
              )}
            </div>
          </li>
        ))}
      </ul>
    </>
  )
}

const inputClass =
  'w-full rounded-xl border border-ink-200 bg-white px-3.5 py-2.5 text-sm outline-none transition focus:border-brand-500 focus:ring-2 focus:ring-brand-100'

function Pill({ children, tone = 'gray' }: { children: React.ReactNode; tone?: 'gray' | 'emerald' }) {
  const styles = tone === 'emerald' ? 'bg-emerald-100 text-emerald-800' : 'bg-ink-100 text-ink-600'

  return <span className={`rounded-full px-2.5 py-1 font-medium ${styles}`}>{children}</span>
}

function StatusBadge({ status }: { status: SourceDocument['status'] }) {
  const tone = {
    pending: 'bg-ink-100 text-ink-600',
    processing: 'bg-brand-100 text-brand-700',
    extracted: 'bg-emerald-100 text-emerald-700',
    failed: 'bg-red-100 text-red-700',
  }[status]

  const label = {
    pending: 'Queued',
    processing: 'Reading',
    extracted: 'Done',
    failed: 'Failed',
  }[status]

  return <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${tone}`}>{label}</span>
}
