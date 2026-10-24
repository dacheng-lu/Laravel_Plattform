<?php

namespace Tests\Feature\Mistakes;

use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Filament\Resources\Lessons\RelationManagers\SubmissionsRelationManager;
use App\MistakeSource;
use App\MistakeType;
use App\Models\Answer;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Actions\ViewAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/**
 * The teacher may correct the mistake type of an answer in the submission view (spec AC-6). The correction is a hint:
 * score and understanding score stay untouched, and only the answers of that submission can be changed.
 */
class MistakeCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $schoolClass;

    private User $teacher;

    private User $student;

    private Lesson $lesson;

    private Submission $submission;

    private Answer $answer;

    private Answer $otherAnswer;

    private Answer $outsideAnswer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolClass = SchoolClass::factory()->create(['name' => '7b']);
        $this->teacher = $this->schoolClass->teacher;
        $this->student = User::factory()->student($this->schoolClass)->create(['name' => 'Mia Hoffmann']);

        $competency = Competency::factory()->create(['subject' => 'Mathematik', 'name' => 'Brüche kürzen']);

        $this->lesson = Lesson::factory()
            ->for($this->schoolClass)
            ->for($this->teacher, 'teacher')
            ->publishedFor([$this->student])
            ->create(['subject' => 'Mathematik', 'topic' => 'Brüche kürzen üben']);

        $this->submission = Submission::factory()
            ->for($this->lesson)
            ->for($this->student, 'student')
            ->evaluated(60)
            ->create();

        $this->answer = Answer::factory()
            ->for($this->submission)
            ->for(Question::factory()->for($this->lesson)->create(['prompt' => 'Wie kürzt du 6/8?', 'competency_id' => $competency->id]))
            ->mistake(MistakeType::Concept, MistakeSource::Demo, 72)
            ->create(['answer' => '3/4, weil 6 und 8 durch 2 teilbar sind.', 'score' => 30]);

        $this->otherAnswer = Answer::factory()
            ->for($this->submission)
            ->for(Question::factory()->for($this->lesson)->create(['prompt' => 'Warum kürzt man überhaupt?', 'competency_id' => $competency->id]))
            ->mistake(MistakeType::Procedure, MistakeSource::Demo, 65)
            ->create(['answer' => 'Damit die Zahlen kleiner werden.', 'score' => 45]);

        // A second homework of the same student: its answer is never part of this submission's form.
        $outsideLesson = Lesson::factory()
            ->for($this->schoolClass)
            ->for($this->teacher, 'teacher')
            ->publishedFor([$this->student])
            ->create(['subject' => 'Mathematik', 'topic' => 'Brüche wiederholen']);

        $outsideSubmission = Submission::factory()
            ->for($outsideLesson)
            ->for($this->student, 'student')
            ->evaluated(35)
            ->create();

        $this->outsideAnswer = Answer::factory()
            ->for($outsideSubmission)
            ->for(Question::factory()->for($outsideLesson)->create(['competency_id' => $competency->id]))
            ->mistake(MistakeType::Concept, MistakeSource::Demo, 70)
            ->create(['score' => 20]);
    }

    public function test_correction_sets_teacher_source(): void
    {
        // The AI's hint is marked as such in the submission view.
        Livewire::actingAs($this->teacher)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->mountAction(TestAction::make(ViewAction::class)->table($this->submission))
            ->assertMountedActionModalSee(['Grundidee noch unklar', 'KI-Hinweis, kann falsch sein']);

        Livewire::actingAs($this->teacher)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->callAction(TestAction::make('correctMistakes')->table($this->submission), data: [
                'mistakes' => [$this->answer->getKey() => MistakeType::Procedure->value],
            ])
            ->assertNotified('Fehlerarten gespeichert');

        $this->answer->refresh();
        $this->assertSame(MistakeType::Procedure, $this->answer->mistake_type);
        $this->assertSame(MistakeSource::Teacher, $this->answer->mistake_source);
        // The confidence belongs to the AI's classification and is cleared with the teacher's correction.
        $this->assertNull($this->answer->mistake_confidence);
        // Only a hint: neither the answer's score nor the submission's understanding score change.
        $this->assertSame(30, $this->answer->score);
        $this->assertSame(60, $this->submission->refresh()->understanding_score);
        // An answer outside this submission keeps the AI's classification.
        $this->outsideAnswer->refresh();
        $this->assertSame(MistakeType::Concept, $this->outsideAnswer->mistake_type);
        $this->assertSame(MistakeSource::Demo, $this->outsideAnswer->mistake_source);

        // After the correction the view names the teacher as the source instead of an AI hint.
        Livewire::actingAs($this->teacher)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->mountAction(TestAction::make(ViewAction::class)->table($this->submission))
            ->assertMountedActionModalSee(['Weg/Schritt falsch', 'Von der Lehrkraft eingeordnet']);

        // Clearing the type keeps the teacher's decision recognisable and still touches no score.
        Livewire::actingAs($this->teacher)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->callAction(TestAction::make('correctMistakes')->table($this->submission), data: [
                'mistakes' => [$this->answer->getKey() => ''],
            ]);

        $this->answer->refresh();
        $this->assertNull($this->answer->mistake_type);
        $this->assertSame(MistakeSource::Teacher, $this->answer->mistake_source);
        $this->assertSame(30, $this->answer->score);
    }

    public function test_confirming_the_ai_type_makes_it_the_teachers_own_order(): void
    {
        // The teacher keeps the AI's "procedure": the type stays, but the classification is now theirs.
        Livewire::actingAs($this->teacher)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->callAction(TestAction::make('correctMistakes')->table($this->submission), data: [
                'mistakes' => [$this->otherAnswer->getKey() => MistakeType::Procedure->value],
            ]);

        $this->otherAnswer->refresh();
        $this->assertSame(MistakeType::Procedure, $this->otherAnswer->mistake_type);
        $this->assertSame(MistakeSource::Teacher, $this->otherAnswer->mistake_source);
        $this->assertNull($this->otherAnswer->mistake_confidence);
        $this->assertSame(45, $this->otherAnswer->score);

        // Kontrolle: an answer outside this submission is still the AI's classification.
        $this->outsideAnswer->refresh();
        $this->assertSame(MistakeSource::Demo, $this->outsideAnswer->mistake_source);
        $this->assertSame(MistakeType::Concept, $this->outsideAnswer->mistake_type);
        $this->assertSame(70, $this->outsideAnswer->mistake_confidence);
    }

    public function test_the_teacher_can_withdraw_the_mistake_type(): void
    {
        // Positivkontrolle: before the correction the classified answer is marked as an AI hint.
        Livewire::actingAs($this->teacher)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->mountAction(TestAction::make(ViewAction::class)->table($this->submission))
            ->assertMountedActionModalSee(['Grundidee noch unklar', 'KI-Hinweis, kann falsch sein']);

        Livewire::actingAs($this->teacher)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->callAction(TestAction::make('correctMistakes')->table($this->submission), data: [
                'mistakes' => [$this->answer->getKey() => ''],
            ]);

        $this->answer->refresh();
        $this->assertNull($this->answer->mistake_type);
        $this->assertSame(MistakeSource::Teacher, $this->answer->mistake_source);

        // A withdrawn type is the teacher's decision, so it is not presented as an AI hint.
        Livewire::actingAs($this->teacher)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->mountAction(TestAction::make(ViewAction::class)->table($this->submission))
            ->assertMountedActionModalSee(['Von der Lehrkraft: keine Fehlerart']);
    }

    public function test_correction_authorized(): void
    {
        $admin = User::factory()->admin()->for($this->schoolClass->school)->create();
        $foreignTeacher = User::factory()->teacher()->create();
        $colleague = User::factory()->teacher()->for($this->schoolClass->school)->create();

        // Positivkontrolle: the class teacher and the school's admin may correct.
        $this->assertTrue($this->teacher->can('correctMistake', $this->answer));
        $this->assertTrue($admin->can('correctMistake', $this->answer));

        // Another school, a colleague without this lesson, the student and the operator may not.
        foreach ([$foreignTeacher, $colleague, $this->student, User::factory()->operator()->create()] as $viewer) {
            $this->assertFalse($viewer->can('correctMistake', $this->answer));
        }

        // A forged answer id from another submission is ignored: only this submission's answers count.
        $foreignClass = SchoolClass::factory()->create();
        $foreignStudent = User::factory()->student($foreignClass)->create();
        $foreignLesson = Lesson::factory()->for($foreignClass)->publishedFor([$foreignStudent])->create();
        $foreignSubmission = Submission::factory()->for($foreignLesson)->for($foreignStudent, 'student')->evaluated(50)->create();
        $foreignAnswer = Answer::factory()
            ->for($foreignSubmission)
            ->for(Question::factory()->for($foreignLesson)->create())
            ->create(['score' => 15]);

        Livewire::actingAs($this->teacher)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->callAction(TestAction::make('correctMistakes')->table($this->submission), data: [
                'mistakes' => [
                    $this->answer->getKey() => MistakeType::Procedure->value,
                    $foreignAnswer->getKey() => MistakeType::OffTopic->value,
                ],
            ]);

        // Positive control in the same call: the own answer changed, the smuggled one did not.
        $this->assertSame(MistakeType::Procedure, $this->answer->refresh()->mistake_type);
        $this->assertSame(MistakeSource::Teacher, $this->answer->mistake_source);

        $foreignAnswer->refresh();
        $this->assertNull($foreignAnswer->mistake_type);
        $this->assertNull($foreignAnswer->mistake_source);
        $this->assertSame(15, $foreignAnswer->score);

        // A teacher of another school really executes the action: it is refused and writes nothing.
        try {
            Livewire::actingAs($foreignTeacher)
                ->test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
                ->callAction(TestAction::make('correctMistakes')->table($this->submission), data: [
                    'mistakes' => [$this->answer->getKey() => MistakeType::OffTopic->value],
                ])
                ->assertForbidden();
        } catch (AuthorizationException|HttpExceptionInterface $denied) {
            // Livewire may surface the refusal itself instead of a 403 response: either way it is a 403.
            if ($denied instanceof HttpExceptionInterface) {
                $this->assertSame(403, $denied->getStatusCode());
            }
        }

        $this->answer->refresh();
        $this->assertSame(MistakeType::Procedure, $this->answer->mistake_type);
        $this->assertSame(MistakeSource::Teacher, $this->answer->mistake_source);

        // Positivkontrolle in derselben Testmethode: the responsible teacher stores exactly that change.
        Livewire::actingAs($this->teacher)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->callAction(TestAction::make('correctMistakes')->table($this->submission), data: [
                'mistakes' => [$this->answer->getKey() => MistakeType::OffTopic->value],
            ]);

        $this->assertSame(MistakeType::OffTopic, $this->answer->refresh()->mistake_type);
        $this->assertSame(MistakeSource::Teacher, $this->answer->mistake_source);
    }
}
