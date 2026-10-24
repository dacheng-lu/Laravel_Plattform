@php
    use App\Filament\Pages\ParentReport;
    use App\Filament\Resources\Lessons\LessonResource;
    use App\MistakeType;
    use App\UnderstandingLevel;
    use Filament\Support\Icons\Heroicon;
@endphp

<x-filament-panels::page>
    @if ($classes->isEmpty())
        <x-filament::empty-state
            heading="Keine Klassen"
            description="Sobald du eine Klasse leitest oder in einer Klasse unterrichtest, erscheint hier deren Lernstand."
            :icon="Heroicon::OutlinedUserGroup"
        />
    @else
        <div class="flex flex-wrap items-end justify-between gap-4">
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

            @include('filament.pages.insights.legend')
        </div>

        <x-filament::section
            heading="Kompetenzmatrix"
            description="Durchschnitt aller KI-bewerteten Antworten je Kompetenz des Referenzrahmens (0–100 Punkte). Ein Strich bedeutet: noch keine bewertete Antwort. Der Name führt zum Bericht für das Elterngespräch."
            :icon="Heroicon::OutlinedChartBar"
        >
            @if ($competencies->isEmpty())
                <p class="text-sm text-gray-600 dark:text-gray-400">Für diese Klasse liegen noch keine bewerteten Antworten vor.</p>
            @else
                {{-- Below md one card per student, from md (and in print) the table. display:none keeps the inactive variant out of the accessibility tree. --}}
                <ul class="grid gap-4 md:hidden print:hidden">
                    @foreach ($students as $student)
                        @php
                            $needsSupport = isset($supportNeeds[$student->id]);
                        @endphp

                        <li wire:key="matrix-card-{{ $student->id }}" @class([
                            'grid gap-3 rounded-xl p-4 ring-1',
                            'ring-gray-950/5 dark:ring-white/10' => ! $needsSupport,
                            'bg-danger-50 ring-danger-600/30 dark:bg-danger-400/10 dark:ring-danger-400/30' => $needsSupport,
                        ])>
                            <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
                                <x-filament::link :href="ParentReport::getUrl(['student' => $student])" class="font-medium">{{ $student->name }}</x-filament::link>

                                @if ($needsSupport)
                                    <x-filament::badge :color="UnderstandingLevel::NeedsSupport->getColor()" :icon="UnderstandingLevel::NeedsSupport->getIcon()" class="w-fit">{{ UnderstandingLevel::NeedsSupport->getLabel() }}</x-filament::badge>
                                @endif
                            </div>

                            <dl class="grid gap-2 text-sm">
                                @foreach ($competencies as $competency)
                                    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-t border-gray-950/5 pt-2 first:border-t-0 first:pt-0 dark:border-white/10">
                                        <dt class="min-w-32 flex-1">
                                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $competency->subject ?? 'Fachübergreifend' }}</span>
                                            <span class="text-gray-950 dark:text-white">{{ $competency->name }}</span>
                                        </dt>
                                        <dd class="ms-auto">
                                            @isset($averages[$student->id][$competency->id])
                                                @include('filament.pages.insights.score', ['score' => $averages[$student->id][$competency->id], 'withLabel' => true])
                                            @else
                                                <span class="text-gray-400 dark:text-gray-500">–<span class="sr-only">keine Daten</span></span>
                                            @endisset
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        </li>
                    @endforeach
                </ul>

                {{-- relative: the sr-only spans in far-right cells are absolutely positioned and would otherwise widen the page itself. --}}
                <div class="relative hidden overflow-x-auto md:block print:block">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-950/5 dark:border-white/10">
                                <th scope="col" class="px-3 py-2 text-start align-bottom font-medium text-gray-950 dark:text-white">Schüler:in</th>
                                @foreach ($competencies as $competency)
                                    <th scope="col" class="min-w-32 px-3 py-2 text-start align-bottom font-medium text-gray-950 dark:text-white" title="{{ $competency->description }}">
                                        <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">{{ $competency->subject ?? 'Fachübergreifend' }}</span>
                                        {{ $competency->name }}
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-950/5 dark:divide-white/10">
                            @foreach ($students as $student)
                                <tr wire:key="matrix-{{ $student->id }}">
                                    <th scope="row" class="px-3 py-2 text-start font-normal whitespace-nowrap">
                                        <x-filament::link :href="ParentReport::getUrl(['student' => $student])">{{ $student->name }}</x-filament::link>
                                    </th>
                                    @foreach ($competencies as $competency)
                                        <td class="px-3 py-2">
                                            @isset($averages[$student->id][$competency->id])
                                                @include('filament.pages.insights.score', ['score' => $averages[$student->id][$competency->id]])
                                            @else
                                                <span class="text-gray-400 dark:text-gray-500">–<span class="sr-only">keine Daten</span></span>
                                            @endisset
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section
            heading="Förderbedarf"
            description="Schüler:innen mit mindestens einer Kompetenz unter {{ UnderstandingLevel::SUPPORT_THRESHOLD }} Punkten, mit den Stunden, in denen sich die Lücke gezeigt hat."
            :icon="Heroicon::OutlinedLifebuoy"
        >
            @if (empty($supportNeeds))
                <p class="text-sm text-gray-600 dark:text-gray-400">Derzeit liegt bei niemandem in dieser Klasse eine Kompetenz unter der Förderschwelle.</p>
            @else
                <div class="mb-6 rounded-xl p-4 ring-1 ring-gray-950/5 dark:ring-white/10">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Förderaufgaben auf Knopfdruck</h3>

                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        Zu jeder Kompetenz mit Förderbedarf entwirft die KI drei bis vier Übungsfragen aus den betroffenen Stunden. Du prüfst sie, bevor sie verteilt werden, oder verteilst sie direkt.
                    </p>

                    <ul class="mt-4 divide-y divide-gray-950/5 dark:divide-white/10">
                        @foreach ($supportCompetencies as $competency)
                            <li wire:key="practice-competency-{{ $competency->id }}" class="grid gap-2 py-3 first:pt-0 last:pb-0">
                                <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                                    <p class="text-sm">
                                        <span class="font-medium text-gray-950 dark:text-white">{{ $competency->name }}</span>
                                        <span class="text-gray-600 dark:text-gray-400">
                                            ({{ $competency->subject ?? 'Fachübergreifend' }}) ·
                                            {{ $supportCounts[$competency->id] }} {{ $supportCounts[$competency->id] === 1 ? 'Schüler:in' : 'Schüler:innen' }} unter {{ UnderstandingLevel::SUPPORT_THRESHOLD }} Punkten
                                        </span>
                                    </p>

                                    @php
                                        $existingTasks = $practiceTasks->get($competency->id, collect());
                                        $buttonLabel = $existingTasks->isEmpty() ? 'Förderaufgabe erstellen' : 'Weitere Förderaufgabe erstellen';
                                    @endphp

                                    {{ ($this->createPracticeTaskAction)(['competency' => $competency->id])->label($buttonLabel)->extraAttributes(['aria-label' => "{$buttonLabel} zu {$competency->name}"]) }}
                                </div>

                                @php
                                    // Counted over the answers of this class only and split by source: a model's hint may be
                                    // wrong, the teacher's own order does not need that caveat. Unknown values are dropped
                                    // instead of breaking the page.
                                    $mistakeCounts = fn (string $source): string => collect($mistakeDistribution[$competency->id][$source] ?? [])
                                        ->mapWithKeys(fn (int $count, string $value): array => ($type = MistakeType::tryFrom($value)) === null ? [] : [$type->getLabel() => $count])
                                        ->sortDesc()
                                        ->map(fn (int $count, string $label): string => $count.'× '.$label)
                                        ->join(', ');
                                    $aiMistakes = $mistakeCounts('ai');
                                    $teacherMistakes = $mistakeCounts('teacher');
                                @endphp

                                @if ($aiMistakes !== '')
                                    <p wire:key="mistake-distribution-{{ $competency->id }}" class="text-sm text-gray-600 dark:text-gray-400">
                                        Fehlerarten: {{ $aiMistakes }} (KI-Hinweis, kann falsch sein)
                                    </p>
                                @endif

                                @if ($teacherMistakes !== '')
                                    <p wire:key="mistake-distribution-teacher-{{ $competency->id }}" class="text-sm text-gray-600 dark:text-gray-400">
                                        Fehlerarten (von Lehrkraft bestätigt oder korrigiert): {{ $teacherMistakes }}
                                    </p>
                                @endif

                                @foreach ($existingTasks as $task)
                                    <p wire:key="practice-task-{{ $task->id }}" class="text-sm text-gray-600 dark:text-gray-400">
                                        @can('update', $task)
                                            <x-filament::link :href="LessonResource::getUrl('edit', ['record' => $task])" :icon="Heroicon::OutlinedClipboardDocumentList" size="sm">
                                                Förderaufgabe vom {{ $task->held_on->format('d.m.') }}, {{ $task->created_at->format('H:i') }} Uhr
                                            </x-filament::link>
                                        @else
                                            Förderaufgabe vom {{ $task->held_on->format('d.m.') }}, {{ $task->created_at->format('H:i') }} Uhr
                                        @endcan
                                        –
                                        @if ($task->isPublished())
                                            {{ $task->submissions_count }} von {{ $task->students_count }} abgegeben
                                        @else
                                            Entwurf, noch nicht verteilt
                                        @endif
                                    </p>
                                @endforeach
                            </li>
                        @endforeach
                    </ul>
                </div>

                <ul class="divide-y divide-gray-950/5 dark:divide-white/10">
                    @foreach ($students->filter(fn ($student) => isset($supportNeeds[$student->id])) as $student)
                        <li wire:key="support-{{ $student->id }}" class="py-4 first:pt-0 last:pb-0">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="font-medium text-gray-950 dark:text-white">{{ $student->name }}</p>
                                <x-filament::link :href="ParentReport::getUrl(['student' => $student])" :icon="Heroicon::OutlinedDocumentText" size="sm">
                                    Bericht für das Elterngespräch
                                </x-filament::link>
                            </div>

                            <ul class="mt-2 grid gap-2">
                                @foreach ($supportNeeds[$student->id] as $need)
                                    @php
                                        $competency = $competencies[$need['competency_id']];
                                    @endphp

                                    <li class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-gray-600 dark:text-gray-400">
                                        @include('filament.pages.insights.score', ['score' => $need['average']])
                                        <span class="font-medium text-gray-950 dark:text-white">{{ $competency->name }}</span>
                                        <span>({{ $competency->subject ?? 'Fachübergreifend' }})</span>
                                        <span>
                                            gezeigt in:
                                            {{ collect($need['lesson_ids'])->map(fn ($lessonId) => $supportLessons[$lessonId]->topic.' am '.$supportLessons[$lessonId]->held_on->format('d.m.Y'))->join(', ') }}
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>

        <x-filament::section
            heading="Klassendurchschnitt je Stunde"
            description="Durchschnitt der KI-Gesamtbewertung aller bewerteten Abgaben einer Hausaufgabe."
            :icon="Heroicon::OutlinedPresentationChartLine"
        >
            @if ($lessons->isEmpty())
                <p class="text-sm text-gray-600 dark:text-gray-400">In dieser Klasse wurden noch keine Hausaufgaben veröffentlicht.</p>
            @else
                <ul class="grid gap-3 md:hidden print:hidden">
                    @foreach ($lessons as $lesson)
                        <li wire:key="lesson-card-{{ $lesson->id }}" class="grid gap-3 rounded-xl p-4 ring-1 ring-gray-950/5 dark:ring-white/10">
                            <p class="font-medium text-gray-950 dark:text-white">{{ $lesson->topic }}</p>

                            <dl class="grid gap-2 text-sm text-gray-600 dark:text-gray-400">
                                <div class="flex items-start justify-between gap-x-4">
                                    <dt class="shrink-0">Datum</dt>
                                    <dd class="min-w-0 break-words text-end">{{ $lesson->held_on->format('d.m.Y') }}</dd>
                                </div>
                                <div class="flex items-start justify-between gap-x-4">
                                    <dt class="shrink-0">Fach</dt>
                                    <dd class="min-w-0 break-words text-end">{{ $lesson->subject }}</dd>
                                </div>
                                <div class="flex items-start justify-between gap-x-4">
                                    <dt class="shrink-0">Abgaben</dt>
                                    <dd class="min-w-0 break-words text-end">{{ $lesson->submissions_count }} von {{ $lesson->students_count }}</dd>
                                </div>
                                <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                                    <dt>Durchschnitt</dt>
                                    <dd class="ms-auto">
                                        @if ($lesson->submissions_avg_understanding_score !== null)
                                            @include('filament.pages.insights.score', ['score' => (int) round($lesson->submissions_avg_understanding_score), 'withLabel' => true])
                                        @else
                                            noch nicht bewertet
                                        @endif
                                    </dd>
                                </div>
                            </dl>
                        </li>
                    @endforeach
                </ul>

                <div class="hidden overflow-x-auto md:block print:block">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-950/5 text-start dark:border-white/10">
                                <th scope="col" class="px-3 py-2 text-start font-medium text-gray-950 dark:text-white">Datum</th>
                                <th scope="col" class="px-3 py-2 text-start font-medium text-gray-950 dark:text-white">Fach</th>
                                <th scope="col" class="px-3 py-2 text-start font-medium text-gray-950 dark:text-white">Thema</th>
                                <th scope="col" class="px-3 py-2 text-start font-medium text-gray-950 dark:text-white">Abgaben</th>
                                <th scope="col" class="px-3 py-2 text-start font-medium text-gray-950 dark:text-white">Durchschnitt</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-950/5 text-gray-600 dark:divide-white/10 dark:text-gray-400">
                            @foreach ($lessons as $lesson)
                                <tr wire:key="lesson-{{ $lesson->id }}">
                                    <td class="px-3 py-2 whitespace-nowrap">{{ $lesson->held_on->format('d.m.Y') }}</td>
                                    <td class="px-3 py-2">{{ $lesson->subject }}</td>
                                    <td class="px-3 py-2 text-gray-950 dark:text-white">{{ $lesson->topic }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ $lesson->submissions_count }} von {{ $lesson->students_count }}</td>
                                    <td class="px-3 py-2">
                                        @if ($lesson->submissions_avg_understanding_score !== null)
                                            @include('filament.pages.insights.score', ['score' => (int) round($lesson->submissions_avg_understanding_score)])
                                        @else
                                            <span class="text-gray-400 dark:text-gray-500">noch nicht bewertet</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
