<x-filament-panels::page>
    @php
        $clusters = $this->getClusters();
    @endphp

    <div class="space-y-4">
        <div class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
            <p class="font-semibold">Nothing on this page has been merged.</p>
            <p class="mt-1">
                These are questions whose embeddings sit above {{ $this->getThreshold() }} cosine similarity
                within the same topic — likely the same question reworded between sittings. They already count
                as repeats in the forecast when their answers agree. The exact fingerprint remains the source
                of truth for the question bank, because a wrong merge would unify two different answer keys
                silently.
            </p>
        </div>

        @if (count($this->getExams()) > 1)
            <select
                wire:model.live="examId"
                class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"
            >
                @foreach ($this->getExams() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        @endif

        @forelse ($clusters as $cluster)
            <div @class([
                'rounded-xl border p-4',
                'border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-950/30' => ! $cluster['answers_agree'],
                'border-gray-200 dark:border-gray-700' => $cluster['answers_agree'],
            ])>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-medium">{{ $cluster['canonical_stem'] }}</p>
                        <p class="mt-0.5 text-xs text-gray-500">
                            {{ $cluster['topic']['name'] ?? 'Unclassified' }}
                            · {{ $cluster['distinct_years'] }} sittings
                            · {{ $cluster['distinct_fingerprints'] }} distinct wordings
                        </p>
                    </div>

                    <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold tabular-nums dark:bg-gray-800">
                        {{ number_format($cluster['mean_similarity'] * 100, 1) }}% similar
                    </span>
                </div>

                @unless ($cluster['answers_agree'])
                    <p class="mt-2 text-sm font-semibold text-red-700 dark:text-red-300">
                        The answers disagree ({{ implode(' / ', $cluster['conflicting_answers']) }}).
                        These are almost certainly different questions, or one of them has a wrong key.
                        Excluded from the repeat forecast until resolved.
                    </p>
                @endunless

                <ul class="mt-3 space-y-1.5 text-sm">
                    @foreach ($cluster['members'] as $member)
                        <li class="flex gap-3">
                            <span class="w-12 shrink-0 tabular-nums text-gray-500">{{ $member['year'] }}</span>
                            <span>
                                {{ $member['stem'] }}
                                <span class="text-gray-500">→ {{ $member['answer'] }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @empty
            <div class="rounded-xl border border-gray-200 p-6 text-sm text-gray-500 dark:border-gray-700">
                No suspected duplicates. Either the bank has no rewordings, or past questions have not been
                indexed yet — run <code>php artisan examora:bootstrap</code>.
            </div>
        @endforelse
    </div>
</x-filament-panels::page>
