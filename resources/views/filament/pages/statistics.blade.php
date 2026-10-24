@php
    use App\UnderstandingLevel;
    use Filament\Support\Icons\Heroicon;
@endphp

<x-filament-panels::page>
    @if ($classes->isEmpty() || $statistics === null)
        <x-filament::empty-state
            heading="Keine Klassen"
            description="Sobald du eine Klasse leitest oder in einer Klasse unterrichtest, erscheinen hier die Durchschnittswerte dieser Klasse."
            :icon="Heroicon::OutlinedUserGroup"
        />
    @else
        <div class="flex flex-wrap items-end gap-4">
            <label class="grid gap-1 text-sm font-medium text-gray-950 dark:text-white">
                Klasse
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="schoolClassId">
                        @foreach ($classes as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>

            <label class="grid gap-1 text-sm font-medium text-gray-950 dark:text-white">
                Fach
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="subject">
                        <option value="">Alle Fächer</option>
                        @foreach ($statistics->subjects() as $classSubject)
                            <option value="{{ $classSubject }}">{{ $classSubject }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>

            <label class="grid gap-1 text-sm font-medium text-gray-950 dark:text-white">
                Zeitraum
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="weeks">
                        @foreach (config('statistics.weeks') as $option)
                            <option value="{{ $option }}">{{ $option }} Wochen</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
        </div>

        @if (! $statistics->hasEnoughStudents())
            <x-filament::section
                heading="Zu wenige Daten"
                :icon="Heroicon::OutlinedUserGroup"
            >
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Statistiken erscheinen erst ab {{ config('statistics.min_group') }} Schüler:innen in der Klasse, damit niemand einzeln erkennbar ist.
                </p>
            </x-filament::section>
        @else
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="grid gap-1 rounded-xl p-4 ring-1 ring-gray-950/5 dark:ring-white/10">
                    <p class="text-sm text-gray-600 dark:text-gray-400">Abgabequote</p>
                    <p class="text-2xl font-semibold text-gray-950 dark:text-white">{{ $kpis['hand_in_rate'] === null ? '–' : $kpis['hand_in_rate'].' %' }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Anteil der abgegebenen Hausaufgaben bei den Abgabeterminen im Zeitraum.</p>
                </div>

                <div class="grid gap-1 rounded-xl p-4 ring-1 ring-gray-950/5 dark:ring-white/10">
                    <p class="text-sm text-gray-600 dark:text-gray-400">Ø Verständnis</p>
                    <p class="text-2xl font-semibold text-gray-950 dark:text-white">{{ $kpis['average_understanding'] === null ? '–' : $kpis['average_understanding'].' %' }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Durchschnitt der KI-Gesamtbewertung aller bewerteten Abgaben.</p>
                </div>

                <div class="grid gap-1 rounded-xl p-4 ring-1 ring-gray-950/5 dark:ring-white/10">
                    <p class="text-sm text-gray-600 dark:text-gray-400">Anteil mit Förderbedarf</p>
                    <p class="text-2xl font-semibold text-gray-950 dark:text-white">{{ $kpis['support_share'] === null ? '–' : $kpis['support_share'].' %' }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Schüler:innen, die im Schnitt unter {{ UnderstandingLevel::SUPPORT_THRESHOLD }} Punkten liegen.</p>
                </div>

                <div class="grid gap-1 rounded-xl p-4 ring-1 ring-gray-950/5 dark:ring-white/10">
                    <p class="text-sm text-gray-600 dark:text-gray-400">Gesparte Korrekturzeit</p>
                    <p class="text-xl font-semibold text-gray-950 dark:text-white">{{ $kpis['minutes_saved_per_week'] === null ? '–' : 'ca. '.$kpis['minutes_saved_per_week'].' Min. pro Woche' }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Schätzung: {{ config('statistics.minutes_per_evaluation') }} Minuten Korrekturzeit je KI-vorbewerteter Abgabe,
                        {{ $kpis['evaluated_submissions'] }} bewertete {{ $kpis['evaluated_submissions'] === 1 ? 'Abgabe' : 'Abgaben' }} im Zeitraum.
                    </p>
                </div>
            </div>

            <div class="grid gap-6 md:grid-cols-2">
                @livewire(\App\Livewire\Statistics\UnderstandingTrendChart::class, [
                    'schoolClassId' => $this->schoolClassId,
                    'subject' => $this->subject,
                    'weeks' => $this->weeks,
                ], key('understanding-trend-'.$this->schoolClassId.'-'.($this->subject ?? 'alle').'-'.$this->weeks))

                @livewire(\App\Livewire\Statistics\CompetencyAveragesChart::class, [
                    'schoolClassId' => $this->schoolClassId,
                    'subject' => $this->subject,
                    'weeks' => $this->weeks,
                ], key('competency-averages-'.$this->schoolClassId.'-'.($this->subject ?? 'alle').'-'.$this->weeks))

                @livewire(\App\Livewire\Statistics\MistakeTypesChart::class, [
                    'schoolClassId' => $this->schoolClassId,
                    'subject' => $this->subject,
                    'weeks' => $this->weeks,
                ], key('mistake-types-'.$this->schoolClassId.'-'.($this->subject ?? 'alle').'-'.$this->weeks))

                @livewire(\App\Livewire\Statistics\HandInRateChart::class, [
                    'schoolClassId' => $this->schoolClassId,
                    'subject' => $this->subject,
                    'weeks' => $this->weeks,
                ], key('hand-in-rate-'.$this->schoolClassId.'-'.($this->subject ?? 'alle').'-'.$this->weeks))

                @livewire(\App\Livewire\Statistics\ExplainItUsageChart::class, [
                    'schoolClassId' => $this->schoolClassId,
                    'subject' => $this->subject,
                    'weeks' => $this->weeks,
                ], key('explain-it-usage-'.$this->schoolClassId.'-'.($this->subject ?? 'alle').'-'.$this->weeks))
            </div>

            <p class="text-xs text-gray-500 dark:text-gray-400">
                Nur Durchschnittswerte der Klasse. Gruppen unter {{ config('statistics.min_group') }} Schüler:innen werden ausgeblendet.
                Selbsteinschätzungen aus „Heute wiederholen“ sind privat und fließen nicht ein.
            </p>
        @endif
    @endif
</x-filament-panels::page>
