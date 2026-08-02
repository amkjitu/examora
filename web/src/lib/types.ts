export type Exam = {
  id: number
  code: string
  name: string
  slug: string
  authority: string | null
  description: string | null
  total_marks: number
  duration_minutes: number
  negative_marking: number
  past_questions_count: number
  subjects: { id: number; name: string; slug: string; marks: number }[]
}

export type TopicSummary = {
  id: number
  name: string
  slug: string
  description: string | null
  subject: { name: string; slug: string }
  question_count: number
  probability_stars: number
  importance_score: number
  estimated_minutes: number
  expected_questions: string | null
  last_examined: number | null
  mastery: number
  lesson_completed: boolean
  /** Null until a forecast has been computed. */
  forecast: {
    appearance_probability: number
    confidence: number
    trend: 'rising' | 'steady' | 'falling'
    is_overdue: boolean
  } | null
}

export type Predictions = {
  exam: { id: number; name: string; slug: string }
  target_year: number
  model_version: string
  computed_at: string | null
  disclaimer: string
  freshness: {
    last_session_in_bank: number | null
    forecasting: number | null
    sessions_behind: number | null
    is_current: boolean
  }
  topics: {
    rank: number
    topic: { id: number; name: string; slug: string; subject: string | null }
    appearance_probability: number
    expected_questions: number
    expected_range: [number, number]
    confidence: number
    confidence_label: 'high' | 'moderate' | 'low' | 'very low'
    trend: 'rising' | 'steady' | 'falling'
    is_overdue: boolean
    evidence: string[]
    signals: Record<string, unknown>
    mastery: number
  }[]
  repeat_candidates_locked: boolean
  repeat_candidates: {
    past_question_id: number
    stem: string
    answer: string | null
    explanation: string | null
    topic: { id: number; name: string; subject: string | null } | null
    years: number[]
    appearances: number
    last_seen: number
    mean_gap_years: number
    score: number
    evidence: string[]
  }[]
}

export type LessonCard = {
  id: number
  type: 'fact' | 'mnemonic' | 'timeline' | 'comparison' | 'pitfall'
  title: string | null
  body: string
}

export type Lesson = {
  id: number
  title: string
  summary: string | null
  estimated_minutes: number
  key_points: string[]
  generated_by: string | null
  topic: { id: number; name: string; slug: string; subject: string; exam: string }
  exam_intelligence: {
    questions_asked_previously: number
    probability_stars: number
    expected_questions: string
    years: Record<string, number> | null
    last_examined: number | null
  } | null
  cards: LessonCard[]
}

export type QuizQuestion = {
  id: number
  stem: string
  options: string[]
  difficulty: string
  origin: string
  topic_id: number | null
  answered: boolean
  selected_index: number | null
}

export type ExamMode = 'strict' | 'practice'

export type QuizSession = {
  id: number
  type: 'lesson_check' | 'practice' | 'revision' | 'mock'
  mode: ExamMode
  exam_id: number
  topic_id: number | null
  lesson_id: number | null
  total: number
  completed_at: string | null
  /** Authoritative: recomputed server-side on every load. Null when untimed. */
  seconds_remaining: number | null
  duration_minutes: number | null
  negative_marking: number
  questions: QuizQuestion[]
}

/**
 * In strict mode the server returns only `recorded` — no key, no explanation.
 * Everything past `mode` is therefore optional.
 */
export type AnswerResult = {
  recorded: boolean
  mode: ExamMode
  seconds_remaining: number | null
  is_correct?: boolean
  correct_index?: number
  explanation?: string | null
  previously_examined?: number[]
  next_review_in_days?: number | null
}

export type LevelProgress = { into: number; needed: number }

export type QuizResult = {
  id: number
  type: string
  total: number
  correct: number
  wrong: number | null
  skipped: number | null
  marks: number | null
  max_marks: number | null
  penalty_marks: number | null
  score: number
  duration_seconds: number | null
  xp: number
  level: number
  streak_days: number
  level_progress: LevelProgress
}

export type MockAnalysis = {
  summary: {
    mode: ExamMode
    total: number
    attempted: number
    skipped: number
    correct: number
    wrong: number
    marks: number
    max_marks: number
    penalty_marks: number
    negative_marking: number
    score_percent: number
    duration_seconds: number | null
    allowed_seconds: number | null
    accuracy_on_attempted: number
  }
  subjects: {
    subject: string
    exam_marks: number | null
    total: number
    attempted: number
    correct: number
    wrong: number
    skipped: number
    marks: number
    accuracy: number
    score_percent: number
  }[]
  review: {
    position: number
    question_id: number
    stem: string
    options: string[]
    correct_index: number
    selected_index: number | null
    is_correct: boolean
    skipped: boolean
    explanation: string | null
    difficulty: string
    origin: string
    previously_examined: number[]
    subject: string | null
    topic: { id: number; name: string } | null
    time_ms: number | null
    cohort_p_value: number | null
  }[]
  pacing: {
    median_ms: number | null
    mean_ms: number | null
    budget_ms: number | null
    time_sinks: { question_id: number; time_ms: number; stem: string; topic: string | null }[]
    rushed_mistakes: { question_id: number; time_ms: number; stem: string; topic: string | null }[]
  }
  readiness: {
    before: number
    after: number
    delta: number
    estimated_score_before: number
    estimated_score_after: number
    exam_total_marks: number | null
    fix_next: {
      topic: { id: number | null; name: string | null; slug: string | null }
      accuracy: number
      mastery: number
    }[]
  }
}

export type PlanItem = {
  id: number
  type: 'lesson' | 'practice' | 'revision' | 'mock'
  status: 'pending' | 'completed' | 'skipped'
  estimated_minutes: number
  reason: string | null
  topic: { id: number; name: string; slug: string } | null
  lesson_id: number | null
  payload: Record<string, unknown> | null
}

export type Dashboard = {
  exam: { id: number; name: string; slug: string; total_marks: number }
  target: { session: string | null; date: string | null; days_left: number | null }
  today: { target_minutes: number; completed_minutes: number; items: PlanItem[] }
  gamification: {
    xp: number
    level: number
    coins: number
    streak_days: number
    longest_streak: number
    level_progress: LevelProgress
  }
  readiness: {
    readiness: number
    estimated_score: number
    covered_topics: number
    total_topics: number
  }
  revision_due: number
  weakest_topics: {
    topic: { id: number | null; name: string | null; slug: string | null }
    accuracy: number
    mastery: number
  }[]
}

export type SourceDocument = {
  id: number
  title: string
  original_filename: string
  doc_type: 'question_paper' | 'book' | 'notes'
  visibility: 'shared' | 'private'
  status: 'pending' | 'processing' | 'extracted' | 'failed'
  year: number | null
  session_label: string | null
  page_count: number
  pages_processed: number
  progress_percent: number
  size_bytes: number
  error: string | null
  extracted_count: number | null
  published_privately: number | null
  pending_count: number | null
  duplicate_count: number | null
  processed_at: string | null
  created_at: string | null
}

export type MyQuestions = {
  total: number
  by_topic: {
    topic: string
    topic_id: number | null
    subject: string | null
    count: number
  }[]
  questions: (QuizQuestion & { topic: string | null; source_document_id: number | null })[]
}

export type ExtractedQuestionPreview = {
  id: number
  page_number: number | null
  stem: string
  options: string[]
  correct_index: number | null
  explanation: string | null
  status: 'pending' | 'approved' | 'rejected' | 'duplicate'
  confidence: number | null
  topic_confidence: number | null
  needs_attention: boolean
  topic: { id: number; name: string } | null
}

export type FeatureKey =
  | 'chapter_rankings'
  | 'lessons'
  | 'practice'
  | 'revision'
  | 'daily_plan'
  | 'readiness'
  | 'forecast_topics'
  | 'mock_exam'
  | 'ai_tutor'
  | 'repeat_candidates'
  | 'document_upload'

export type Limit = {
  /** Null means unlimited. */
  limit: number | null
  used: number
  remaining: number | null
  resets_at: string
}

export type Entitlements = {
  tier: string
  label: string
  is_premium: boolean
  on_trial: boolean
  upgrade_url: string
  features: Record<FeatureKey, boolean>
  limits: {
    lessons_per_day: Limit
    documents_per_month: Limit
  }
}

export type Me = {
  id: number
  name: string
  email: string
  username: string
  avatar: string | null
  subscription: { is_premium: boolean; on_trial: boolean; plan: string | null }
  entitlements: Entitlements
  /** Superseded by `entitlements.limits`; kept for older clients. */
  quota: { free_lessons_per_day: number | null; used_today: number; remaining: number | null }
  active_enrollment: {
    exam: { id: number; name: string; slug: string }
    target_session: string | null
    days_left: number | null
    daily_minutes_goal: number
  } | null
}
