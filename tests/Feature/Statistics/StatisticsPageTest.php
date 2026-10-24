<?php

namespace Tests\Feature\Statistics;

use App\Filament\Pages\Statistics;
use App\Livewire\Statistics\CompetencyAveragesChart;
use App\Livewire\Statistics\ExplainItUsageChart;
use App\Livewire\Statistics\HandInRateChart;
use App\Livewire\Statistics\MistakeTypesChart;
use App\Livewire\Statistics\StatisticsChart;
use App\Livewire\Statistics\UnderstandingTrendChart;
use App\MistakeType;
use App\Models\Answer;
use App\Models\Competency;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\ReviewItem;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The "Statistik" page: who may open it, which classes and filters it offers, the figures it shows and what it must
 * never show. Hand-built factory data keeps every expected number readable.
 */
class StatisticsPageTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $schoolClass;

    private User $teacher;

    /**
     * @var Collection<int, User>
     */
    private Collection $students;

    private Lesson $firstLesson;

    private Lesson $secondLesson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolClass = SchoolClass::factory()->create(['name' => 'Statistik 7b']);
        $this->teacher = $this->schoolClass->teacher;
        $this->students = $this->createStudents($this->schoolClass, 4);

        $this->firstLesson = $this->publishedLesson('Mathematik', 'Bruchrechnung', today()->subDays(3));
        $this->secondLesson = $this->publishedLesson('Mathematik', 'Prozentrechnung', today()->subDay());

        $this->evaluatedSubmission($this->firstLesson, $this->students[0], 80);
        $this->evaluatedSubmission($this->firstLesson, $this->students[1], 60);
        $this->evaluatedSubmission($this->firstLesson, $this->students[2], 40);
        $this->evaluatedSubmission($this->secondLesson, $this->students[0], 90);
        $this->evaluatedSubmission($this->secondLesson, $this->students[1], 70);
        $this->evaluatedSubmission($this->secondLesson, $this->students[2], 50);
    }

    public function test_teachers_of_the_class_and_the_school_administration_see_the_page(): void
    {
        $this->actingAs($this->teacher)
            ->get(Statistics::getUrl())
            ->assertOk()
            ->assertSee('Abgabequote')
            ->assertSee('Statistik 7b');

        $this->actingAs(User::factory()->admin()->for($this->schoolClass->school)->create())
            ->get(Statistics::getUrl(['klasse' => $this->schoolClass->id]))
            ->assertOk()
            ->assertSee('Abgabequote');
    }

    public function test_students_and_platform_operators_are_forbidden(): void
    {
        $this->actingAs($this->teacher)->get(Statistics::getUrl())->assertOk();

        $this->actingAs($this->students[0])->get(Statistics::getUrl())->assertForbidden();
        $this->actingAs(User::factory()->operator()->create())->get(Statistics::getUrl())->assertForbidden();
    }

    public function test_a_class_of_another_teacher_or_school_is_forbidden(): void
    {
        $foreignClass = SchoolClass::factory()->create(['name' => 'Fremdklasse']);

        $this->actingAs($this->teacher)->get(Statistics::getUrl(['klasse' => $this->schoolClass->id]))->assertOk();
        $this->actingAs($this->teacher)->get(Statistics::getUrl(['klasse' => $foreignClass->id]))->assertForbidden();

        $this->actingAs(User::factory()->admin()->for($this->schoolClass->school)->create())
            ->get(Statistics::getUrl(['klasse' => $this->schoolClass->id]))
            ->assertOk();
        $this->actingAs(User::factory()->admin()->create())
            ->get(Statistics::getUrl(['klasse' => $this->schoolClass->id]))
            ->assertForbidden();
    }

    public function test_the_class_select_lists_only_the_visible_classes(): void
    {
        SchoolClass::factory()->for($this->teacher, 'teacher')->create(['name' => 'Statistik 8a']);
        SchoolClass::factory()->create(['name' => 'Fremdklasse']);

        Livewire::actingAs($this->teacher)
            ->test(Statistics::class)
            ->assertOk()
            ->assertSet('schoolClassId', $this->schoolClass->id)
            ->assertSee('Statistik 7b')
            ->assertSee('Statistik 8a')
            ->assertDontSee('Fremdklasse');
    }

    public function test_the_page_shows_the_figures_of_the_class(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Statistics::class)
            ->assertOk()
            ->assertSeeTextInOrder([
                'Abgabequote', '75 %',
                'Ø Verständnis', '65 %',
                'Anteil mit Förderbedarf', '33 %',
                'ca. 2 Min. pro Woche',
                'Schätzung: 3 Minuten Korrekturzeit je KI-vorbewerteter Abgabe',
                '6 bewertete Abgaben im Zeitraum',
            ])
            ->assertSee('Nur Durchschnittswerte der Klasse. Gruppen unter 3 Schüler:innen werden ausgeblendet.');
    }

    public function test_a_class_with_too_few_students_shows_no_figures(): void
    {
        $smallClass = SchoolClass::factory()
            ->for(User::factory()->teacher()->for($this->schoolClass->school)->create(), 'teacher')
            ->create(['name' => 'Statistik 5a']);
        $students = $this->createStudents($smallClass, 2);

        $lesson = Lesson::factory()->for($smallClass)->publishedFor($students)
            ->create(['subject' => 'Mathematik', 'topic' => 'Kopfrechnen', 'due_on' => today()->subDay()]);
        $this->evaluatedSubmission($lesson, $students[0], 30);
        $this->evaluatedSubmission($lesson, $students[1], 30);

        Livewire::actingAs($smallClass->teacher)
            ->test(Statistics::class)
            ->assertOk()
            ->assertSet('schoolClassId', $smallClass->id)
            ->assertSee('Zu wenige Daten')
            ->assertSee('Statistiken erscheinen erst ab 3 Schüler:innen in der Klasse, damit niemand einzeln erkennbar ist.')
            ->assertDontSee('Abgabequote')
            ->assertDontSee('Ø Verständnis')
            ->assertDontSee('Min. pro Woche');
    }

    public function test_the_page_shows_a_dash_while_a_figure_has_no_data(): void
    {
        $newClass = SchoolClass::factory()
            ->for(User::factory()->teacher()->for($this->schoolClass->school)->create(), 'teacher')
            ->create(['name' => 'Statistik 10b']);
        $this->createStudents($newClass, 3);

        Livewire::actingAs($newClass->teacher)
            ->test(Statistics::class)
            ->assertOk()
            ->assertSet('schoolClassId', $newClass->id)
            ->assertDontSee('Zu wenige Daten')
            ->assertSeeTextInOrder(['Abgabequote', '–', 'Ø Verständnis', '–', 'Anteil mit Förderbedarf', '–']);
    }

    public function test_the_subject_filter_changes_the_figures(): void
    {
        $biologyLesson = $this->publishedLesson('Biologie', 'Fotosynthese', today()->subDay());

        foreach ($this->students->take(3) as $student) {
            $this->evaluatedSubmission($biologyLesson, $student, 80);
        }

        $page = Livewire::actingAs($this->teacher)
            ->test(Statistics::class)
            ->assertOk()
            ->assertSee('Alle Fächer')
            ->assertSee('Mathematik')
            ->assertSee('Biologie')
            ->assertViewHas('kpis', fn (array $kpis): bool => $kpis['average_understanding'] === 70 && $kpis['evaluated_submissions'] === 9);

        // Six hand-ins with Ø 65 and three with 80: (390 + 240) / 9 = 70.
        $page->set('subject', 'Biologie')
            ->assertViewHas('kpis', fn (array $kpis): bool => $kpis['average_understanding'] === 80 && $kpis['evaluated_submissions'] === 3);

        $page->set('subject', null)
            ->assertViewHas('kpis', fn (array $kpis): bool => $kpis['average_understanding'] === 70 && $kpis['evaluated_submissions'] === 9);
    }

    public function test_the_period_filter_changes_the_figures(): void
    {
        $oldLesson = $this->publishedLesson('Mathematik', 'Wiederholung', today()->subWeeks(6));
        $this->evaluatedSubmission($oldLesson, $this->students[0], 20, today()->subWeeks(6));
        $this->evaluatedSubmission($oldLesson, $this->students[1], 20, today()->subWeeks(6));

        // Ten weeks: the six hand-ins of the setup (Ø 65) plus two with 20 → (390 + 40) / 8 = 53.75 → 54.
        Livewire::actingAs($this->teacher)
            ->test(Statistics::class)
            ->assertOk()
            ->assertSee('4 Wochen')
            ->assertSee('10 Wochen')
            ->assertViewHas('kpis', fn (array $kpis): bool => $kpis['average_understanding'] === 54 && $kpis['evaluated_submissions'] === 8)
            ->set('weeks', 4)
            ->assertViewHas('kpis', fn (array $kpis): bool => $kpis['average_understanding'] === 65 && $kpis['evaluated_submissions'] === 6);
    }

    public function test_the_page_shows_no_individual_results(): void
    {
        $page = Livewire::actingAs($this->teacher)
            ->test(Statistics::class)
            ->assertOk()
            ->assertSee('Abgabequote')
            ->assertDontSee('Mia Hoffmann')
            ->assertDontSee('Ben Krüger')
            ->assertDontSee('Sofort gewusst')
            ->assertDontSee('Nicht gewusst');

        $question = Question::factory()->for($this->firstLesson)->create();

        foreach ($this->students as $student) {
            ReviewItem::factory()->for($student, 'student')->for($question)->create(['knew_count' => 5, 'hard_count' => 2, 'forgot_count' => 1]);
            ExplanationSession::factory()->for($student, 'student')->finished(70)->create(['lesson_id' => null, 'topic' => 'Mein Hobby']);
        }

        // Private self-assessments and free-topic sessions change nothing on the page.
        Livewire::actingAs($this->teacher)
            ->test(Statistics::class)
            ->assertOk()
            ->assertSeeTextInOrder(['Abgabequote', '75 %', 'Ø Verständnis', '65 %', 'Anteil mit Förderbedarf', '33 %'])
            ->assertDontSee('Mia Hoffmann')
            ->assertDontSee('Mein Hobby');
    }

    /**
     * @return array<string, array{class-string<StatisticsChart>}>
     */
    public static function charts(): array
    {
        return [
            'Verständnis-Trend' => [UnderstandingTrendChart::class],
            'Kompetenzen' => [CompetencyAveragesChart::class],
            'Fehlerarten' => [MistakeTypesChart::class],
            'Abgabequote' => [HandInRateChart::class],
            "Erklär's mir" => [ExplainItUsageChart::class],
        ];
    }

    /**
     * @param  class-string<StatisticsChart>  $chart
     */
    #[DataProvider('charts')]
    public function test_manipulated_chart_properties_of_a_foreign_class_are_forbidden(string $chart): void
    {
        $foreignClass = SchoolClass::factory()->create(['name' => 'Fremdklasse']);

        // Positive control: the chart renders for the own class.
        Livewire::actingAs($this->teacher)
            ->test($chart, ['schoolClassId' => $this->schoolClass->id])
            ->assertOk();

        Livewire::actingAs($this->teacher)
            ->test($chart, ['schoolClassId' => $foreignClass->id])
            ->assertForbidden();
    }

    public function test_the_charts_deliver_chart_js_data(): void
    {
        $competency = Competency::factory()->create(['subject' => 'Mathematik', 'name' => 'Rechenwege']);

        foreach ([$this->firstLesson, $this->secondLesson] as $lesson) {
            foreach ($this->students->take(3) as $student) {
                $submission = Submission::query()->where('lesson_id', $lesson->id)->where('student_id', $student->id)->firstOrFail();
                $mistakeType = MistakeType::Concept;

                if ($student->is($this->students[1])) {
                    $mistakeType = $lesson->is($this->firstLesson) ? MistakeType::Procedure : MistakeType::Incomplete;
                }

                Answer::factory()
                    ->for($submission)
                    ->for(Question::factory()->for($lesson)->for($competency))
                    ->mistake($mistakeType)
                    ->create(['score' => $submission->understanding_score]);
            }
        }

        $trend = $this->chartData(UnderstandingTrendChart::class);
        $points = array_combine($trend['labels'], $trend['datasets'][0]['data']);

        $this->assertSame('Ø Verständnis', $trend['datasets'][0]['label']);
        $this->assertCount(11, $trend['labels']);
        $this->assertSame(65, $points['KW '.today()->isoWeek]);
        $this->assertNull($points['KW '.today()->subWeeks(3)->isoWeek]);
        $this->assertSame(0.3, $trend['datasets'][0]['tension']);
        $this->assertTrue($trend['datasets'][0]['spanGaps']);

        $mistakes = $this->chartData(MistakeTypesChart::class);
        $datasets = collect($mistakes['datasets'])->keyBy('label');
        $colors = array_column($mistakes['datasets'], 'backgroundColor');

        $this->assertSame(['Mathematik'], $mistakes['labels']);
        $this->assertCount(5, $mistakes['datasets']);

        // One colour per mistake type in the order of MistakeType::cases(), or the stacked bars blur into one.
        $this->assertCount(5, array_unique($colors));
        $this->assertSame(['#f87171', '#fb923c', '#facc15', '#a78bfa', '#94a3b8'], $colors);
        $this->assertSame($colors, array_column($mistakes['datasets'], 'borderColor'));
        $this->assertSame(4, $datasets[MistakeType::Concept->getLabel()]['data'][0]);
        $this->assertSame(1, $datasets[MistakeType::Procedure->getLabel()]['data'][0]);
        $this->assertSame(1, $datasets[MistakeType::Incomplete->getLabel()]['data'][0]);
        $this->assertSame(0, $datasets[MistakeType::Empty->getLabel()]['data'][0]);
    }

    public function test_the_competency_bars_follow_the_traffic_light(): void
    {
        $strong = Competency::factory()->create(['subject' => 'Mathematik', 'name' => 'Grundlagen der Zellatmung und Photosynthese']);
        $partly = Competency::factory()->create(['subject' => 'Mathematik', 'name' => 'Kurz']);
        $weak = Competency::factory()->create(['subject' => 'Mathematik', 'name' => 'Mittel']);

        foreach ($this->students->take(3) as $student) {
            $submission = Submission::query()
                ->where('lesson_id', $this->firstLesson->id)
                ->where('student_id', $student->id)
                ->firstOrFail();

            foreach ([[$strong, 90], [$partly, 60], [$weak, 30]] as [$competency, $score]) {
                Answer::factory()
                    ->for($submission)
                    ->for(Question::factory()->for($this->firstLesson)->for($competency))
                    ->mistake(MistakeType::Concept)
                    ->create(['score' => $score]);
            }
        }

        $chart = $this->chartData(CompetencyAveragesChart::class);

        // Alphabetical by label: Grundlagen… (90, understood), Kurz (60, partly), Mittel (30, needs support).
        $this->assertSame([90, 60, 30], $chart['datasets'][0]['data']);
        $this->assertSame(['#34d399', '#fbbf24', '#f87171'], $chart['datasets'][0]['backgroundColor']);

        // A long competency name is shortened on the axis, the bar keeps its full figure.
        $this->assertStringStartsWith('Grundlagen der Zellatmung', $chart['labels'][0]);
        $this->assertStringEndsWith('...', $chart['labels'][0]);
        $this->assertSame(['Kurz (Mathematik)', 'Mittel (Mathematik)'], array_slice($chart['labels'], 1));
    }

    public function test_the_student_dashboard_shows_no_statistics(): void
    {
        $this->actingAs($this->students[0])
            ->get('/')
            ->assertOk()
            ->assertSee('Hallo Mia')
            ->assertDontSee('Abgabequote')
            ->assertDontSee('Statistik');
    }

    /**
     * The chart data is only handed to Filament's own view, so the test reads it from the component.
     *
     * @param  class-string<StatisticsChart>  $chart
     * @return array{datasets: array<int, array<string, mixed>>, labels: array<int, string>}
     */
    private function chartData(string $chart, ?string $subject = null, int $weeks = 10): array
    {
        $component = Livewire::actingAs($this->teacher)
            ->test($chart, [
                'schoolClassId' => $this->schoolClass->id,
                'subject' => $subject,
                'weeks' => $weeks,
            ])
            ->instance();

        return (new ReflectionMethod($component, 'getData'))->invoke($component);
    }

    /**
     * @return Collection<int, User>
     */
    private function createStudents(SchoolClass $schoolClass, int $count): Collection
    {
        return collect(['Mia Hoffmann', 'Ben Krüger', 'Ida Peters', 'Tom Bauer'])
            ->take($count)
            ->map(fn (string $name): User => User::factory()->student($schoolClass)->create(['name' => $name]));
    }

    private function publishedLesson(string $subject, string $topic, Carbon $dueOn, ?iterable $students = null): Lesson
    {
        return Lesson::factory()
            ->for($this->schoolClass)
            ->for($this->teacher, 'teacher')
            ->publishedFor($students ?? $this->students)
            ->create(['subject' => $subject, 'topic' => $topic, 'due_on' => $dueOn]);
    }

    private function evaluatedSubmission(Lesson $lesson, User $student, int $score, ?Carbon $createdAt = null): Submission
    {
        $submission = Submission::factory()->for($lesson)->for($student, 'student')->evaluated($score)->create();

        if ($createdAt !== null) {
            $submission->forceFill(['created_at' => $createdAt])->save();
        }

        return $submission;
    }
}
