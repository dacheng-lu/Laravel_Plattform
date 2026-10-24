<?php

namespace App\Filament\Resources\Lessons\RelationManagers;

use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\MistakeSource;
use App\MistakeType;
use App\Models\Answer;
use App\Models\Lesson;
use App\Models\Submission;
use App\UnderstandingLevel;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

/**
 * The students' homework as handed in. Teachers read it and may re-classify the mistake type of an answer; returning a
 * submission deletes it so the student can redo it.
 */
#[On(EditLesson::HOMEWORK_CHANGED_EVENT)]
class SubmissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'submissions';

    protected static ?string $title = 'Abgaben';

    protected static ?string $modelLabel = 'Abgabe';

    protected static ?string $pluralModelLabel = 'Abgaben';

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ergebnis der KI-Prüfung')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        self::asUnderstandingBadge(TextEntry::make('understanding_score')->label('Verständnis')),
                        TextEntry::make('created_at')
                            ->label('Abgegeben am')
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('feedback')
                            ->label('Rückmeldung')
                            ->placeholder('–')
                            ->columnSpanFull(),
                        TextEntry::make('gaps')
                            ->label('Lücken')
                            ->bulleted()
                            ->listWithLineBreaks()
                            ->placeholder('Keine Lücken erkannt')
                            ->columnSpanFull(),
                    ]),
                Section::make('Zusammenfassung der Schüler:in')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('summary')
                            ->hiddenLabel(),
                    ]),
                Section::make('Antworten')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('answers')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('question.prompt')
                                    ->label('Frage'),
                                TextEntry::make('answer')
                                    ->label('Antwort'),
                                self::asUnderstandingBadge(TextEntry::make('score')->label('Bewertung')),
                                TextEntry::make('feedback')
                                    ->label('Rückmeldung')
                                    ->placeholder('–'),
                                TextEntry::make('mistake_type')
                                    ->label('Fehlerart')
                                    ->badge()
                                    ->formatStateUsing(fn (mixed $state): string => match (true) {
                                        $state instanceof MistakeType => $state->getLabel(),
                                        is_string($state) => MistakeType::tryFrom($state)?->getLabel() ?? '',
                                        default => '',
                                    })
                                    ->color(fn (?Answer $record): string => $record?->mistake_source === MistakeSource::Teacher ? 'success' : 'warning')
                                    ->placeholder('Nicht eingeordnet')
                                    // Never an assessment: an AI hint is marked as such, the teacher's own order stays visible.
                                    ->helperText(fn (?Answer $record): ?string => match (true) {
                                        $record?->mistake_source === MistakeSource::Teacher && $record->mistake_type === null => 'Von der Lehrkraft: keine Fehlerart',
                                        $record?->mistake_type === null => null,
                                        $record->mistake_source === MistakeSource::Teacher => 'Von der Lehrkraft eingeordnet',
                                        default => 'KI-Hinweis, kann falsch sein',
                                    }),
                            ]),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('student'))
            ->description(fn (): string => $this->describePendingStudents())
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('student.name')
                    ->label('Schüler:in')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Abgegeben am')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                self::asUnderstandingBadge(TextColumn::make('understanding_score')->label('Verständnis'))
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Ansehen'),
                Action::make('correctMistakes')
                    ->label('Fehlerart korrigieren')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading('Fehlerart korrigieren')
                    ->modalDescription('Deine Einordnung ersetzt den KI-Hinweis. Sie ändert weder die Bewertung noch eine Note. Automatisch eingeordnet werden nur Antworten unter '.config('lernplattform.mistakes.threshold', 80).' Punkten; deine Einordnung hat Vorrang.')
                    ->modalSubmitActionLabel('Fehlerarten speichern')
                    // Only whoever may re-classify this submission's answers sees the action at all; the action checks the
                    // same right again before it writes, so a hidden action stays refused even if it were mounted.
                    ->visible(function (Submission $record): bool {
                        $first = $record->answers->first();

                        return $first instanceof Answer && Gate::allows('correctMistake', $first);
                    })
                    ->fillForm(fn (Submission $record): array => [
                        'mistakes' => $record->answers
                            ->mapWithKeys(fn (Answer $answer): array => [$answer->getKey() => $answer->mistake_type?->value ?? ''])
                            ->all(),
                    ])
                    ->schema(fn (Submission $record): array => $record->answers
                        ->map(fn (Answer $answer): Select => Select::make("mistakes.{$answer->getKey()}")
                            ->label(Str::limit($answer->question->prompt, 80))
                            ->options(self::mistakeTypeOptions())
                            ->native(false))
                        ->values()
                        ->all())
                    ->action(function (Submission $record, array $data): void {
                        /** @var array<int|string, mixed> $submitted */
                        $submitted = $data['mistakes'] ?? [];
                        $answers = $record->answers;

                        // One authority check for the whole action and before anything is written: every answer of a
                        // submission belongs to the same student, so the policy decides for all of them alike.
                        if ($answers->isNotEmpty()) {
                            Gate::authorize('correctMistake', $answers->first());
                        }

                        DB::transaction(function () use ($answers, $submitted): void {
                            foreach ($answers as $answer) {
                                // Only the answers of this submission count: an id smuggled in from elsewhere is ignored.
                                if (! array_key_exists($answer->getKey(), $submitted)) {
                                    continue;
                                }

                                // The form declares a select for every answer of this submission, so a request that does
                                // not carry a value for one of them must leave that answer exactly as it is.
                                $type = $submitted[$answer->getKey()] ?? null;

                                if ($type === null) {
                                    continue;
                                }

                                $mistakeType = match (true) {
                                    $type instanceof MistakeType => $type,
                                    is_string($type) => MistakeType::tryFrom($type),
                                    default => null,
                                };

                                // An unknown value is no decision at all; only the empty option withdraws a type.
                                if ($mistakeType === null && $type !== '') {
                                    continue;
                                }

                                // Nothing to decide: an answer that has no type and gets none back stays unclassified,
                                // and a type the teacher already confirmed stays theirs.
                                if ($mistakeType === $answer->mistake_type
                                    && ($mistakeType === null || $answer->mistake_source === MistakeSource::Teacher)) {
                                    continue;
                                }

                                // The teacher's order wins; confidence belongs to an AI source and the score stays untouched.
                                $answer->update([
                                    'mistake_type' => $mistakeType,
                                    'mistake_confidence' => null,
                                    'mistake_source' => MistakeSource::Teacher,
                                ]);
                            }
                        });
                    })
                    ->successNotificationTitle('Fehlerarten gespeichert'),
                DeleteAction::make()
                    ->label('Zur Überarbeitung zurückgeben')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->modalHeading('Zur Überarbeitung zurückgeben?')
                    ->modalDescription('Die Abgabe samt KI-Prüfung wird gelöscht. Die Schüler:in kann die Hausaufgabe danach neu bearbeiten.')
                    ->modalSubmitActionLabel('Zurückgeben')
                    ->successNotificationTitle('Abgabe zurückgegeben'),
            ]);
    }

    /**
     * The choices of the correction form: every mistake type plus "no type at all", so a teacher can withdraw one.
     *
     * @return array<string, string>
     */
    private static function mistakeTypeOptions(): array
    {
        $options = ['' => 'Keine Fehlerart'];

        foreach (MistakeType::cases() as $type) {
            $options[$type->value] = $type->getLabel();
        }

        return $options;
    }

    /**
     * Shows a 0–100 score as percentage plus UnderstandingLevel label and icon, so it never relies on colour alone.
     */
    private static function asUnderstandingBadge(TextColumn|TextEntry $component): TextColumn|TextEntry
    {
        return $component
            ->badge()
            ->formatStateUsing(fn (int $state): string => "{$state} % · ".UnderstandingLevel::fromScore($state)->getLabel())
            ->color(fn (int $state): string => UnderstandingLevel::fromScore($state)->getColor())
            ->icon(fn (int $state): Heroicon => UnderstandingLevel::fromScore($state)->getIcon())
            ->placeholder('Wird noch geprüft');
    }

    private function describePendingStudents(): string
    {
        /** @var Lesson $lesson */
        $lesson = $this->getOwnerRecord();

        if (! $lesson->isPublished()) {
            return 'Die Hausaufgabe ist noch nicht verteilt.';
        }

        $pendingNames = $lesson->students()
            ->whereDoesntHave('submissions', fn (Builder $query): Builder => $query->whereBelongsTo($lesson))
            ->orderBy('name')
            ->pluck('name');

        return $pendingNames->isEmpty()
            ? 'Alle Schüler:innen haben abgegeben.'
            : "Noch nicht abgegeben ({$pendingNames->count()}): {$pendingNames->join(', ')}";
    }
}
