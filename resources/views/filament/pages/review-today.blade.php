@php
    use Filament\Support\Icons\Heroicon;

    /** One colour per rating; the label always says the same, colour never carries the meaning alone. */
    $ratingColors = [
        'knew' => 'success',
        'hard' => 'warning',
        'forgot' => 'gray',
    ];
@endphp

<x-filament-panels::page>
    <section class="lp-card p-6" aria-labelledby="lp-review-intro">
        <h2 id="lp-review-intro" class="text-base font-semibold text-gray-950 dark:text-white">Erst aus dem Kopf, dann nachschauen</h2>

        <p class="mt-2 max-w-2xl text-pretty text-gray-700 dark:text-gray-300">
            Antworte zuerst ohne Hilfsmittel. Danach deckst du die Musterlösung auf und schätzt selbst ein, wie gut es geklappt hat.
            Deine Einschätzung zählt für keine Note – sie entscheidet nur, wann eine Frage wieder dran ist.
        </p>
    </section>

    @if ($item === null)
        <x-filament::section>
            <div class="flex items-start gap-4">
                <x-filament::icon :icon="Heroicon::OutlinedCheckBadge" class="mt-1 size-8 shrink-0 text-success-600 dark:text-success-400" />

                <div class="min-w-0">
                    <h3 class="text-lg font-semibold text-gray-950 dark:text-white">Für heute ist alles wiederholt</h3>

                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                        @if ($remaining === 0)
                            Du hast deine Fragen für heute schon geschafft. Morgen geht es weiter.
                        @else
                            Morgen kommen die nächsten Fragen. Dann antwortest du wieder zuerst aus dem Kopf.
                        @endif
                    </p>
                </div>
            </div>
        </x-filament::section>
    @else
        <section class="lp-card p-6" aria-labelledby="lp-review-question">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ $item->question->lesson->subject }}: {{ $item->question->lesson->topic }}
                </p>

                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Noch {{ $dueCount }} {{ $dueCount === 1 ? 'Frage' : 'Fragen' }} heute
                </p>
            </div>

            <h3 id="lp-review-question" class="mt-3 text-xl text-balance text-gray-950 dark:text-white">
                {{ $prompt }}
            </h3>

            <form wire:submit="reveal" class="mt-6">
                <label for="lp-review-own-answer" class="text-sm font-medium text-gray-950 dark:text-white">
                    Deine Antwort (nur für dich)
                </label>

                <textarea
                    id="lp-review-own-answer"
                    wire:model="ownAnswer"
                    rows="4"
                    maxlength="{{ $maxOwnAnswer }}"
                    placeholder="Schreib auf, was dir dazu einfällt …"
                    class="mt-1 block w-full resize-y rounded-xl border-none bg-white px-4 py-3 text-base text-gray-950 shadow-sm ring-1 ring-gray-950/10 placeholder:text-gray-400 focus:ring-2 focus:ring-primary-600 focus:outline-hidden sm:text-sm dark:bg-white/5 dark:text-white dark:ring-white/20 dark:placeholder:text-gray-500 dark:focus:ring-primary-500"
                ></textarea>

                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Was du hier schreibst, wird nicht gespeichert. Niemand sonst liest es.
                </p>

                @unless ($revealed)
                    <div class="mt-4">
                        <x-filament::button type="submit" :icon="Heroicon::OutlinedEye" wire:target="reveal">
                            Lösung zeigen
                        </x-filament::button>
                    </div>
                @endunless
            </form>

            @if ($revealed)
                <div class="mt-6 rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10" aria-live="polite">
                    <h4 class="text-sm font-semibold text-gray-950 dark:text-white">Musterlösung</h4>

                    <p class="mt-1 whitespace-pre-line text-pretty text-gray-800 dark:text-gray-200">{{ $expectedAnswer }}</p>
                </div>

                <div class="mt-6">
                    <h4 class="text-sm font-semibold text-gray-950 dark:text-white">Wie gut wusstest du es?</h4>

                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($ratings as $rating)
                            <x-filament::button
                                :color="$ratingColors[$rating->value]"
                                wire:click="rate('{{ $rating->value }}')"
                                wire:target="rate"
                            >
                                {{ $rating->getLabel() }}
                            </x-filament::button>
                        @endforeach
                    </div>
                </div>
            @endif
        </section>
    @endif
</x-filament-panels::page>
