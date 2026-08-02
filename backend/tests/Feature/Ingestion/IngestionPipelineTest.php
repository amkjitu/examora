<?php

use App\Jobs\ProcessSourceDocument;
use App\Models\Examora\DocumentPage;
use App\Models\Examora\ExtractedQuestion;
use App\Models\Examora\PastQuestion;
use App\Models\Examora\Question;
use App\Models\Examora\SourceDocument;
use App\Models\User;
use App\Services\Ingestion\DocumentReviewer;
use App\Services\Ingestion\QuestionExtractor;
use App\Services\Learning\QuizEngine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Examora;
use Tymon\JWTAuth\Facades\JWTAuth;
use Wave\Plan;
use Wave\Subscription;

/**
 * A page of exam text in the layout real papers use: numbered questions,
 * lettered options, a printed answer key.
 */
function paperPage(): string
{
    return <<<'TXT'
    44th BCS Preliminary — General Knowledge

    1. Who was the Commander-in-Chief of the Mukti Bahini?
    A) Colonel M. A. G. Osmani B) Major Ziaur Rahman C) Khaled Mosharraf D) K. M. Shafiullah
    Answer: A
    Explanation: General M. A. G. Osmani led the liberation forces.

    2. How many sectors was Bangladesh divided into during the Liberation War?
    A) 9 B) 11 C) 7 D) 13
    Answer: B

    3. Which river is known as the Padma inside Bangladesh?
    A) The Ganges B) The Brahmaputra C) The Meghna D) The Teesta
    TXT;
}

beforeEach(function () {
    $this->exam = Examora::exam(['name' => 'BCS Preliminary', 'slug' => 'bcs-preliminary']);
    $this->topic = Examora::populatedTopic($this->exam);
    $this->owner = Examora::user();
    $this->admin = Examora::user(['name' => 'Reviewer']);
});

/** Builds a document whose page text is already transcribed. */
function documentWithPage(string $visibility, ?string $text = null): SourceDocument
{
    $document = SourceDocument::create([
        'user_id' => test()->owner->id,
        'exam_id' => test()->exam->id,
        'title' => '44th BCS Preliminary',
        'original_filename' => 'bcs-44.pdf',
        'path' => 'documents/test/bcs-44.pdf',
        'mime' => 'application/pdf',
        'size_bytes' => 1024,
        'page_count' => 1,
        'visibility' => $visibility,
        'doc_type' => 'question_paper',
        'status' => SourceDocument::STATUS_EXTRACTED,
        'year' => 2023,
        'session_label' => '44th BCS Preliminary (2023)',
        'checksum' => hash('sha256', $visibility.microtime()),
    ]);

    DocumentPage::create([
        'source_document_id' => $document->id,
        'page_number' => 1,
        'text' => $text ?? paperPage(),
        'extraction_method' => DocumentPage::METHOD_TEXT_LAYER,
    ]);

    return $document->fresh(['exam']);
}

it('extracts questions from page text and stages them for review', function () {
    $document = documentWithPage(SourceDocument::VISIBILITY_SHARED);

    $staged = app(QuestionExtractor::class)->extract($document);

    expect($staged)->toBe(3);

    $questions = ExtractedQuestion::where('source_document_id', $document->id)->get();

    $osmani = $questions->firstWhere('stem', 'Who was the Commander-in-Chief of the Mukti Bahini?');

    expect($osmani)->not->toBeNull()
        ->and($osmani->options)->toHaveCount(4)
        ->and($osmani->options[0])->toBe('Colonel M. A. G. Osmani')
        ->and($osmani->correct_index)->toBe(0)
        ->and($osmani->status)->toBe(ExtractedQuestion::STATUS_PENDING)
        ->and($osmani->page_number)->toBe(1);

    // The third question prints no answer, so no key is invented for it — a
    // guessed key is worse than none.
    $padma = $questions->firstWhere('stem', 'Which river is known as the Padma inside Bangladesh?');
    expect($padma->correct_index)->toBeNull()
        ->and($padma->needsAttention())->toBeTrue();
});

it('never writes into past_questions by itself', function () {
    $document = documentWithPage(SourceDocument::VISIBILITY_SHARED);
    $before = PastQuestion::count();

    app(QuestionExtractor::class)->extract($document);

    expect(PastQuestion::count())->toBe($before);
});

it('flags a question that already exists as a duplicate instead of restaging it', function () {
    // The fixture topic already contains this exact question.
    $document = documentWithPage(SourceDocument::VISIBILITY_SHARED, <<<'TXT'
    1. Who was the Commander-in-Chief?
    A) Osmani B) Zia C) Khaled D) Shafiullah
    Answer: A
    TXT);

    app(QuestionExtractor::class)->extract($document);

    $extracted = ExtractedQuestion::where('source_document_id', $document->id)->first();

    expect($extracted->status)->toBe(ExtractedQuestion::STATUS_DUPLICATE)
        ->and($extracted->duplicate_of_id)->not->toBeNull()
        // A duplicate inherits the known topic rather than being re-guessed.
        ->and($extracted->topic_id)->toBe($this->topic->id);
});

it('does not stage the same question twice when reprocessed', function () {
    $document = documentWithPage(SourceDocument::VISIBILITY_SHARED);

    app(QuestionExtractor::class)->extract($document);
    $second = app(QuestionExtractor::class)->extract($document);

    expect($second)->toBe(0)
        ->and(ExtractedQuestion::where('source_document_id', $document->id)->count())->toBe(3);
});

it('refuses to promote a question from a private upload', function () {
    $document = documentWithPage(SourceDocument::VISIBILITY_PRIVATE);

    app(QuestionExtractor::class)->extract($document);

    $extracted = ExtractedQuestion::where('source_document_id', $document->id)
        ->whereNotNull('correct_index')
        ->first();

    expect(fn () => app(DocumentReviewer::class)->approve($extracted, $this->admin, [
        'topic_id' => $this->topic->id,
        'year' => 2023,
    ]))->toThrow(RuntimeException::class, 'private upload');

    expect($extracted->fresh()->status)->toBe(ExtractedQuestion::STATUS_PENDING);
});

it('publishes a private upload to its owner only, without touching the shared corpus', function () {
    $document = documentWithPage(SourceDocument::VISIBILITY_PRIVATE);

    app(QuestionExtractor::class)->extract($document);

    $extracted = ExtractedQuestion::where('source_document_id', $document->id)
        ->whereNotNull('correct_index')
        ->first();

    $before = PastQuestion::count();
    $question = app(DocumentReviewer::class)->publishPrivately($extracted);

    expect($question)->not->toBeNull()
        ->and($question->owner_id)->toBe($this->owner->id)
        ->and($question->isPrivate())->toBeTrue()
        ->and(PastQuestion::count())->toBe($before);
});

/*
 * Regression: a student's uploaded questions used to be marked private only in
 * `meta`, which nothing enforced — so they appeared in every other student's
 * practice pool. Ownership is now a column with a global scope behind it.
 */
it('keeps one student uploaded questions out of another student practice pool', function () {
    $document = documentWithPage(SourceDocument::VISIBILITY_PRIVATE);
    app(QuestionExtractor::class)->extract($document);

    $private = app(DocumentReviewer::class)->publishPrivately(
        ExtractedQuestion::where('source_document_id', $document->id)
            ->whereNotNull('correct_index')
            ->first()
    );

    $engine = app(QuizEngine::class);
    $stranger = Examora::user();

    $ownerPool = $engine->selectForTopic($this->owner, $private->topic_id, 50);
    $strangerPool = $engine->selectForTopic($stranger, $private->topic_id, 50);

    expect($ownerPool->pluck('id'))->toContain($private->id)
        ->and($strangerPool->pluck('id'))->not->toContain($private->id);
});

it('keeps private questions out of another student mock exam', function () {
    // The shared bank the stranger's paper is legitimately built from.
    foreach (PastQuestion::where('exam_id', $this->exam->id)->get() as $past) {
        Question::withAllOwners()->create([
            'exam_id' => $past->exam_id,
            'owner_id' => null,
            'topic_id' => $past->topic_id,
            'origin' => Question::ORIGIN_PAST,
            'past_question_id' => $past->id,
            'stem' => $past->stem,
            'options' => $past->options,
            'correct_index' => $past->correct_index,
            'difficulty' => $past->difficulty,
            'is_active' => true,
        ]);
    }

    $document = documentWithPage(SourceDocument::VISIBILITY_PRIVATE);
    app(QuestionExtractor::class)->extract($document);

    $private = app(DocumentReviewer::class)->publishPrivately(
        ExtractedQuestion::where('source_document_id', $document->id)
            ->whereNotNull('correct_index')
            ->first()
    );

    $stranger = Examora::user();
    $mock = app(QuizEngine::class)
        ->startMock($stranger, $this->exam->id, 50);

    // A real paper was built, and the private question is not in it.
    expect($mock->question_ids)->not->toBeEmpty()
        ->and($mock->question_ids)->not->toContain($private->id);

    // The owner's own paper does include it.
    $ownerMock = app(QuizEngine::class)
        ->startMock($this->owner, $this->exam->id, 50);

    expect($ownerMock->question_ids)->toContain($private->id);
});

it('hides private questions from an unauthenticated read by default', function () {
    $document = documentWithPage(SourceDocument::VISIBILITY_PRIVATE);
    app(QuestionExtractor::class)->extract($document);

    $private = app(DocumentReviewer::class)->publishPrivately(
        ExtractedQuestion::where('source_document_id', $document->id)
            ->whereNotNull('correct_index')
            ->first()
    );

    // No authenticated user — a queue job or console command. The global scope
    // must fail closed, not open.
    expect(Question::find($private->id))->toBeNull()
        ->and(Question::withAllOwners()->find($private->id))->not->toBeNull();
});

it('promotes an approved question into the shared corpus', function () {
    $document = documentWithPage(SourceDocument::VISIBILITY_SHARED);

    app(QuestionExtractor::class)->extract($document);

    $extracted = ExtractedQuestion::where('source_document_id', $document->id)
        ->where('stem', 'How many sectors was Bangladesh divided into during the Liberation War?')
        ->first();

    $past = app(DocumentReviewer::class)->approve($extracted, $this->admin, [
        'topic_id' => $this->topic->id,
        'year' => 2023,
    ]);

    expect($past->exam_id)->toBe($this->exam->id)
        ->and($past->topic_id)->toBe($this->topic->id)
        ->and($past->options[$past->correct_index])->toBe('11')
        ->and($past->source)->toBe("document:{$document->id}");

    $extracted->refresh();

    expect($extracted->status)->toBe(ExtractedQuestion::STATUS_APPROVED)
        ->and($extracted->past_question_id)->toBe($past->id)
        ->and($extracted->reviewed_by)->toBe($this->admin->id);
});

it('will not approve without an answer key, a topic or a year', function () {
    $document = documentWithPage(SourceDocument::VISIBILITY_SHARED);

    app(QuestionExtractor::class)->extract($document);

    $noKey = ExtractedQuestion::where('source_document_id', $document->id)
        ->whereNull('correct_index')
        ->first();

    expect(fn () => app(DocumentReviewer::class)->approve($noKey, $this->admin, [
        'topic_id' => $this->topic->id,
        'year' => 2023,
    ]))->toThrow(RuntimeException::class, 'correct answer');

    $withKey = ExtractedQuestion::where('source_document_id', $document->id)
        ->whereNotNull('correct_index')
        ->first();

    $withKey->update(['topic_id' => null]);

    expect(fn () => app(DocumentReviewer::class)->approve($withKey->fresh(), $this->admin, ['year' => 2023]))
        ->toThrow(RuntimeException::class, 'topic');
});

it('lets a reviewer correct the key on the way through', function () {
    $document = documentWithPage(SourceDocument::VISIBILITY_SHARED);

    app(QuestionExtractor::class)->extract($document);

    $extracted = ExtractedQuestion::where('source_document_id', $document->id)
        ->whereNull('correct_index')
        ->first();

    $past = app(DocumentReviewer::class)->approve($extracted, $this->admin, [
        'correct_index' => 0,
        'topic_id' => $this->topic->id,
        'year' => 2023,
        'explanation' => 'The Ganges is called the Padma inside Bangladesh.',
    ]);

    expect($past->correct_index)->toBe(0)
        ->and($past->explanation)->toContain('Padma');
});

it('records the same question from two papers as two occurrences of one fact', function () {
    // The same question printed in two different sessions — the repetition that
    // the importance scorer treats as its strongest signal.
    $page = <<<'TXT'
    1. How many sectors was Bangladesh divided into during the Liberation War?
    A) 9 B) 11 C) 7 D) 13
    Answer: B
    TXT;

    $reviewer = app(DocumentReviewer::class);
    $extractor = app(QuestionExtractor::class);

    $paper2023 = documentWithPage(SourceDocument::VISIBILITY_SHARED, $page);
    $extractor->extract($paper2023);

    $paper2019 = documentWithPage(SourceDocument::VISIBILITY_SHARED, $page);
    $paper2019->update(['year' => 2019, 'session_label' => '41st BCS Preliminary (2019)']);
    $extractor->extract($paper2019);

    $first = $reviewer->approve(
        ExtractedQuestion::where('source_document_id', $paper2023->id)->first(),
        $this->admin,
        ['topic_id' => $this->topic->id, 'year' => 2023],
    );

    $second = $reviewer->approve(
        ExtractedQuestion::where('source_document_id', $paper2019->id)->first(),
        $this->admin,
        ['topic_id' => $this->topic->id, 'year' => 2019],
    );

    // Two rows, one fingerprint: one fact, examined twice.
    expect($second->id)->not->toBe($first->id)
        ->and($second->fingerprint)->toBe($first->fingerprint)
        ->and($second->year)->toBe(2019);
});

it('will not approve the same staged row twice', function () {
    $document = documentWithPage(SourceDocument::VISIBILITY_SHARED);
    app(QuestionExtractor::class)->extract($document);

    $extracted = ExtractedQuestion::where('source_document_id', $document->id)
        ->whereNotNull('correct_index')
        ->first();

    $reviewer = app(DocumentReviewer::class);
    $reviewer->approve($extracted, $this->admin, ['topic_id' => $this->topic->id, 'year' => 2023]);

    expect(fn () => $reviewer->approve($extracted->fresh(), $this->admin, [
        'topic_id' => $this->topic->id,
        'year' => 2019,
    ]))->toThrow(RuntimeException::class, 'already been reviewed');
});

it('rejects a question with a reviewer note', function () {
    $document = documentWithPage(SourceDocument::VISIBILITY_SHARED);
    app(QuestionExtractor::class)->extract($document);

    $extracted = ExtractedQuestion::where('source_document_id', $document->id)->first();

    app(DocumentReviewer::class)->reject($extracted, $this->admin, 'Garbled transcription.');

    $extracted->refresh();

    expect($extracted->status)->toBe(ExtractedQuestion::STATUS_REJECTED)
        ->and($extracted->review_note)->toBe('Garbled transcription.')
        ->and(fn () => app(DocumentReviewer::class)->approve($extracted, $this->admin, [
            'topic_id' => $this->topic->id,
            'year' => 2023,
        ]))->toThrow(RuntimeException::class, 'already been reviewed');
});

/**
 * Uploading is premium, so an upload test needs a subscribed uploader.
 * Wave keys subscriptions on billable_id/billable_type and caches subscriber()
 * for five minutes, hence the flush.
 */
function subscribeUploader(User $user): void
{
    Subscription::create([
        'billable_id' => $user->id,
        'billable_type' => 'user',
        'plan_id' => Plan::first()?->id ?? 1,
        'vendor_transaction_id' => 'test_'.uniqid(),
        'vendor_customer_id' => 'cus_test',
        'vendor_user_id' => (string) $user->id,
        'vendor_slug' => 'stripe',
        'status' => 'active',
        'plan' => 'Premium',
        'cycle' => 'month',
    ]);

    Cache::flush();
}

it('accepts an upload, quarantines it, and queues it for processing', function () {
    Storage::fake('local');
    Queue::fake();

    subscribeUploader($this->owner);
    $token = JWTAuth::fromUser($this->owner->fresh());

    $response = $this->post('/api/v1/documents', [
        'file' => UploadedFile::fake()->create('bcs-44.pdf', 200, 'application/pdf'),
        'exam_id' => $this->exam->id,
        'year' => 2023,
    ], ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])->assertCreated();

    // Uploads are private regardless of who sends them.
    expect($response->json('data.visibility'))->toBe('private')
        ->and($response->json('data.status'))->toBe('pending');

    Queue::assertPushed(ProcessSourceDocument::class);
});

it('recognises a re-uploaded file instead of paying to read it twice', function () {
    Storage::fake('local');
    Queue::fake();

    subscribeUploader($this->owner);
    $token = JWTAuth::fromUser($this->owner->fresh());
    $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];

    $file = UploadedFile::fake()->createWithContent('bcs-44.pdf', 'identical bytes');

    $first = $this->post('/api/v1/documents', [
        'file' => $file,
        'exam_id' => $this->exam->id,
    ], $headers)->assertCreated();

    $second = $this->post('/api/v1/documents', [
        'file' => UploadedFile::fake()->createWithContent('bcs-44.pdf', 'identical bytes'),
        'exam_id' => $this->exam->id,
    ], $headers)->assertOk();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(SourceDocument::where('user_id', $this->owner->id)->count())->toBe(1);

    Queue::assertPushed(ProcessSourceDocument::class, 1);
});

it('keeps one student out of another student uploads', function () {
    $document = documentWithPage(SourceDocument::VISIBILITY_PRIVATE);
    $intruder = Examora::user();

    $this->getJson("/api/v1/documents/{$document->id}", [
        'Authorization' => 'Bearer '.JWTAuth::fromUser($intruder),
    ])->assertForbidden();
});
