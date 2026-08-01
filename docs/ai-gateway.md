# AI gateway

`App\Services\Ai\AiGateway` is the only door to any LLM. Nothing in the
application layer names a provider.

Three responsibilities, in order of importance to the business:

1. **No vendor is a single point of failure** — task routing with fallback.
2. **Never pay twice for the same prompt** — content-addressed cache.
3. **Always know what was spent, on what, by whom** — the `ai_requests` ledger.

---

## Task routing

Providers are chosen per *task*, not globally, because the tasks have genuinely
different shapes (`config/ai.php`):

| Task | Preference order | Why |
|---|---|---|
| `lesson.generate` | anthropic → openai → gemini | Long context: reads many past questions at once |
| `question.generate` | gemini → openai → anthropic | High volume, cost-sensitive |
| `tutor.answer` | gemini → openai → anthropic | A student is waiting; latency wins |
| `document.parse` | anthropic → openai → gemini | Structured extraction from papers |
| `topic.classify` | gemini → openai | Cheap, high volume |
| `embed` | openai → gemini | Anthropic ships no embedding endpoint |

`providerChain()` walks the list, **silently skipping any provider without an API
key**, and always ends at a usable fallback. So a deployment with only a Gemini
key routes everything to Gemini; a deployment with no keys at all routes
everything to `mock` and still works.

On a call failure the gateway logs, moves to the next provider, and marks the
`ai_requests` row `fallback` — so a vendor degrading shows up in the ledger
before it shows up in support tickets.

---

## Caching

Cache key is a SHA-256 of `(task, system prompt, messages, max tokens,
temperature, schema)` — the prompt is the identity, not the provider. A cache hit
is recorded in the ledger with `cost_usd = 0` and `cache_hit = true`, so the
saving is measurable rather than assumed.

This matters because lesson generation is shared content: the first student to
open a chapter pays the latency, everyone after gets it instantly and free.
Default TTL 7 days (`AI_CACHE_TTL`); `0` disables.

---

## Cost accounting

Every call writes to `ai_requests`: task, provider, model, prompt/completion
tokens, computed USD cost, latency, status, cache hit, prompt hash.

Without this you cannot price subscription tiers honestly. Per-1M-token rates
live in `config/ai.php` alongside each provider.

```sql
-- What does a lesson actually cost to produce?
select provider, model,
       count(*) as calls,
       sum(cost_usd) as spend,
       avg(latency_ms)::int as p50_latency,
       sum(cache_hit::int) as cache_hits
from ai_requests
where task = 'lesson.generate'
group by provider, model;
```

Accounting failures are caught and logged, never propagated — a ledger write must
not take down a student's lesson.

---

## Structured output

Each provider has a different mechanism, normalised behind
`LlmRequest::withSchema()`:

| Provider | Mechanism |
|---|---|
| OpenAI | `response_format: json_schema` |
| Gemini | `responseSchema` (keywords filtered — Gemini rejects several JSON Schema keys OpenAI accepts, and wants uppercase types) |
| Anthropic | A single forced tool call; the tool input *is* the structured result |
| Mock | Builds a schema-shaped object from the prompt context |

`LlmResponse::json()` still defends against reality: models wrap JSON in prose
and code fences no matter how firmly you ask them not to, so it strips fences,
finds the outermost balanced object, and throws a useful error rather than
returning `null`.

---

## The mock provider

`MockProvider` is not a stub that returns lorem ipsum. It is how the whole
product stays runnable and testable at zero cost.

Every generator embeds its retrieved, database-sourced context into the prompt as
a delimited JSON block:

```php
$prompt = "Write a micro-lesson for the chapter below.\n\n"
        . PromptContext::block([
            'exam' => 'BCS Preliminary',
            'topic' => 'Liberation War of 1971',
            'statistics' => [...],
            'facts' => [...],   // real rows from past_questions
        ]);
```

A real model reads that block as ordinary grounding data. The mock parses it and
assembles its answer from the same real facts. One prompt, both paths — so the
offline path exercises the identical code, and the output is a genuine lesson
about the Liberation War rather than placeholder text.

**Mock embeddings** use the hashing trick over word tokens, L2-normalised.
Unlike random vectors these carry real lexical similarity, so retrieval ranking
behaves sensibly offline — there is a test asserting that two Liberation War
passages score closer to each other than either does to a passage about
photosynthesis.

---

## Adding a provider

1. Implement `LlmProvider` (extend `AbstractProvider` for HTTP, cost and token
   estimation helpers).
2. Register it in `AiGateway::DRIVERS`.
3. Add config under `ai.providers.*` with per-1M-token costs.
4. Add it to the preference list of whichever tasks it suits.

No application code changes.
