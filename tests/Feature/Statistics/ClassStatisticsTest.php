<?php

namespace Tests\Feature\Statistics;

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
use App\Support\ClassStatistics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The aggregates behind the statistics page: hand-built factory data with known figures, so every definition of the
 * plan has a number to check. Everything is a class average; groups below the minimum and private data stay out.
 */
class ClassStatisticsTest extends TestCase
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

    public function test_the_kpis_follow_the_definitions(): void
    {
        $statistics = $this->statistics();
        $kpis = $statistics->kpis();

        $this->assertSame(4, $statistics->studentCount());
        $this->assertTrue($statistics->hasEnoughStudents());
        $this->assertSame(['Mathematik'], $statistics->subjects());

        // 6 of 8 assigned hand-ins (75 %), scores 80/60/40/90/70/50 (Ø 65), one of three students below 50 (33 %).
        $this->assertSame(75, $kpis['hand_in_rate']);
        $this->assertSame(65, $kpis['average_understanding']);
        $this->assertSame(33, $kpis['support_share']);
        $this->assertSame(6, $kpis['evaluated_submissions']);
        $this->assertSame(2, $kpis['minutes_saved_per_week']);

        $this->assertSame([
            ['topic' => 'Bruchrechnung', 'due_on' => today()->subDays(3)->toDateString(), 'rate' => 75],
            ['topic' => 'Prozentrechnung', 'due_on' => today()->subDay()->toDateString(), 'rate' => 75],
        ], $statistics->handInRates());
    }

    public function test_the_kpis_stay_empty_without_evaluated_hand_ins(): void
    {
        $emptyClass = SchoolClass::factory()
            ->for(User::factory()->teacher()->for($this->schoolClass->school)->create(), 'teacher')
            ->create(['name' => 'Statistik 9a']);
        $students = $this->createStudents($emptyClass, 3);

        // Open homework: delegated, but the due date has not passed, so there is no hand-in rate yet.
        Lesson::factory()->for($emptyClass)->publishedFor($students)->create([
            'subject' => 'Deutsch',
            'topic' => 'Aufsatzkorrektur',
            'due_on' => today()->addDays(2),
        ]);

        $statistics = ClassStatistics::forViewer($emptyClass->teacher, $emptyClass->id);
        $kpis = $statistics->kpis();

        $this->assertTrue($statistics->hasEnoughStudents());
        $this->assertNull($kpis['hand_in_rate']);
        $this->assertNull($kpis['average_understanding']);
        $this->assertNull($kpis['support_share']);
        $this->assertSame(0, $kpis['evaluated_submissions']);
        $this->assertNull($kpis['minutes_saved_per_week']);

        $this->assertSame([
            ['topic' => 'Aufsatzkorrektur', 'due_on' => today()->addDays(2)->toDateString(), 'rate' => 0],
        ], $statistics->handInRates());
    }

    public function test_groups_below_the_minimum_are_suppressed(): void
    {
        $smallClass = SchoolClass::factory()
            ->for(User::factory()->teacher()->for($this->schoolClass->school)->create(), 'teacher')
            ->create(['name' => 'Statistik 5a']);
        $smallTeacher = $smallClass->teacher;
        $students = $this->createStudents($smallClass, 2);
        $competency = Competency::factory()->create(['subject' => 'Mathematik', 'name' => 'Kopfrechnen']);

        $lesson = Lesson::factory()->for($smallClass)->for($smallTeacher, 'teacher')->publishedFor($students)
            ->create(['subject' => 'Mathematik', 'topic' => 'Kopfrechnen', 'due_on' => today()->subDay()]);

        foreach ($students as $index => $student) {
            $submission = $this->evaluatedSubmission($lesson, $student, 40);
            $this->classifiedAnswer($submission, $lesson, $competency, 40, $index === 0 ? MistakeType::Concept : MistakeType::Procedure);
        }

        $statistics = ClassStatistics::forViewer($smallTeacher, $smallClass->id);

        $this->assertSame(2, $statistics->studentCount());
        $this->assertFalse($statistics->hasEnoughStudents());

        // Every figure is withheld at the data level: two students are no group, one of them would be recognisable.
        $this->assertNull($statistics->kpis()['hand_in_rate']);
        $this->assertNull($statistics->kpis()['average_understanding']);
        $this->assertNull($statistics->kpis()['support_share']);
        $this->assertNull($statistics->kpis()['minutes_saved_per_week']);
        $this->assertSame(0, $statistics->kpis()['evaluated_submissions']);

        $this->assertNull($statistics->understandingTrend()['KW '.today()->isoWeek]);
        $this->assertSame([], $statistics->competencyAverages());
        $this->assertSame([], $statistics->mistakeTypes());
        $this->assertSame([], $statistics->handInRates());
    }

    public function test_figures_stay_hidden_while_too_few_students_contribute(): void
    {
        $historyLesson = $this->publishedLesson('Geschichte', 'Französische Revolution', today()->subDay(), $this->students->take(2));
        $this->evaluatedSubmission($historyLesson, $this->students[0], 80);
        $this->evaluatedSubmission($historyLesson, $this->students[1], 80);

        $biologyLesson = $this->publishedLesson('Biologie', 'Fotosynthese', today()->subDay(), $this->students->take(3));

        foreach ($this->students->take(3) as $student) {
            $this->evaluatedSubmission($biologyLesson, $student, 60);
        }

        // A subject filter whose homework reached only two students: no rate, no average, no time, no count.
        $history = $this->statistics('Geschichte');
        $this->assertNull($history->kpis()['hand_in_rate']);
        $this->assertNull($history->kpis()['average_understanding']);
        $this->assertNull($history->kpis()['support_share']);
        $this->assertNull($history->kpis()['minutes_saved_per_week']);
        $this->assertSame(0, $history->kpis()['evaluated_submissions']);

        // Positive control: three students are a group, so the figures appear (3 of 3 handed in, Ø 60, 3 × 3 / 10 → 1).
        $biology = $this->statistics('Biologie');
        $this->assertSame(100, $biology->kpis()['hand_in_rate']);
        $this->assertSame(60, $biology->kpis()['average_understanding']);
        $this->assertSame(3, $biology->kpis()['evaluated_submissions']);
        $this->assertSame(1, $biology->kpis()['minutes_saved_per_week']);
    }

    public function test_hand_ins_without_an_understanding_score_are_no_support_need(): void
    {
        // Positive control: one of three students lies below the support threshold (1 / 3 = 33 %).
        $this->assertSame(33, $this->statistics()->kpis()['support_share']);

        $lesson = $this->publishedLesson('Mathematik', 'Kopfrechnen', today()->subDay());
        Submission::factory()->for($lesson)->for($this->students[3], 'student')
            ->create(['understanding_score' => null, 'evaluated_at' => now()]);

        // Without a score the hand-in says nothing about the student, so it is no fourth support need.
        $this->assertSame(33, $this->statistics()->kpis()['support_share']);
    }

    public function test_the_trend_lists_every_week_of_the_period_and_hides_small_weeks(): void
    {
        $olderLesson = $this->publishedLesson('Mathematik', 'Zweisatz', today()->subWeeks(2), $this->students->take(3));
        $this->evaluatedSubmission($olderLesson, $this->students[0], 40, today()->subWeeks(2));
        $this->evaluatedSubmission($olderLesson, $this->students[1], 50, today()->subWeeks(2));
        $this->evaluatedSubmission($olderLesson, $this->students[2], 60, today()->subWeeks(2));

        $smallLesson = $this->publishedLesson('Mathematik', 'Dreisatz', today()->subWeek(), $this->students->take(2));
        $this->evaluatedSubmission($smallLesson, $this->students[0], 70, today()->subWeek());
        $this->evaluatedSubmission($smallLesson, $this->students[1], 80, today()->subWeek());

        $trend = $this->statistics()->understandingTrend();

        $this->assertCount(11, $trend);
        $this->assertSame('KW '.today()->subWeeks(10)->startOfWeek()->isoWeek, array_key_first($trend));
        $this->assertSame('KW '.today()->isoWeek, array_key_last($trend));

        // This week holds the six hand-ins of the setup, the week two weeks ago three students with Ø 50.
        $this->assertSame(65, $trend['KW '.today()->isoWeek]);
        $this->assertSame(50, $trend['KW '.today()->subWeeks(2)->isoWeek]);

        // Two students are below the minimum, a week without hand-ins has no value at all.
        $this->assertNull($trend['KW '.today()->subWeek()->isoWeek]);
        $this->assertNull($trend['KW '.today()->subWeeks(5)->isoWeek]);
    }

    public function test_the_subject_filter_limits_every_figure(): void
    {
        $biologyLesson = $this->publishedLesson('Biologie', 'Fotosynthese', today()->subDay());

        foreach ($this->students->take(3) as $student) {
            $this->evaluatedSubmission($biologyLesson, $student, 80);
        }

        $this->assertSame(['Biologie', 'Mathematik'], $this->statistics()->subjects());

        $mathematics = $this->statistics('Mathematik');
        $this->assertSame(65, $mathematics->kpis()['average_understanding']);
        $this->assertSame(6, $mathematics->kpis()['evaluated_submissions']);

        $biology = $this->statistics('Biologie');
        $this->assertSame(80, $biology->kpis()['average_understanding']);
        $this->assertSame(3, $biology->kpis()['evaluated_submissions']);
        $this->assertSame(75, $biology->kpis()['hand_in_rate']);

        // All subjects together: 3 hand-ins more, Ø over both subjects.
        $allSubjects = $this->statistics();
        $this->assertSame(70, $allSubjects->kpis()['average_understanding']);
        $this->assertSame(9, $allSubjects->kpis()['evaluated_submissions']);
    }

    public function test_competencies_of_the_same_name_in_two_subjects_stay_apart(): void
    {
        $biologyCompetency = Competency::factory()->create(['subject' => 'Biologie', 'name' => 'Sachkompetenz']);
        $historyCompetency = Competency::factory()->create(['subject' => 'Geschichte', 'name' => 'Sachkompetenz']);
        $generalCompetency = Competency::factory()->create(['subject' => null, 'name' => 'Methodenkompetenz']);

        $biologyLesson = $this->publishedLesson('Biologie', 'Fotosynthese', today()->subDay(), $this->students->take(3));
        $historyLesson = $this->publishedLesson('Geschichte', 'Revolution', today()->subDay(), $this->students->take(3));

        foreach ($this->students->take(3) as $student) {
            $biologySubmission = $this->evaluatedSubmission($biologyLesson, $student, 80);
            $this->classifiedAnswer($biologySubmission, $biologyLesson, $biologyCompetency, 80, MistakeType::Concept);
            $this->classifiedAnswer($biologySubmission, $biologyLesson, $generalCompetency, 60, MistakeType::Concept);

            $historySubmission = $this->evaluatedSubmission($historyLesson, $student, 40);
            $this->classifiedAnswer($historySubmission, $historyLesson, $historyCompetency, 40, MistakeType::Concept);
        }

        $this->assertSame([
            'Methodenkompetenz (fachübergreifend)' => 60,
            'Sachkompetenz (Biologie)' => 80,
            'Sachkompetenz (Geschichte)' => 40,
        ], $this->statistics()->competencyAverages());

        // With a subject filter the subject is the same for every entry, so it stays off the label.
        $this->assertSame(['Sachkompetenz' => 40], $this->statistics('Geschichte')->competencyAverages());
    }

    public function test_data_outside_the_period_does_not_count_and_an_unknown_period_uses_the_default(): void
    {
        $oldLesson = $this->publishedLesson('Mathematik', 'Wiederholung', today()->subWeeks(6));
        $this->evaluatedSubmission($oldLesson, $this->students[0], 20, today()->subWeeks(6));
        $this->evaluatedSubmission($oldLesson, $this->students[1], 20, today()->subWeeks(6));

        $oldestLesson = $this->publishedLesson('Mathematik', 'Grundlagen', today()->subWeeks(8));
        $this->evaluatedSubmission($oldestLesson, $this->students[2], 100, today()->subWeeks(8));

        // Four weeks: only the six hand-ins of the setup count (390 / 6 = 65).
        $fourWeeks = $this->statistics(weeks: 4);
        $this->assertSame(65, $fourWeeks->kpis()['average_understanding']);
        $this->assertSame(6, $fourWeeks->kpis()['evaluated_submissions']);
        $this->assertSame(75, $fourWeeks->kpis()['hand_in_rate']);

        // Ten weeks: 2 × 20 (6 weeks ago) and 1 × 100 (8 weeks ago) count too → (390 + 40 + 100) / 9 = 58.9 → 59.
        $tenWeeks = $this->statistics();
        $this->assertSame(59, $tenWeeks->kpis()['average_understanding']);
        $this->assertSame(9, $tenWeeks->kpis()['evaluated_submissions']);
        $this->assertSame(56, $tenWeeks->kpis()['hand_in_rate']);

        // 7 weeks is not offered, so the default of 10 weeks applies: the hand-in of eight weeks ago still counts.
        $sevenWeeks = $this->statistics(weeks: 7);
        $this->assertSame($tenWeeks->kpis(), $sevenWeeks->kpis());
        $this->assertNotSame($fourWeeks->kpis(), $tenWeeks->kpis());
    }

    public function test_practice_tasks_count_for_competencies_and_saved_time_only(): void
    {
        $competency = Competency::factory()->create(['subject' => 'Mathematik', 'name' => 'Rechenwege']);
        $practiceLesson = Lesson::factory()->for($this->schoolClass)->for($this->teacher, 'teacher')
            ->publishedFor($this->students->take(3))
            ->create([
                'subject' => 'Mathematik',
                'topic' => 'Förderaufgabe Rechenwege',
                'practice_competency_id' => $competency->id,
                'due_on' => today()->subDays(2),
            ]);

        $scores = [40, 60, 80];

        foreach ($this->students->take(3) as $index => $student) {
            $submission = $this->evaluatedSubmission($practiceLesson, $student, $scores[$index]);
            $this->classifiedAnswer($submission, $practiceLesson, $competency, $scores[$index], MistakeType::cases()[$index]);
        }

        $statistics = $this->statistics();
        $kpis = $statistics->kpis();

        // Hand-in rate, understanding and trend look at ordinary homework only.
        $this->assertSame(75, $kpis['hand_in_rate']);
        $this->assertSame(65, $kpis['average_understanding']);
        $this->assertSame(65, $statistics->understandingTrend()['KW '.today()->isoWeek]);
        $this->assertSame(['Bruchrechnung', 'Prozentrechnung'], array_column($statistics->handInRates(), 'topic'));

        // Competencies, mistake types and the saved time include them: 9 hand-ins, 9 × 3 minutes over 10 weeks.
        $this->assertSame(9, $kpis['evaluated_submissions']);
        $this->assertSame(3, $kpis['minutes_saved_per_week']);
        $this->assertSame(['Rechenwege (Mathematik)' => 60], $statistics->competencyAverages());
        $this->assertSame([
            'Mathematik' => [
                MistakeType::Concept->value => 1,
                MistakeType::Procedure->value => 1,
                MistakeType::Incomplete->value => 1,
                MistakeType::OffTopic->value => 0,
                MistakeType::Empty->value => 0,
            ],
        ], $statistics->mistakeTypes());
    }

    public function test_explanation_sessions_on_free_topics_stay_private(): void
    {
        foreach ($this->students->take(3) as $student) {
            ExplanationSession::factory()->for($student, 'student')->finished(70)->create(['lesson_id' => $this->firstLesson->id]);
        }

        foreach ($this->students->take(2) as $student) {
            ExplanationSession::factory()->for($student, 'student')->finished(70)->create(['lesson_id' => $this->secondLesson->id]);
        }

        // Freely chosen topics have no lesson and belong to the student alone; one of them is even outside the class.
        $foreignLesson = Lesson::factory()->for(SchoolClass::factory())->publishedFor()->create(['subject' => 'Mathematik']);

        foreach ($this->students as $student) {
            ExplanationSession::factory()->for($student, 'student')->finished(70)->create(['lesson_id' => null, 'topic' => 'Mein Hobby']);
            ExplanationSession::factory()->for($student, 'student')->finished(70)->create(['lesson_id' => $foreignLesson->id, 'topic' => 'Fremde Stunde']);
        }

        $firstSessionLastWeek = ExplanationSession::factory()->for($this->students[0], 'student')->finished(70)
            ->create(['lesson_id' => $this->firstLesson->id]);
        $firstSessionLastWeek->forceFill(['created_at' => today()->subWeek()])->save();

        $secondSessionLastWeek = ExplanationSession::factory()->for($this->students[1], 'student')->finished(70)
            ->create(['lesson_id' => $this->secondLesson->id]);
        $secondSessionLastWeek->forceFill(['created_at' => today()->subWeek()])->save();

        $usage = $this->statistics()->explainItUsage();

        $this->assertSame(5, $usage['KW '.today()->isoWeek]);
        $this->assertNull($usage['KW '.today()->subWeek()->isoWeek]);
        $this->assertCount(11, $usage);
    }

    public function test_private_self_assessments_never_change_a_figure(): void
    {
        $competency = Competency::factory()->create(['subject' => 'Mathematik', 'name' => 'Bruchrechnen']);
        $question = Question::factory()->for($this->firstLesson)->for($competency)->create();

        $statistics = $this->statistics();
        $before = [
            $statistics->kpis(),
            $statistics->understandingTrend(),
            $statistics->competencyAverages(),
            $statistics->mistakeTypes(),
            $statistics->handInRates(),
            $statistics->explainItUsage(),
        ];

        foreach ($this->students as $student) {
            ReviewItem::factory()->for($student, 'student')->for($question)->create([
                'knew_count' => 2,
                'hard_count' => 1,
                'forgot_count' => 3,
            ]);
        }

        $after = [
            $statistics->kpis(),
            $statistics->understandingTrend(),
            $statistics->competencyAverages(),
            $statistics->mistakeTypes(),
            $statistics->handInRates(),
            $statistics->explainItUsage(),
        ];

        $this->assertSame($before, $after);
    }

    public function test_statistics_of_another_teachers_class_are_forbidden(): void
    {
        $otherTeacher = User::factory()->teacher()->for($this->schoolClass->school)->create();

        // Positive control: the class teacher sees the figures.
        $this->assertSame(4, $this->statistics()->studentCount());

        try {
            ClassStatistics::forViewer($otherTeacher, $this->schoolClass->id);
            $this->fail('Statistics of another teacher\'s class must be refused.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    private function statistics(?string $subject = null, int $weeks = 10): ClassStatistics
    {
        return ClassStatistics::forViewer($this->teacher, $this->schoolClass->id, $subject, $weeks);
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

    private function classifiedAnswer(Submission $submission, Lesson $lesson, Competency $competency, int $score, MistakeType $mistakeType): Answer
    {
        return Answer::factory()
            ->for($submission)
            ->for(Question::factory()->for($lesson)->for($competency))
            ->mistake($mistakeType)
            ->create(['score' => $score]);
    }
}
