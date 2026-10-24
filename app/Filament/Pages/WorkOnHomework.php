<?php

namespace App\Filament\Pages;

use App\Ai\LessonAssistant;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\Submission;
use App\Models\User;
use App\Support\MistakeRecorder;
use App\Support\ReviewScheduler;
use App\UnderstandingLevel;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * One homework for the logged-in student: write an own summary, answer the questions, hand in once.
 * The AI checks the hand-in; only then the teacher's official summary is revealed, so it can't be copied.
 */
class WorkOnHomework extends Page
{
    public const int MINIMUM_SUMMARY_LENGTH = 80;

    /**
     * A Förderaufgabe is a short practice for one competency, not a whole lesson to summarise.
     */
    public const int MINIMUM_PRACTICE_SUMMARY_LENGTH = 40;

    protected static ?string $slug = 'hausaufgaben/{lesson}';

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public Lesson $lesson;

    /**
     * @var array{summary?: string|null, answers?: array<int, string|null>}|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->isStudent() ?? false;
    }

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'hausaufgaben.bearbeiten';
    }

    public function mount(Lesson $lesson): void
    {
        // Custom pages don't apply policies: only published homework delegated to this student may be opened.
        Gate::authorize('view', $lesson);

        $this->lesson = $lesson;
        $this->form->fill();
    }

    public function getTitle(): string
    {
        return $this->lesson->topic;
    }

    public function getSubheading(): string
    {
        return collect([
            $this->lesson->subject,
            $this->lesson->isPracticeTask() ? "Förderaufgabe zu „{$this->competencyName()}“" : 'Stunde vom '.$this->lesson->held_on->format('d.m.Y'),
            $this->lesson->due_on ? 'Abgabe bis '.$this->lesson->due_on->format('d.m.Y') : null,
        ])->filter()->implode(' · ');
    }

    /**
     * @return array<string>
     */
    public function getBreadcrumbs(): array
    {
        return [MyHomework::getUrl() => 'Meine Hausaufgaben', $this->lesson->isPracticeTask() ? 'Förderaufgabe' : 'Hausaufgabe'];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Deine Zusammenfassung')
                    ->description($this->lesson->isPracticeTask()
                        ? 'Schreib es so, dass es jemand versteht, der das Thema nicht kennt.'
                        : 'Was war wichtig in der Stunde? Schreib es so, dass es jemand versteht, der nicht dabei war.')
                    ->schema([
                        Textarea::make('summary')
                            ->label($this->lesson->isPracticeTask() ? "Was du jetzt über „{$this->competencyName()}“ weißt" : 'Zusammenfassung in deinen Worten')
                            ->helperText('Mindestens '.$this->minimumSummaryLength().' Zeichen. Ein paar ganze Sätze reichen.')
                            ->required()
                            ->minLength($this->minimumSummaryLength())
                            ->maxLength(5000)
                            ->rows(6)
                            ->autosize()
                            ->validationMessages([
                                'required' => 'Bitte schreib deine Zusammenfassung.',
                                'min' => 'Schreib bitte etwas mehr, mindestens :min Zeichen.',
                            ]),
                    ]),
                ...$this->lesson->questions->values()->map(fn (Question $question, int $index): Section => Section::make('Frage '.($index + 1))
                    ->description($question->type->getDescription())
                    ->afterHeader([$this->typeBadge($question)])
                    ->schema([
                        Textarea::make("answers.{$question->id}")
                            ->label($question->prompt)
                            ->required()
                            ->maxLength(3000)
                            ->rows(4)
                            ->autosize()
                            ->validationMessages(['required' => 'Bitte beantworte diese Frage.']),
                    ]))->all(),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        $submission = $this->submission();

        if ($submission !== null) {
            return $schema->components($this->resultComponents($submission));
        }

        return $schema->components([
            Callout::make($this->lesson->isPracticeTask()
                ? "Schreib in ein paar Sätzen auf, was du jetzt über „{$this->competencyName()}“ weißt – die KI prüft danach, ob du es verstanden hast."
                : 'Fasse die Stunde in deinen eigenen Worten zusammen – die KI prüft danach, ob du alles verstanden hast.')
                ->description('Beantworte danach die Fragen. Die Zusammenfassung deiner Lehrkraft siehst du nach der Abgabe.')
                ->info()
                ->icon(Heroicon::OutlinedLightBulb),
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('submit')
                ->footer([
                    Actions::make([
                        Action::make('submit')
                            ->label('Abgeben und prüfen lassen')
                            ->icon(Heroicon::OutlinedPaperAirplane)
                            ->submit('submit'),
                    ]),
                    Text::make('Du kannst nur einmal abgeben. Lies deine Antworten vorher in Ruhe durch.'),
                ]),
        ]);
    }

    public function submit(): void
    {
        // The lesson is a public property: re-check on every request that it is still delegated to this student.
        Gate::authorize('view', $this->lesson);
        Gate::authorize('create', Submission::class);

        $data = $this->form->getState();

        try {
            $submission = DB::transaction(function () use ($data): Submission {
                $submission = $this->lesson->submissions()->create([
                    'student_id' => $this->student()->getKey(),
                    'summary' => $data['summary'],
                ]);

                $submission->answers()->createMany($this->lesson->questions->map(fn (Question $question): array => [
                    'question_id' => $question->getKey(),
                    'answer' => $data['answers'][$question->getKey()],
                ]));

                return $submission;
            });
        } catch (UniqueConstraintViolationException) {
            // A second request (double click, other tab) handed in first: show that hand-in instead of a duplicate.
            $this->redirect(static::getUrl(['lesson' => $this->lesson]));

            return;
        }

        $this->evaluateAndNotify($submission);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('evaluate')
                ->label('Jetzt auswerten lassen')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (): bool => $this->submission()?->isEvaluated() === false)
                ->action(function (): void {
                    Gate::authorize('view', $this->lesson);

                    $this->evaluateAndNotify($this->submission());
                }),
            Action::make('explain')
                ->label('Thema Tuto erklären')
                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->color('gray')
                ->url(fn (): string => ExplainIt::getUrl(['lesson' => $this->lesson->getKey()])),
        ];
    }

    private function evaluateAndNotify(Submission $submission): void
    {
        if ($this->evaluate($submission)) {
            Notification::make()
                ->title('Geschafft!')
                ->body('Die KI hat deine '.($this->lesson->isPracticeTask() ? 'Förderaufgabe' : 'Hausaufgabe').' geprüft. Schau dir an, wie es gelaufen ist.')
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('Abgegeben – die Auswertung kommt später')
                ->body('Deine Antworten sind sicher gespeichert. Die KI konnte sie gerade nicht prüfen.')
                ->warning()
                ->send();
        }

        // Reload so the page switches to the result view.
        $this->redirect(static::getUrl(['lesson' => $this->lesson]));
    }

    /**
     * Runs the AI check and stores it. On failure the submission stays unevaluated, so nothing the student wrote is lost.
     */
    private function evaluate(Submission $submission): bool
    {
        try {
            $evaluation = app(LessonAssistant::class)->evaluateSubmission($submission->load('answers'));

            DB::transaction(function () use ($submission, $evaluation): void {
                $submission->update([
                    'understanding_score' => Number::clamp($evaluation['understanding_score'], 0, 100),
                    'feedback' => $evaluation['feedback'],
                    'gaps' => $evaluation['gaps'],
                    'evaluated_at' => now(),
                ]);

                foreach ($submission->answers as $answer) {
                    $result = $evaluation['answers'][$answer->question_id] ?? null;

                    if ($result !== null) {
                        $answer->update([
                            'score' => Number::clamp($result['score'], 0, 100),
                            'feedback' => $result['feedback'],
                        ]);
                    }
                }
            });

            $this->runFollowUps($submission, $evaluation);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        return true;
    }

    /**
     * Extras after a stored evaluation; each fails closed and never undoes the evaluation.
     *
     * @param  array<string, mixed>  $evaluation
     */
    private function runFollowUps(Submission $submission, array $evaluation): void
    {
        $followUps = [
            function (Submission $fresh) use ($evaluation): void {
                app(MistakeRecorder::class)->recordForSubmission($fresh, $evaluation);
            },
            function (Submission $fresh): void {
                app(ReviewScheduler::class)->scheduleForSubmission($fresh);
            },
        ];

        foreach ($followUps as $followUp) {
            try {
                $followUp($submission->fresh(['answers.question']));
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * @return list<Component>
     */
    private function resultComponents(Submission $submission): array
    {
        $answers = $submission->answers->keyBy('question_id');

        return [
            $submission->isEvaluated()
                ? Section::make('Deine Auswertung')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->afterHeader([$this->scoreBadge($submission->understanding_score)])
                    ->schema([$this->plainText('feedback', $submission->feedback, 'Rückmeldung der KI')->hiddenLabel()])
                : Callout::make('Deine Abgabe wird noch ausgewertet')
                    ->description('Deine Antworten sind gespeichert. Sobald die KI sie geprüft hat, siehst du hier deine Auswertung.')
                    ->warning()
                    ->icon(Heroicon::OutlinedClock),
            Section::make('Darauf solltest du noch einmal schauen')
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->visible(filled($submission->gaps))
                ->schema([UnorderedList::make($submission->gaps ?? [])->columns(1)]),
            Section::make('Deine Zusammenfassung')
                ->schema([$this->plainText('summary', $submission->summary, 'Deine Zusammenfassung')->hiddenLabel()]),
            ...$this->lesson->questions->values()->map(function (Question $question, int $index) use ($answers): Section {
                $answer = $answers->get($question->getKey());

                return Section::make('Frage '.($index + 1))
                    ->afterHeader(array_filter([
                        $this->typeBadge($question),
                        $answer?->score === null ? null : $this->scoreBadge($answer->score),
                    ]))
                    ->schema([
                        Text::make($question->prompt),
                        $this->plainText("answer_{$question->getKey()}", $answer?->answer, 'Deine Antwort'),
                        $this->plainText("answer_feedback_{$question->getKey()}", $answer?->feedback, 'Rückmeldung')
                            ->visible(filled($answer?->feedback)),
                        $this->plainText("answer_mistake_{$question->getKey()}", $answer?->mistake_type?->feedbackHint(), 'Tipp')
                            ->visible((bool) config('lernplattform.mistakes.enabled') && $answer?->mistake_type !== null),
                    ]);
            })->all(),
            Section::make($this->lesson->isPracticeTask() ? "So fasst deine Lehrkraft „{$this->competencyName()}“ zusammen" : 'So fasst deine Lehrkraft die Stunde zusammen')
                ->icon(Heroicon::OutlinedAcademicCap)
                ->schema([
                    TextEntry::make('lesson_summary')
                        ->label('Zusammenfassung deiner Lehrkraft')
                        ->hiddenLabel()
                        ->state($this->lesson->summary)
                        ->markdown()
                        ->placeholder('Deine Lehrkraft hat noch keine Zusammenfassung hinterlegt.'),
                ]),
        ];
    }

    private function minimumSummaryLength(): int
    {
        return $this->lesson->isPracticeTask() ? self::MINIMUM_PRACTICE_SUMMARY_LENGTH : self::MINIMUM_SUMMARY_LENGTH;
    }

    /**
     * The competency a Förderaufgabe practises; the topic is only a fallback for the type.
     */
    private function competencyName(): string
    {
        return $this->lesson->practiceCompetency?->name ?? $this->lesson->topic;
    }

    private function typeBadge(Question $question): Text
    {
        return Text::make($question->type->getLabel())->badge()->color('gray');
    }

    /**
     * Label and icon carry the meaning too, so it doesn't depend on colour alone.
     */
    private function scoreBadge(int $score): Text
    {
        $level = UnderstandingLevel::fromScore($score);

        return Text::make("{$level->getLabel()} · {$score} von 100")
            ->badge()
            ->color($level->getColor())
            ->icon($level->getIcon());
    }

    /**
     * Shows text the way it was typed, line breaks included.
     */
    private function plainText(string $name, ?string $text, string $label): TextEntry
    {
        return TextEntry::make($name)
            ->label($label)
            ->state($text)
            ->placeholder('–')
            ->extraAttributes(['class' => 'whitespace-pre-line']);
    }

    private function submission(): ?Submission
    {
        return $this->lesson->submissions()
            ->whereBelongsTo($this->student(), 'student')
            ->with('answers')
            ->first();
    }

    private function student(): User
    {
        /** @var User */
        return Filament::auth()->user();
    }
}
