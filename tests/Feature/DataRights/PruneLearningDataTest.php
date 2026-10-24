<?php

namespace Tests\Feature\DataRights;

use App\Console\Commands\PruneLearningData;
use App\Models\Answer;
use App\Models\Competency;
use App\Models\ExplanationMessage;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\ReviewItem;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PruneLearningDataTest extends TestCase
{
    use RefreshDatabase;

    private const array LESSON_DATES = ['2024-07-31', '2024-08-01', '2025-07-31', '2025-08-01', '2026-07-31'];

    /**
     * @return array<string, array{string, int, string, string}>
     */
    public static function cutoffs(): array
    {
        return [
            'last day of the school year keeps two years' => ['2026-07-31', 2, '08-01', '2024-08-01'],
            'first day of the new school year drops the oldest' => ['2026-08-01', 2, '08-01', '2025-08-01'],
            'middle of the school year' => ['2026-10-24', 2, '08-01', '2025-08-01'],
            'spring before the start' => ['2026-03-15', 2, '08-01', '2024-08-01'],
            'one year only' => ['2026-10-24', 1, '08-01', '2026-08-01'],
            'calendar school year' => ['2026-03-15', 3, '01-01', '2024-01-01'],
        ];
    }

    #[DataProvider('cutoffs')]
    public function test_cutoff_is_the_start_of_the_oldest_kept_school_year(string $today, int $keptYears, string $starts, string $expected): void
    {
        $cutoff = PruneLearningData::cutoffDate(CarbonImmutable::parse($today), $keptYears, $starts);

        $this->assertSame($expected, $cutoff->toDateString());
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function runs(): array
    {
        return [
            '31 July still keeps the second school year' => ['2026-07-31', ['2024-08-01', '2025-07-31', '2025-08-01', '2026-07-31']],
            '1 August drops the school year before last' => ['2026-08-01', ['2025-08-01', '2026-07-31']],
        ];
    }

    /**
     * @param  list<string>  $keptDates
     */
    #[DataProvider('runs')]
    public function test_only_lessons_before_the_oldest_kept_school_year_are_deleted(string $today, array $keptDates): void
    {
        $this->travelTo($today);
        collect(self::LESSON_DATES)->each(fn (string $date) => Lesson::factory()->create(['held_on' => $date, 'created_at' => $date, 'updated_at' => $date]));

        $this->artisan('lernplattform:prune-learning-data')->assertSuccessful();

        $this->assertEqualsCanonicalizing($keptDates, Lesson::query()->get()->map(fn (Lesson $lesson): string => $lesson->held_on->toDateString())->all());
    }

    public function test_sessions_are_deleted_by_the_same_cutoff(): void
    {
        $this->travelTo('2026-08-01 08:00:00');
        $student = User::factory()->student()->create();
        $expired = ExplanationSession::factory()->for($student, 'student')->create(['created_at' => '2025-07-31 23:59:59']);
        $kept = ExplanationSession::factory()->for($student, 'student')->create(['created_at' => '2025-08-01 00:00:00']);

        $this->artisan('lernplattform:prune-learning-data')->assertSuccessful();

        $this->assertModelMissing($expired);
        $this->assertModelExists($kept);
    }

    public function test_dry_run_reports_counts_and_deletes_nothing(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-10-24');
        $this->expiredData();
        $before = $this->rowCounts();

        $this->artisan('lernplattform:prune-learning-data --dry-run')
            ->expectsOutputToContain('Probelauf, es wird nichts gelöscht. Aufbewahrt wird alles ab dem 01.08.2025.')
            ->expectsTable(['Gegenstand', 'würde gelöscht'], [
                ['Unterrichtsstunden', 1],
                ['Fragen', 1],
                ['Wiederholungskarten', 1],
                ['Abgaben', 1],
                ['Antworten', 1],
                ['Erklär\'s-mir-Gespräche', 1],
                ['Nachrichten', 2],
                ['Arbeitsblatt-Dateien', 1],
            ])
            ->assertSuccessful();

        $this->assertSame($before, $this->rowCounts());
        Storage::disk('local')->assertExists('worksheets/expired.pdf');
    }

    public function test_real_run_deletes_exactly_the_expired_data_and_its_files_and_is_idempotent(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-10-24');
        $expired = $this->expiredData();
        $current = $this->currentData();
        $framework = $this->rowCounts();

        $this->artisan('lernplattform:prune-learning-data')
            ->expectsTable(['Gegenstand', 'gelöscht'], [
                ['Unterrichtsstunden', 1],
                ['Fragen', 1],
                ['Wiederholungskarten', 1],
                ['Abgaben', 1],
                ['Antworten', 1],
                ['Erklär\'s-mir-Gespräche', 1],
                ['Nachrichten', 2],
                ['Arbeitsblatt-Dateien', 1],
            ])
            ->assertSuccessful();

        $this->assertModelMissing($expired['lesson']);
        $this->assertModelMissing($expired['question']);
        $this->assertModelMissing($expired['submission']);
        $this->assertModelMissing($expired['answer']);
        $this->assertModelMissing($expired['review']);
        $this->assertModelMissing($expired['session']);
        $this->assertDatabaseMissing('explanation_messages', ['explanation_session_id' => $expired['session']->id]);
        $this->assertDatabaseMissing('lesson_user', ['lesson_id' => $expired['lesson']->id]);
        Storage::disk('local')->assertMissing('worksheets/expired.pdf');

        foreach ($current as $model) {
            $this->assertModelExists($model);
        }
        $this->assertDatabaseHas('explanation_messages', ['explanation_session_id' => $current['session']->id]);
        $this->assertDatabaseHas('lesson_user', ['lesson_id' => $current['lesson']->id]);
        Storage::disk('local')->assertExists('worksheets/current.pdf');

        // Users, classes, schools and the competency framework are never touched.
        $after = $this->rowCounts();
        foreach (['users', 'school_classes', 'schools', 'competencies'] as $table) {
            $this->assertSame($framework[$table], $after[$table], $table);
        }

        $this->artisan('lernplattform:prune-learning-data')
            ->expectsTable(['Gegenstand', 'gelöscht'], [
                ['Unterrichtsstunden', 0],
                ['Fragen', 0],
                ['Wiederholungskarten', 0],
                ['Abgaben', 0],
                ['Antworten', 0],
                ['Erklär\'s-mir-Gespräche', 0],
                ['Nachrichten', 0],
                ['Arbeitsblatt-Dateien', 0],
            ])
            ->assertSuccessful();
        $this->assertSame($after, $this->rowCounts());
    }

    public function test_a_mistyped_lesson_date_cannot_delete_current_work(): void
    {
        $this->travelTo('2026-10-25 03:30:00');
        $student = User::factory()->student()->create();
        // The teacher typed the wrong year, yesterday: the row is new although held_on lies before the cut-off.
        $backdated = Lesson::factory()->for($student->schoolClass)->publishedFor([$student])->create([
            'held_on' => '2024-10-23',
            'due_on' => null,
            'created_at' => '2026-10-24 09:00:00',
            'updated_at' => '2026-10-24 09:00:00',
        ]);
        Submission::factory()->for($backdated)->for($student, 'student')->create(['created_at' => '2026-10-24 15:00:00', 'updated_at' => '2026-10-24 15:00:00']);
        // An old lesson (row and date) that received a hand-in only now.
        $lateHandIn = Lesson::factory()->for($student->schoolClass)->create(['held_on' => '2024-10-23', 'created_at' => '2024-10-23', 'updated_at' => '2024-10-23']);
        Submission::factory()->for($lateHandIn)->for($student, 'student')->create(['created_at' => '2026-10-24 16:00:00', 'updated_at' => '2026-10-24 16:00:00']);

        $this->artisan('lernplattform:prune-learning-data')->assertSuccessful();

        $this->assertModelExists($backdated);
        $this->assertModelExists($lateHandIn);
        $this->assertDatabaseCount('submissions', 2);
    }

    public function test_a_lesson_with_a_due_date_after_the_cutoff_is_kept_until_the_due_date_has_expired_too(): void
    {
        $this->travelTo('2026-08-01');
        $student = User::factory()->student()->create();
        // School year 2025/26 is still kept on 1 August 2026; the homework was handed in on 10 September 2025.
        $lesson = Lesson::factory()->for($student->schoolClass)->publishedFor([$student])->create([
            'held_on' => '2025-07-25',
            'due_on' => '2025-09-15',
            'created_at' => '2025-07-25',
            'updated_at' => '2025-07-25',
        ]);
        $submission = Submission::factory()->for($lesson)->for($student, 'student')->create(['created_at' => '2025-09-10', 'updated_at' => '2025-09-10']);

        $this->artisan('lernplattform:prune-learning-data')->assertSuccessful();

        $this->assertModelExists($lesson);
        $this->assertModelExists($submission);

        $this->travelTo('2027-08-01');
        $this->artisan('lernplattform:prune-learning-data')->assertSuccessful();

        $this->assertModelMissing($lesson);
        $this->assertModelMissing($submission);
    }

    public function test_open_homework_of_a_lesson_held_just_before_the_new_school_year_survives_a_single_kept_year(): void
    {
        $this->travelTo('2026-08-01');
        config(['lernplattform.retention.school_years_kept' => 1]);
        $lesson = Lesson::factory()->create([
            'held_on' => '2026-07-25',
            'due_on' => '2026-09-15',
            'published_at' => '2026-07-25',
            'created_at' => '2026-07-25',
            'updated_at' => '2026-07-25',
        ]);

        $this->artisan('lernplattform:prune-learning-data')->assertSuccessful();

        $this->assertModelExists($lesson);
    }

    public function test_a_worksheet_still_used_by_a_kept_lesson_is_not_deleted(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-10-24');
        Storage::disk('local')->put('worksheets/shared.pdf', 'x');
        Lesson::factory()->create(['held_on' => '2020-01-01', 'created_at' => '2020-01-01', 'updated_at' => '2020-01-01', 'attachments' => ['worksheets/shared.pdf']]);
        $kept = Lesson::factory()->create(['held_on' => '2026-10-01', 'attachments' => ['worksheets/shared.pdf']]);

        $this->artisan('lernplattform:prune-learning-data')->assertSuccessful();

        $this->assertModelExists($kept);
        Storage::disk('local')->assertExists('worksheets/shared.pdf');
    }

    public function test_a_broken_setting_deletes_nothing(): void
    {
        $this->travelTo('2026-10-24');
        Lesson::factory()->create(['held_on' => '2020-01-01', 'created_at' => '2020-01-01', 'updated_at' => '2020-01-01']);

        config(['lernplattform.retention.school_years_kept' => 0]);
        $this->artisan('lernplattform:prune-learning-data')->assertFailed();

        config(['lernplattform.retention.school_years_kept' => 2, 'lernplattform.retention.school_year_starts' => '13-45']);
        $this->artisan('lernplattform:prune-learning-data')->assertFailed();

        $this->assertDatabaseCount('lessons', 1);
    }

    public function test_the_command_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains($event->command, 'lernplattform:prune-learning-data'));

        $this->assertCount(1, $events);
        $this->assertSame('30 3 * * *', $events->first()->expression);
    }

    /**
     * One lesson of 2024 with everything attached to it, and a session of the same age.
     *
     * @return array<string, Model>
     */
    private function expiredData(): array
    {
        Competency::factory()->create();
        Storage::disk('local')->put('worksheets/expired.pdf', 'x');

        return $this->learningData('2024-09-01', 'worksheets/expired.pdf');
    }

    /**
     * @return array<string, Model>
     */
    private function currentData(): array
    {
        Storage::disk('local')->put('worksheets/current.pdf', 'x');

        return $this->learningData('2026-09-01', 'worksheets/current.pdf');
    }

    /**
     * @return array<string, Model>
     */
    private function learningData(string $date, string $worksheet): array
    {
        $schoolClass = SchoolClass::factory()->create();
        $student = User::factory()->student($schoolClass)->create();
        $lesson = Lesson::factory()->for($schoolClass)->publishedFor()->create([
            'held_on' => $date,
            'due_on' => $date,
            'created_at' => $date,
            'updated_at' => $date,
            'attachments' => [$worksheet],
        ]);
        $question = Question::factory()->for($lesson)->create();
        $submission = Submission::factory()->for($lesson)->for($student, 'student')->create(['created_at' => $date, 'updated_at' => $date]);
        $answer = Answer::factory()->for($submission)->for($question)->create();
        // A repeat card hangs on the question, so it is part of what a lesson takes with it.
        $review = ReviewItem::factory()->for($student, 'student')->for($question)->create();
        $session = ExplanationSession::factory()->for($student, 'student')->create(['created_at' => $date]);
        ExplanationMessage::factory()->count(2)->for($session, 'session')->create();

        return compact('lesson', 'question', 'submission', 'answer', 'review', 'session');
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return collect(['users', 'school_classes', 'schools', 'competencies', 'lessons', 'questions', 'submissions', 'answers', 'review_items', 'lesson_user', 'explanation_sessions', 'explanation_messages'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
            ->all();
    }
}
