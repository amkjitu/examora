<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSourceDocument;
use App\Models\Examora\ExtractedQuestion;
use App\Models\Examora\Question;
use App\Models\Examora\SourceDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $documents = SourceDocument::query()
            ->where('user_id', $request->user()->id)
            ->withCount([
                'extractedQuestions',
                'extractedQuestions as pending_count' => fn ($q) => $q->where('status', ExtractedQuestion::STATUS_PENDING),
                'extractedQuestions as duplicate_count' => fn ($q) => $q->where('status', ExtractedQuestion::STATUS_DUPLICATE),
            ])
            ->latest()
            ->limit(50)
            ->get();

        return response()->json(['data' => $documents->map(fn (SourceDocument $d) => $this->present($d))]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:51200'], // 50 MB
            'exam_id' => ['required', 'integer', 'exists:exams,id'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'title' => ['nullable', 'string', 'max:191'],
            'doc_type' => ['nullable', 'in:question_paper,book,notes'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'session_label' => ['nullable', 'string', 'max:191'],
        ]);

        $user = $request->user();
        $file = $validated['file'];
        $checksum = hash_file('sha256', $file->getRealPath());

        // Re-uploading the same file returns the original rather than paying to
        // transcribe it twice.
        $existing = SourceDocument::where('user_id', $user->id)->where('checksum', $checksum)->first();

        if ($existing) {
            return response()->json([
                'data' => $this->present($existing),
                'message' => 'You have already uploaded this file.',
            ], 200);
        }

        $path = $file->store('documents/'.$user->id);

        $document = SourceDocument::create([
            'user_id' => $user->id,
            'exam_id' => $validated['exam_id'],
            'subject_id' => $validated['subject_id'] ?? null,
            'title' => $validated['title'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'original_filename' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getClientMimeType() ?: 'application/pdf',
            'size_bytes' => $file->getSize(),
            'doc_type' => $validated['doc_type'] ?? 'question_paper',
            'year' => $validated['year'] ?? null,
            'session_label' => $validated['session_label'] ?? null,
            'checksum' => $checksum,
            // Uploads are private by default regardless of who sent them.
            // Promotion to `shared` is an admin decision made in the review
            // queue, never something an upload request can ask for.
            'visibility' => SourceDocument::VISIBILITY_PRIVATE,
            'status' => SourceDocument::STATUS_PENDING,
        ]);

        ProcessSourceDocument::dispatch($document->id);

        return response()->json(['data' => $this->present($document)], 201);
    }

    public function show(Request $request, SourceDocument $document): JsonResponse
    {
        $this->authorizeDocument($request, $document);

        $document->loadCount('extractedQuestions');

        return response()->json(['data' => $this->present($document) + [
            'questions' => $document->extractedQuestions()
                ->with('topic:id,name')
                ->orderBy('page_number')
                ->limit(200)
                ->get()
                ->map(fn (ExtractedQuestion $q) => [
                    'id' => $q->id,
                    'page_number' => $q->page_number,
                    'stem' => $q->stem,
                    'options' => $q->options,
                    'correct_index' => $q->correct_index,
                    'explanation' => $q->explanation,
                    'status' => $q->status,
                    'confidence' => $q->confidence,
                    'topic_confidence' => $q->topic_confidence,
                    'needs_attention' => $q->needsAttention(),
                    'topic' => $q->topic ? ['id' => $q->topic->id, 'name' => $q->topic->name] : null,
                ]),
        ]]);
    }

    public function destroy(Request $request, SourceDocument $document): JsonResponse
    {
        $this->authorizeDocument($request, $document);

        // Approved questions already live in past_questions and are not undone
        // by deleting the upload; the file and its staging rows go.
        Storage::delete($document->path);
        $document->delete();

        return response()->json(null, 204);
    }

    /**
     * The questions a student's own uploads gave them, ready to practise.
     *
     * Scoped to the caller by ownership, not by a filter the caller passes —
     * there is no request shape that returns someone else's private questions.
     */
    public function myQuestions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'topic_id' => ['nullable', 'integer', 'exists:topics,id'],
            'document_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();

        $questions = Question::ownedBy($user->id)
            ->active()
            ->with('topic:id,name,subject_id', 'topic.subject:id,name')
            ->when($validated['topic_id'] ?? null, fn ($q, $id) => $q->where('topic_id', $id))
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => [
            'total' => $questions->count(),
            'by_topic' => $questions
                ->groupBy(fn (Question $q) => $q->topic?->name ?? 'Unclassified')
                ->map(fn ($group, $topic) => [
                    'topic' => $topic,
                    'topic_id' => $group->first()->topic_id,
                    'subject' => $group->first()->topic?->subject?->name,
                    'count' => $group->count(),
                ])
                ->sortByDesc('count')
                ->values(),
            // Never the answer key: these feed practice, and a student who can
            // read the answers first is not practising.
            'questions' => $questions->map(fn (Question $q) => $q->toStudentArray() + [
                'topic' => $q->topic?->name,
                'source_document_id' => $q->meta['source_document_id'] ?? null,
            ]),
        ]]);
    }

    private function present(SourceDocument $document): array
    {
        return [
            'id' => $document->id,
            'title' => $document->title,
            'original_filename' => $document->original_filename,
            'doc_type' => $document->doc_type,
            'visibility' => $document->visibility,
            'status' => $document->status,
            'year' => $document->year,
            'session_label' => $document->session_label,
            'page_count' => $document->page_count,
            'pages_processed' => $document->pages_processed,
            'progress_percent' => $document->progressPercent(),
            'size_bytes' => $document->size_bytes,
            'error' => $document->error,
            'extracted_count' => $document->extracted_questions_count ?? null,
            'published_privately' => $document->meta['questions_published_privately'] ?? null,
            'pending_count' => $document->pending_count ?? null,
            'duplicate_count' => $document->duplicate_count ?? null,
            'processed_at' => $document->processed_at?->toIso8601String(),
            'created_at' => $document->created_at?->toIso8601String(),
        ];
    }

    private function authorizeDocument(Request $request, SourceDocument $document): void
    {
        abort_unless($document->user_id === $request->user()->id, 403, 'This document belongs to another user.');
    }
}
