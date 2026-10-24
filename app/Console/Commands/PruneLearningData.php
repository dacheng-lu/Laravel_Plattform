<?php

namespace App\Console\Commands;

use App\Models\Answer;
use App\Models\ExplanationMessage;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\ReviewItem;
use App\Models\Submission;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Storage limitation (Art. 5 and 17 DSGVO): deletes lessons and "Erklär's mir" conversations older than the
 * configured number of school years. A lesson expires only when everything about it is older than the cut-off
 * (lesson date, last change, due date, newest hand-in), so current work is never lost to a mistyped date.
 * Questions, hand-ins, answers, messages, repeat cards ("Heute wiederholen") and homework assignments go with them
 * through the foreign keys. Users, classes, schools and the competency framework are never touched.
 */
#[Signature('lernplattform:prune-learning-data {--dry-run : Nur zählen, nichts löschen}')]
#[Description('Löscht Unterrichtsstunden und Erklär\'s-mir-Gespräche, die älter als die Aufbewahrungsfrist sind')]
class PruneLearningData extends Command
{
    public function handle(): int
    {
        $keptYears = (int) config('lernplattform.retention.school_years_kept');
        $starts = (string) config('lernplattform.retention.school_year_starts');

        // A broken setting must never turn into "delete everything".
        if ($keptYears < 1 || ! preg_match('/^\d{2}-\d{2}$/', $starts) || ! checkdate((int) substr($starts, 0, 2), (int) substr($starts, 3), 2001)) {
            $this->error('Ungültige Aufbewahrungs-Einstellung: school_years_kept muss mindestens 1 sein, school_year_starts das Format MM-TT haben.');

            return self::FAILURE;
        }

        $cutoff = self::cutoffDate(CarbonImmutable::today(), $keptYears, $starts);
        $dryRun = (bool) $this->option('dry-run');

        // The lesson date is typed in by the teacher, so it alone must never expire a lesson: the row itself
        // (created_at <= updated_at), its due date and its newest hand-in have to be older than the cut-off too.
        $oldLessons = fn (): Builder => Lesson::query()
            ->whereDate('held_on', '<', $cutoff->toDateString())
            ->where('updated_at', '<', $cutoff)
            ->where(fn (Builder $query) => $query->whereNull('due_on')->orWhereDate('due_on', '<', $cutoff->toDateString()))
            ->whereDoesntHave('submissions', fn (Builder $query) => $query->where('updated_at', '>=', $cutoff));
        $oldSessions = fn (): Builder => ExplanationSession::query()->where('created_at', '<', $cutoff);
        $oldSubmissions = fn (): Builder => Submission::query()->whereIn('lesson_id', $oldLessons()->select('id'));
        $oldQuestions = fn (): Builder => Question::query()->whereIn('lesson_id', $oldLessons()->select('id'));
        // Repeat cards hang on the question, so they vanish with the lesson; counted here, before the delete.
        $oldReviewItems = fn (): Builder => ReviewItem::query()->whereIn('question_id', $oldQuestions()->select('id'));

        $files = $oldLessons()->whereNotNull('attachments')->pluck('attachments')->flatten()->filter(fn (mixed $path): bool => is_string($path))->unique();

        $counts = [
            'Unterrichtsstunden' => $oldLessons()->count(),
            'Fragen' => $oldQuestions()->count(),
            'Wiederholungskarten' => $oldReviewItems()->count(),
            'Abgaben' => $oldSubmissions()->count(),
            'Antworten' => Answer::query()->whereIn('submission_id', $oldSubmissions()->select('id'))->count(),
            'Erklär\'s-mir-Gespräche' => $oldSessions()->count(),
            'Nachrichten' => ExplanationMessage::query()->whereIn('explanation_session_id', $oldSessions()->select('id'))->count(),
            'Arbeitsblatt-Dateien' => $files->filter(fn (string $path): bool => Storage::disk('local')->exists($path))->count(),
        ];

        $this->info(($dryRun ? 'Probelauf, es wird nichts gelöscht. ' : '').'Aufbewahrt wird alles ab dem '.$cutoff->format('d.m.Y').'.');

        if (! $dryRun) {
            DB::transaction(function () use ($oldLessons, $oldSessions): void {
                $oldSessions()->delete();
                $oldLessons()->delete();
            });

            // After the commit, so a failed delete never leaves lessons without their worksheets or the other way round.
            Storage::disk('local')->delete(
                $files->reject(fn (string $path): bool => Lesson::query()->whereJsonContains('attachments', $path)->exists())->values()->all(),
            );

            Log::info('Lerndaten gelöscht', ['cutoff' => $cutoff->toDateString()] + $counts);
        }

        $this->table(['Gegenstand', $dryRun ? 'würde gelöscht' : 'gelöscht'], collect($counts)->map(fn (int $count, string $label): array => [$label, $count])->values()->all());

        return self::SUCCESS;
    }

    /**
     * First day of the oldest school year that is still kept: the start of the current school year
     * minus the other kept years. Anything dated before it is expired.
     */
    public static function cutoffDate(CarbonImmutable $today, int $keptYears, string $schoolYearStarts): CarbonImmutable
    {
        [$month, $day] = array_map('intval', explode('-', $schoolYearStarts));

        $currentStart = $today->setDate($today->year, $month, $day)->startOfDay();

        if ($currentStart->greaterThan($today)) {
            $currentStart = $currentStart->subYear();
        }

        return $currentStart->subYears($keptYears - 1);
    }
}
