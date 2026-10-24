<?php

namespace Tests\Feature\Mistakes;

use App\Ai\LessonAssistant;
use App\Filament\Pages\LearningProgress;
use App\MistakeSource;
use App\MistakeType;
use App\Models\Answer;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\QuestionType;
use App\Support\MistakeInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\Feature\StudentProgress\CreatesLearningResults;
use Tests\TestCase;

/**
 * A Förderaufgabe starts where the group got stuck: the draft gets the most common mistake type of the students with a
 * support need in that competency (spec AC-9). Without a classified answer it gets none.
 */
class PracticeTaskMistakeTypeTest extends TestCase
{
    use CreatesLearningResults;
    use RefreshDatabase;

    private SchoolClass $schoolClass;

    private User $teacher;

    private User $mia;

    private User $ben;

    private User $lena;

    private Competency $competency;

    private Answer $miaAnswer;

    private Answer $benAnswer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolClass = SchoolClass::factory()->create(['name' => '7b']);
        $this->teacher = $this->schoolClass->teacher;
        $this->mia = User::factory()->student($this->schoolClass)->create(['name' => 'Mia Hoffmann']);
        $this->ben = User::factory()->student($this->schoolClass)->create(['name' => 'Ben Schulz']);
        $this->lena = User::factory()->student($this->schoolClass)->create(['name' => 'Lena Wagner']);

        $this->competency = Competency::factory()->create(['subject' => 'Mathematik', 'area' => 'Zahlen', 'name' => 'Brüche kürzen']);

        $this->miaAnswer = $this->answerFor($this->evaluatedHomework($this->mia, 'Brüche kürzen üben', '2026-10-01'), 30);
        $this->benAnswer = $this->answerFor($this->evaluatedHomework($this->ben, 'Zähler und Nenner', '2026-10-05'), 40);
        // A student above the support threshold: their answers never decide the group's mistake type.
        $this->answerFor($this->evaluatedHomework($this->lena, 'Kürzen am Modell', '2026-10-10'), 90);
    }

    public function test_the_most_common_mistake_type_reaches_the_draft(): void
    {
        $this->classify($this->miaAnswer, MistakeType::Procedure);
        $this->classify($this->answerFor($this->miaAnswer->submission, 25), MistakeType::Procedure);
        $this->classify($this->benAnswer, MistakeType::Concept);

        $received = null;
        $this->mock(LessonAssistant::class, function (MockInterface $mock) use (&$received): void {
            $mock->shouldReceive('draftPracticeTask')
                ->once()
                ->andReturnUsing(function (Competency $competency, string $subject, Collection $sourceLessons, array $gaps, ?MistakeType $mistakeType = null) use (&$received): array {
                    $this->assertTrue($competency->is($this->competency));
                    $received = $mistakeType;

                    return $this->draft();
                });
        });

        Livewire::actingAs($this->teacher)
            ->test(LearningProgress::class)
            ->callAction('createPracticeTask', data: ['students' => [$this->mia->id, $this->ben->id], 'distribute' => false], arguments: ['competency' => $this->competency->id])
            ->assertHasNoActionErrors();

        // Two procedure mistakes against one concept mistake: the group gets the procedure task.
        $this->assertSame(MistakeType::Procedure, $received);
        $this->assertSame(1, Lesson::query()->where('practice_competency_id', $this->competency->id)->count());
    }

    public function test_the_kill_switch_hands_no_mistake_type_to_the_draft(): void
    {
        $this->classify($this->miaAnswer, MistakeType::Procedure);
        $this->classify($this->answerFor($this->miaAnswer->submission, 25), MistakeType::Procedure);
        $this->classify($this->benAnswer, MistakeType::Concept);

        $received = [];
        $this->mock(LessonAssistant::class, function (MockInterface $mock) use (&$received): void {
            $mock->shouldReceive('draftPracticeTask')
                ->twice()
                ->andReturnUsing(function (Competency $competency, string $subject, Collection $sourceLessons, array $gaps, ?MistakeType $mistakeType = null) use (&$received): array {
                    $received[] = $mistakeType;

                    return $this->draft();
                });
        });

        // Positivkontrolle: eingeschaltet bekommt der Entwurf die häufigste Fehlerart der Gruppe.
        config()->set('lernplattform.mistakes.enabled', true);

        Livewire::actingAs($this->teacher)
            ->test(LearningProgress::class)
            ->callAction('createPracticeTask', data: ['students' => [$this->mia->id, $this->ben->id], 'distribute' => false], arguments: ['competency' => $this->competency->id])
            ->assertHasNoActionErrors();

        // Kill-Switch: ausgeschaltet bekommt er keine, obwohl die Antworten eingeordnet gespeichert sind.
        config()->set('lernplattform.mistakes.enabled', false);

        Livewire::actingAs($this->teacher)
            ->test(LearningProgress::class)
            ->callAction('createPracticeTask', data: ['students' => [$this->mia->id, $this->ben->id], 'distribute' => false], arguments: ['competency' => $this->competency->id])
            ->assertHasNoActionErrors();

        $this->assertSame([MistakeType::Procedure, null], $received);
        $this->assertSame(2, Lesson::query()->where('practice_competency_id', $this->competency->id)->count());
    }

    public function test_without_classified_answers_the_draft_gets_no_mistake_type(): void
    {
        $received = MistakeType::Empty;
        $this->mock(LessonAssistant::class, function (MockInterface $mock) use (&$received): void {
            $mock->shouldReceive('draftPracticeTask')
                ->once()
                ->andReturnUsing(function (Competency $competency, string $subject, Collection $sourceLessons, array $gaps, ?MistakeType $mistakeType = null) use (&$received): array {
                    $this->assertTrue($competency->is($this->competency));
                    $received = $mistakeType;

                    return $this->draft();
                });
        });

        Livewire::actingAs($this->teacher)
            ->test(LearningProgress::class)
            ->callAction('createPracticeTask', data: ['students' => [$this->mia->id, $this->ben->id], 'distribute' => false], arguments: ['competency' => $this->competency->id])
            ->assertHasNoActionErrors();

        // Positivkontrolle: the call reached the assistant, but there is nothing to hand over.
        $this->assertNull($received);
        $this->assertSame(1, Lesson::query()->where('practice_competency_id', $this->competency->id)->count());
    }

    public function test_a_tie_keeps_the_order_of_the_enum_cases(): void
    {
        $this->classify($this->miaAnswer, MistakeType::Concept);
        $this->classify($this->benAnswer, MistakeType::Procedure);

        $studentIds = [$this->mia->getKey(), $this->ben->getKey()];
        $lessonIds = [$this->miaAnswer->submission->lesson->getKey(), $this->benAnswer->submission->lesson->getKey()];

        // One mistake each: the first case of the enum wins, so the result never depends on the database's order.
        $this->assertSame(MistakeType::Concept, MistakeInsights::mostCommon($studentIds, $this->competency->getKey(), $lessonIds));

        // Nothing to count is no classification.
        $this->assertNull(MistakeInsights::mostCommon([], $this->competency->getKey(), $lessonIds));
        $this->assertNull(MistakeInsights::mostCommon($studentIds, $this->competency->getKey(), []));
    }

    /**
     * A scored answer on the competency of this class, not classified yet.
     */
    private function answerFor(Submission $submission, int $score): Answer
    {
        return Answer::factory()
            ->for($submission)
            ->for(Question::factory()->for($submission->lesson)->create(['competency_id' => $this->competency->id]))
            ->create(['score' => $score]);
    }

    private function classify(Answer $answer, MistakeType $type): void
    {
        $answer->update([
            'mistake_type' => $type,
            'mistake_confidence' => 80,
            'mistake_source' => MistakeSource::Demo,
        ]);
    }

    /**
     * @return array{summary: string, questions: list<array{type: QuestionType, prompt: string, expected_answer: string, competency_id: int}>}
     */
    private function draft(): array
    {
        return [
            'summary' => 'Übe das Kürzen.',
            'questions' => [['type' => QuestionType::Comprehension, 'prompt' => 'Wie kürzt du 6/8?', 'expected_answer' => '3/4.', 'competency_id' => $this->competency->id]],
        ];
    }
}
