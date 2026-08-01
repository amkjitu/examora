<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Examora\Topic;
use App\Services\Ai\AiGateway;
use App\Services\Ai\Data\LlmRequest;
use App\Services\Ai\PromptContext;
use App\Services\Knowledge\Retriever;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TutorController extends Controller
{
    private const SYSTEM = <<<'TXT'
    You are an exam tutor, not an encyclopaedia.

    Answer at the level the exam tests, not the level a textbook would. Use one
    concrete analogy if it helps recall. Keep it under 120 words. Then point at
    the specific past question that tested this, if one is supplied.

    Never introduce facts absent from the supplied context.
    TXT;

    public function __construct(
        private readonly AiGateway $ai,
        private readonly Retriever $retriever,
    ) {}

    public function ask(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:500'],
            'topic_id' => ['nullable', 'integer', 'exists:topics,id'],
            'exam_id' => ['required', 'integer', 'exists:exams,id'],
        ]);

        $topic = isset($validated['topic_id']) ? Topic::find($validated['topic_id']) : null;

        $chunks = $this->retriever->search(
            $validated['question'],
            (int) $validated['exam_id'],
            $topic?->id,
            limit: 8,
        );

        $context = [
            'question' => $validated['question'],
            'topic' => $topic?->name,
            'examined_facts' => $chunks->map(fn ($c) => [
                'content' => $c->content,
                'year' => $c->meta['year'] ?? null,
            ])->values()->all(),
        ];

        $response = $this->ai->complete(
            LlmRequest::make('tutor.answer', $validated['question']."\n\n".PromptContext::block($context), self::SYSTEM)
                ->forUser($request->user()->id)
        );

        return response()->json(['data' => [
            'answer' => $response->content,
            'sources' => $chunks->map(fn ($c) => [
                'title' => $c->title,
                'year' => $c->meta['year'] ?? null,
            ])->values(),
            'model' => $response->provider.':'.$response->model,
        ]]);
    }
}
