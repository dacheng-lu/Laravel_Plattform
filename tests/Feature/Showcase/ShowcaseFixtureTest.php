<?php

namespace Tests\Feature\Showcase;

use App\Filament\Pages\Statistics;
use App\Livewire\Statistics\CompetencyAveragesChart;
use App\Livewire\Statistics\ExplainItUsageChart;
use App\Livewire\Statistics\HandInRateChart;
use App\Livewire\Statistics\MistakeTypesChart;
use App\Livewire\Statistics\StatisticsChart;
use App\Livewire\Statistics\UnderstandingTrendChart;
use App\Models\Lesson;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\Role;
use App\Support\ClassStatistics;
use Database\Seeders\CompetencyFrameworkSeeder;
use Database\Seeders\DemoSchoolSeeder;
use Filament\Widgets\ChartWidget;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The showcase file of the orchestrator as it ships with the application: the real file, not the sample, has to fill
 * the demo school completely and idempotently, and the figures it leaves behind have to carry the "Statistik" page.
 *
 * Every expected number is counted from the file itself, so a repaired file is followed instead of hard-coded; the
 * minimums beside them are the floor the demo needs. A precondition test asserts that the file adds every row it
 * names, which is what lets the row counts of the file be compared with the growth of the database.
 */
class ShowcaseFixtureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private const array TABLES = ['schools', 'users', 'school_classes', 'competencies', 'lessons', 'questions', 'lesson_user', 'submissions', 'answers', 'review_items', 'explanation_sessions', 'explanation_messages', 'consent_forms', 'recording_consents'];

    /**
     * The content a second import must not touch, table by table: the columns that carry what the demo shows.
     *
     * @var array<string, list<string>>
     */
    private const array FINGERPRINT_COLUMNS = [
        'users' => ['id', 'username', 'name', 'password', 'role', 'school_class_id'],
        'lessons' => ['id', 'notes', 'summary', 'held_on', 'published_at', 'due_on'],
        'submissions' => ['id', 'summary', 'understanding_score', 'created_at', 'evaluated_at'],
        'answers' => ['id', 'answer', 'score', 'mistake_type'],
        'review_items' => ['id', 'box', 'due_on', 'knew_count'],
        'explanation_sessions' => ['id', 'finished_at'],
        'recording_consents' => ['id', 'granted_at', 'revoked_at'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CompetencyFrameworkSeeder::class, DemoSchoolSeeder::class]);
    }

    public function test_the_real_fixture_imports_completely(): void
    {
        $data = $this->fixtureData();

        $this->assertTheFileIsWhollyNew($data);

        $countsBefore = $this->rowCounts();

        $this->importShowcase();

        $countsAfter = $this->rowCounts();

        $students = count($data['students']);
        $messages = array_sum(array_map(fn (array $session): int => count($session['messages']), $data['explanation_sessions']));

        // What the demo needs at least; the file has to deliver it.
        $this->assertGreaterThanOrEqual(60, $students, 'Die Vorführdatei enthält mindestens 60 Schüler:innen.');
        $this->assertGreaterThanOrEqual(40, count($data['lessons']), 'Die Vorführdatei enthält mindestens 40 Unterrichtsstunden.');
        $this->assertGreaterThanOrEqual(600, $this->submissionsIn($data), 'Die Vorführdatei enthält mindestens 600 Abgaben.');
        $this->assertGreaterThanOrEqual(2500, $this->answersIn($data), 'Die Vorführdatei enthält mindestens 2500 Antworten.');
        $this->assertGreaterThanOrEqual(1500, count($data['review_items']), 'Die Vorführdatei enthält mindestens 1500 Wiederholungen.');
        $this->assertGreaterThanOrEqual(90, count($data['explanation_sessions']), 'Die Vorführdatei enthält mindestens 90 Erklär-Sitzungen.');
        $this->assertGreaterThanOrEqual(50, count($data['consents']), 'Die Vorführdatei enthält mindestens 50 Einwilligungen.');

        // ... and the import has to write every row of the file into the demo school.
        $this->assertSame($countsBefore['schools'], $countsAfter['schools'], 'Der Import legt keine Schule an.');
        $this->assertSame($countsBefore['competencies'], $countsAfter['competencies'], 'Der Import ändert das Kompetenzraster nicht.');
        $this->assertSame($countsBefore['users'] + count($data['teachers']) + $students, $countsAfter['users'], 'Jedes Konto der Datei wird angelegt.');
        $this->assertSame($countsBefore['school_classes'] + count($data['classes']), $countsAfter['school_classes'], 'Jede Klasse der Datei wird angelegt.');
        $this->assertSame($countsBefore['lessons'] + count($data['lessons']), $countsAfter['lessons'], 'Jede Stunde der Datei wird angelegt.');
        $this->assertSame($countsBefore['questions'] + $this->questionsIn($data), $countsAfter['questions'], 'Jede Frage der Datei wird angelegt.');
        $this->assertSame($countsBefore['submissions'] + $this->submissionsIn($data), $countsAfter['submissions'], 'Jede Abgabe der Datei wird angelegt.');
        $this->assertSame($countsBefore['answers'] + $this->answersIn($data), $countsAfter['answers'], 'Jede Antwort der Datei wird angelegt.');
        $this->assertSame($countsBefore['review_items'] + count($data['review_items']), $countsAfter['review_items'], 'Jede Wiederholung der Datei wird angelegt.');
        $this->assertSame($countsBefore['explanation_sessions'] + count($data['explanation_sessions']), $countsAfter['explanation_sessions'], 'Jede Erklär-Sitzung der Datei wird angelegt.');
        $this->assertSame($countsBefore['explanation_messages'] + $messages, $countsAfter['explanation_messages'], 'Jede Nachricht der Datei wird angelegt.');
        $this->assertSame($countsBefore['consent_forms'] + 1, $countsAfter['consent_forms'], 'Die Einwilligungen bekommen eine veröffentlichte Vorlage.');
        $this->assertSame($countsBefore['recording_consents'] + count($data['consents']), $countsAfter['recording_consents'], 'Jede Einwilligung der Datei wird angelegt.');

        $class = SchoolClass::query()
            ->where('school_id', $this->demoSchool()->getKey())
            ->where('name', '8a')
            ->first();

        $this->assertNotNull($class, 'Die Klasse 8a der Vorführdatei existiert nach dem Import.');
        $this->assertSame(
            count(array_filter($data['students'], fn (array $student): bool => $student['class'] === '8a')),
            $class->students()->count(),
            'Alle Schüler:innen der Datei für die 8a sind in der 8a.',
        );
    }

    public function test_importing_twice_changes_nothing(): void
    {
        $this->importShowcase();

        $countsAfterFirstRun = $this->rowCounts();
        $fingerprintAfterFirstRun = $this->contentFingerprint();

        // Positive control: the first run did fill the demo school, so an unchanged row count means something.
        $this->assertGreaterThan(0, $countsAfterFirstRun['lessons']);

        $this->importShowcase();

        $this->assertSame($countsAfterFirstRun, $this->rowCounts(), 'Ein zweiter Import legt keine Zeile an und ändert keine.');
        $this->assertSame($fingerprintAfterFirstRun, $this->contentFingerprint(), 'Ein zweiter Import ändert keinen Inhalt der Demo-Daten.');

        // Positive control for the fingerprint itself: one changed lesson note has to show up.
        $lesson = Lesson::query()->orderBy('id')->firstOrFail();
        $lesson->forceFill(['notes' => $lesson->notes.' (nachträglich geändert)'])->save();

        $changed = $this->contentFingerprint()['lessons'];

        $this->assertNotSame($fingerprintAfterFirstRun['lessons'], $changed, 'Der Fingerabdruck erkennt eine geänderte Stunde.');
    }

    public function test_the_statistics_page_has_figures_for_the_demo_teacher(): void
    {
        $this->importShowcase();

        $teacher = $this->user('lehrer');
        $schoolClass = SchoolClass::query()
            ->where('school_id', $teacher->school_id)
            ->where('name', '7b')
            ->firstOrFail();

        $this->actingAs($teacher)
            ->get(Statistics::getUrl())
            ->assertOk()
            ->assertSee('Abgabequote')
            // Positive control for the missing poll below: the page really is Livewire markup.
            ->assertSee('wire:model.live')
            ->assertDontSee('wire:poll');

        Livewire::actingAs($teacher)
            ->test(Statistics::class)
            ->assertOk()
            ->assertSet('schoolClassId', $schoolClass->getKey())
            ->assertViewHas('kpis', $this->hasEveryKpi(...));

        // The charts themselves carry no poll, so a filter change stays the only reason to query the aggregates again.
        Livewire::actingAs($teacher)
            ->test(UnderstandingTrendChart::class, ['schoolClassId' => $schoolClass->getKey()])
            ->assertOk()
            ->assertDontSee('wire:poll');

        // Every chart of the page delivers labels and values for the demo class.
        foreach ([CompetencyAveragesChart::class, ExplainItUsageChart::class, HandInRateChart::class, MistakeTypesChart::class, UnderstandingTrendChart::class] as $chart) {
            $component = Livewire::actingAs($teacher)
                ->test($chart, ['schoolClassId' => $schoolClass->getKey(), 'subject' => null, 'weeks' => 10])
                ->assertOk()
                ->instance();

            $this->assertChartHasValues($chart, (new ReflectionMethod($component, 'getCachedData'))->invoke($component));
        }

        $statistics = ClassStatistics::forViewer($teacher, $schoolClass->getKey());

        $weeks = array_filter($statistics->understandingTrend(), fn (?int $average): bool => $average !== null);
        $usage = array_filter($statistics->explainItUsage(), fn (?int $sessions): bool => $sessions !== null);

        $this->assertGreaterThanOrEqual(6, count($weeks), 'Der Verlauf der Demo-Klasse zeigt mindestens sechs Wochen mit Werten.');
        $this->assertGreaterThanOrEqual(3, count($usage), 'Die Nutzung von "Erklär\'s mir" zeigt mindestens drei Wochen mit Werten.');
        $this->assertGreaterThanOrEqual(3, count($statistics->mistakeTypes()), 'Die Fehlerarten der Demo-Klasse zeigen mindestens drei Fächer.');
        $this->assertGreaterThanOrEqual(5, count($statistics->competencyAverages()), 'Die Demo-Klasse zeigt mindestens fünf Kompetenzen.');
        $this->assertGreaterThanOrEqual(8, count($statistics->handInRates()), 'Die Demo-Klasse zeigt mindestens acht Hausaufgaben.');

        // The charts of the page inherit the switch: the framework polls every five seconds, this page never does.
        $this->assertSame('5s', (new ReflectionProperty(ChartWidget::class, 'pollingInterval'))->getDefaultValue());
        $this->assertNull((new ReflectionProperty(StatisticsChart::class, 'pollingInterval'))->getDefaultValue());
    }

    public function test_the_demo_student_has_open_and_evaluated_homework(): void
    {
        $this->importShowcase();

        $student = $this->user('schueler');

        $open = Lesson::assignedTo($student)
            ->whereNotNull('due_on')
            ->whereDate('due_on', '>=', today())
            ->whereDoesntHave('submissions', fn (Builder $query) => $query->whereBelongsTo($student, 'student'))
            ->count();

        $this->assertGreaterThanOrEqual(1, $open, 'Die Demo-Schülerin hat mindestens eine offene Hausaufgabe.');

        $evaluated = Submission::query()
            ->where('student_id', $student->getKey())
            ->whereIn('lesson_id', Lesson::assignedTo($student)->select('lessons.id'))
            ->whereNotNull('evaluated_at')
            ->count();

        $this->assertGreaterThanOrEqual(8, $evaluated, 'Die Demo-Schülerin hat mindestens acht bewertete Abgaben.');
    }

    public function test_no_generated_text_contains_names_of_demo_students(): void
    {
        $data = $this->fixtureData();

        // The students of the demo school after the import: the accounts of the seeder plus the accounts of the file.
        $firstNames = User::query()
            ->withRole(Role::Student)
            ->where('school_id', $this->demoSchool()->getKey())
            ->pluck('name')
            ->merge(array_column($data['students'], 'name'))
            ->map(fn (string $name): string => explode(' ', $name)[0])
            ->filter(fn (string $firstName): bool => mb_strlen($firstName) >= 3)
            ->unique()
            ->values();

        $texts = $this->generatedTexts($data);

        $this->assertGreaterThanOrEqual(40, $firstNames->count(), 'Genug Vornamen, damit die Prüfung etwas finden kann.');
        $this->assertGreaterThanOrEqual(1000, count($texts), 'Genug generierte Texte, damit die Prüfung etwas finden kann.');

        // Positive control: the same pattern does find a name that stands in a sentence.
        $firstName = $firstNames->first();

        $this->assertSame(1, preg_match($this->namePattern($firstName), "Hier erklärt {$firstName} die Ballade."));

        foreach ($firstNames as $firstName) {
            $pattern = $this->namePattern($firstName);

            foreach ($texts as $text) {
                $this->assertSame(0, preg_match($pattern, $text), "Kein generierter Text nennt den Vornamen {$firstName}: {$text}");
            }
        }
    }

    /**
     * The file adds every row it names: no account, class, lesson or conversation of it exists before the import, and
     * the demo school has no consent yet. Only then do the row counts of the file equal the growth of the database.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertTheFileIsWhollyNew(array $data): void
    {
        $school = $this->demoSchool();
        $schoolId = $school->getKey();

        $usernames = [...array_column($data['teachers'], 'username'), ...array_column($data['students'], 'username')];

        $this->assertSame([], User::query()->whereIn('username', $usernames)->pluck('username')->all(), 'Die Datei legt nur neue Konten an.');
        $this->assertSame([], SchoolClass::query()->where('school_id', $schoolId)->whereIn('name', array_column($data['classes'], 'name'))->pluck('name')->all(), 'Die Datei legt nur neue Klassen an.');

        $existingLessons = DB::table('lessons')
            ->join('school_classes', 'school_classes.id', '=', 'lessons.school_class_id')
            ->where('school_classes.school_id', $schoolId)
            ->get(['school_classes.name', 'lessons.subject', 'lessons.topic'])
            ->map(fn (object $lesson): string => "{$lesson->name}|{$lesson->subject}|{$lesson->topic}")
            ->all();

        $fileLessons = array_map(fn (array $lesson): string => "{$lesson['class']}|{$lesson['subject']}|{$lesson['topic']}", $data['lessons']);

        $this->assertSame([], array_values(array_intersect($fileLessons, $existingLessons)), 'Die Datei legt nur neue Stunden an.');

        $existingSessions = DB::table('explanation_sessions')
            ->join('users', 'users.id', '=', 'explanation_sessions.student_id')
            ->get(['users.username', 'explanation_sessions.topic'])
            ->map(fn (object $session): string => "{$session->username}|{$session->topic}")
            ->all();

        $fileSessions = array_map(fn (array $session): string => "{$session['student']}|{$session['topic']}", $data['explanation_sessions']);

        $this->assertSame([], array_values(array_intersect($fileSessions, $existingSessions)), 'Die Datei legt nur neue Gespräche an.');
        $this->assertSame(count($fileSessions), count(array_unique($fileSessions)), 'Jedes Gespräch der Datei kommt nur einmal vor.');

        $this->assertSame(0, DB::table('consent_forms')->where('school_id', $schoolId)->count(), 'Die Demo-Schule hat noch keine Einwilligungsvorlage.');
        $this->assertSame(0, DB::table('recording_consents')->where('school_id', $schoolId)->count(), 'Die Demo-Schule hat noch keine Einwilligung.');
    }

    private function importShowcase(): void
    {
        $startedAt = microtime(true);

        $exitCode = Artisan::call('lernplattform:seed-showcase');

        $duration = microtime(true) - $startedAt;

        $this->assertSame(Command::SUCCESS, $exitCode, Artisan::output());
        $this->assertLessThan(60.0, $duration, sprintf('Der Import der Vorführdatei dauert %.1f s und muss unter 60 s bleiben.', $duration));
    }

    /**
     * A chart renders only with labels and at least one value; an empty result would leave the page with an empty chart.
     *
     * @param  class-string<StatisticsChart>  $chart
     * @param  array<string, mixed>  $data
     */
    private function assertChartHasValues(string $chart, array $data): void
    {
        $this->assertNotEmpty($data['labels'] ?? [], "Der Chart {$chart} hat Beschriftungen.");

        $values = [];

        foreach ($data['datasets'] ?? [] as $dataset) {
            foreach ($dataset['data'] ?? [] as $value) {
                $values[] = $value;
            }
        }

        $this->assertNotEmpty(array_filter($values, fn (mixed $value): bool => $value !== null), "Der Chart {$chart} zeigt mindestens einen Wert.");
    }

    /**
     * The content of the tables the demo shows, one hash per table, read in a fixed order.
     *
     * @return array<string, string>
     */
    private function contentFingerprint(): array
    {
        $fingerprint = [];

        foreach (self::FINGERPRINT_COLUMNS as $table => $columns) {
            $fingerprint[$table] = sha1(DB::table($table)->orderBy('id')->get($columns)->toJson());
        }

        return $fingerprint;
    }

    /**
     * Every figure the page shows has a value for the demo class, and the class really has evaluated hand-ins behind it.
     *
     * @param  array<string, mixed>  $kpis
     */
    private function hasEveryKpi(array $kpis): bool
    {
        return $kpis['hand_in_rate'] !== null
            && $kpis['average_understanding'] !== null
            && $kpis['support_share'] !== null
            && $kpis['minutes_saved_per_week'] !== null
            && $kpis['evaluated_submissions'] > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function fixtureData(): array
    {
        $path = database_path('seeders/demo/showcase.json');
        $json = file_get_contents($path);

        $this->assertIsString($json, "Die Vorführdatei {$path} fehlt oder ist nicht lesbar.");

        $data = json_decode($json, true);

        $this->assertIsArray($data, "Die Vorführdatei {$path} enthält kein JSON-Objekt.");
        $this->assertSame(1, $data['version'] ?? null, 'Die Vorführdatei folgt dem Schema, das der Import versteht.');

        return $data;
    }

    /**
     * Every text the import stores as content: notes, summaries, questions, hand-ins, answers, session feedback and
     * the messages of "Erklär's mir".
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function generatedTexts(array $data): array
    {
        $texts = [];

        foreach ($data['lessons'] as $lesson) {
            $texts[] = (string) $lesson['notes'];

            if (($lesson['summary'] ?? null) !== null) {
                $texts[] = (string) $lesson['summary'];
            }

            foreach ($lesson['questions'] as $question) {
                $texts[] = (string) $question['prompt'];
                $texts[] = (string) $question['expected_answer'];
            }

            foreach ($lesson['submissions'] as $submission) {
                $texts[] = (string) $submission['summary'];

                if (($submission['feedback'] ?? null) !== null) {
                    $texts[] = (string) $submission['feedback'];
                }

                foreach ($submission['gaps'] as $gap) {
                    $texts[] = (string) $gap;
                }

                foreach ($submission['answers'] as $answer) {
                    $texts[] = (string) $answer['answer'];

                    if (($answer['feedback'] ?? null) !== null) {
                        $texts[] = (string) $answer['feedback'];
                    }
                }
            }
        }

        foreach ($data['explanation_sessions'] as $session) {
            if (($session['feedback'] ?? null) !== null) {
                $texts[] = (string) $session['feedback'];
            }

            foreach ($session['messages'] as $message) {
                $texts[] = (string) $message['content'];
            }
        }

        return $texts;
    }

    /**
     * The first name as a whole word, in the spelling of the file: no letter before or after it, so "Mia" is found in
     * "Mia schreibt" but not in "Mias" or "Familie".
     */
    private function namePattern(string $firstName): string
    {
        return '/(?<!\pL)'.preg_quote($firstName, '/').'(?!\pL)/u';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function questionsIn(array $data): int
    {
        return array_sum(array_map(fn (array $lesson): int => count($lesson['questions']), $data['lessons']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function submissionsIn(array $data): int
    {
        return array_sum(array_map(fn (array $lesson): int => count($lesson['submissions']), $data['lessons']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function answersIn(array $data): int
    {
        $answers = 0;

        foreach ($data['lessons'] as $lesson) {
            foreach ($lesson['submissions'] as $submission) {
                $answers += count($submission['answers']);
            }
        }

        return $answers;
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return collect(self::TABLES)
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
            ->all();
    }

    private function demoSchool(): School
    {
        return School::query()->where('school_number', '4711')->firstOrFail();
    }

    private function user(string $username): User
    {
        return User::query()->where('username', $username)->firstOrFail();
    }
}
