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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    private Answer $plainAnswer;

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

        // An answer nobody classified yet: saving the form unchanged must not turn it into a teacher's own order.
        $this->plainAnswer = Answer::factory()
            ->for($this->submission)
            ->for(Question::factory()->for($this->lesson)->create(['prompt' => 'Was bleibt beim Kürzen gleich?', 'competency_id' => $competency->id]))
            ->create(['answer' => 'Der Wert der Zahl bleibt gleich.', 'score' => 50]);

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
                // A real save carries every answer of the form: the unchanged ones keep their current value.
                'mistakes' => [
                    $this->answer->getKey() => MistakeType::Procedure->value,
                    $this->otherAnswer->getKey() => MistakeType::Procedure->value,
                    $this->plainAnswer->getKey() => '',
                ],
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

        // The unchanged answers of the same save keep the AI's classification, and an answer outside this submission
        // is not part of the form at all.
        $this->otherAnswer->refresh();
        $this->assertSame(MistakeType::Procedure, $this->otherAnswer->mistake_type);
        $this->assertSame(MistakeSource::Demo, $this->otherAnswer->mistake_source);
        $this->assertSame(65, $this->otherAnswer->mistake_confidence);

        $this->outsideAnswer->refresh();
        $this->assertSame(MistakeType::Concept, $this->outsideAnswer->mistake_type);
        $this->assertSame(MistakeSource::Demo, $this->outsideAnswer->mistake_source);

        // After the correction the view names the teacher as the source instead of an AI hint.
        Livewire::actingAs($this->teacher)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->mountAction(TestAction::make(ViewAction::class)->table($this->submission))
            ->assertMountedActionModalSee(['Weg/Schritt falsch', 'Von der Lehrkraft eingeordnet']);

        // Clearing the type keeps the teacher's decision recognisable and still touches no score. The empty option is
        // dehydrated to null, so it has to count as "no mistake type".
        Livewire::actingAs($this->teacher)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->callAction(TestAction::make('correctMistakes')->table($this->submission), data: [
                'mistakes' => [
                    $this->answer->getKey() => '',
                    $this->otherAnswer->getKey() => MistakeType::Procedure->value,
                    $this->plainAnswer->getKey() => '',
                ],
            ]);

        $this->answer->refresh();
        $this->assertNull($this->answer->mistake_type);
        $this->assertSame(MistakeSource::Teacher, $this->answer->mistake_source);
        $this->assertSame(30, $this->answer->score);
    }

    public function test_an_unchanged_form_leaves_the_ai_classification_alone(): void
    {
        $action = TestAction::make('correctMistakes')->table($this->submission);

        // Saving without a change: every select carries its current value, the unclassified one stays empty.
        Livewire::actingAs($this->teacher)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->callAction($action, data: [
                'mistakes' => [
                    $this->answer->getKey() => MistakeType::Concept->value,
                    $this->otherAnswer->getKey() => MistakeType::Procedure->value,
                    $this->plainAnswer->getKey() => '',
                ],
            ])
            ->assertNotified('Fehlerarten gespeichert');

        // Fehlerpfad: ein unverändert gespeichertes Formular macht aus KI-Hinweisen keine Lehrkraft-Entscheidungen –
        // weder Typ noch Quelle noch Konfidenz ändern sich.
        $this->answer->refresh();
        $this->assertSame(MistakeType::Concept, $this->answer->mistake_type);
        $this->assertSame(MistakeSource::Demo, $this->answer->mistake_source);
        $this->assertSame(72, $this->answer->mistake_confidence);

        $this->otherAnswer->refresh();
        $this->assertSame(MistakeType::Procedure, $this->otherAnswer->mistake_type);
        $this->assertSame(MistakeSource::Demo, $this->otherAnswer->mistake_source);
        $this->assertSame(65, $this->otherAnswer->mistake_confidence);

        // Die Antwort ohne Einordnung bleibt ohne Einordnung und ohne Quelle.
        $this->plainAnswer->refresh();
        $this->assertNull($this->plainAnswer->mistake_type);
        $this->assertNull($this->plainAnswer->mistake_source);

        // Eine Antwort einer anderen Abgabe steht in keinem Formular dieser Abgabe und bleibt unberührt.
        $this->outsideAnswer->refresh();
        $this->assertSame(MistakeType::Concept, $this->outsideAnswer->mistake_type);
        $this->assertSame(MistakeSource::Demo, $this->outsideAnswer->mistake_source);
        $this->assertSame(70, $this->outsideAnswer->mistake_confidence);

        // Positivkontrolle derselben Aktion: eine echte Änderung wird gespeichert, die unveränderte daneben nicht.
        Livewire::actingAs($this->teacher)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->callAction($action, data: [
                'mistakes' => [
                    $this->answer->getKey() => MistakeType::Concept->value,
                    $this->otherAnswer->getKey() => MistakeType::Concept->value,
                    $this->plainAnswer->getKey() => '',
                ],
            ]);

        $this->otherAnswer->refresh();
        $this->assertSame(MistakeType::Concept, $this->otherAnswer->mistake_type);
        $this->assertSame(MistakeSource::Teacher, $this->otherAnswer->mistake_source);
        $this->assertNull($this->otherAnswer->mistake_confidence);

        $this->answer->refresh();
        $this->assertSame(MistakeSource::Demo, $this->answer->mistake_source);
        $this->assertSame(72, $this->answer->mistake_confidence);
    }

    public function test_the_teacher_can_withdraw_the_mistake_type(): void
    {
        // Positivkontrolle: before the correction the classified answer is marked as an AI hint.
        Livewire::actingAs($this->teacher)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->mountAction(TestAction::make(ViewAction::class)->table($this->submission))
            ->assertMountedActionModalSee(['Grundidee noch unklar', 'KI-Hinweis, kann falsch sein']);

        // The empty option "Keine Fehlerart" is dehydrated to null: it withdraws the AI's type.
        Livewire::actingAs($this->teacher)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->callAction(TestAction::make('correctMistakes')->table($this->submission), data: [
                'mistakes' => [
                    $this->answer->getKey() => '',
                    $this->otherAnswer->getKey() => MistakeType::Procedure->value,
                    $this->plainAnswer->getKey() => '',
                ],
            ]);

        $this->answer->refresh();
        $this->assertNull($this->answer->mistake_type);
        $this->assertSame(MistakeSource::Teacher, $this->answer->mistake_source);
        $this->assertNull($this->answer->mistake_confidence);

        // The unchanged answer next to it keeps the AI's classification.
        $this->otherAnswer->refresh();
        $this->assertSame(MistakeType::Procedure, $this->otherAnswer->mistake_type);
        $this->assertSame(MistakeSource::Demo, $this->otherAnswer->mistake_source);

        // A withdrawn type is the teacher's decision, so it is not presented as an AI hint.
        Livewire::actingAs($this->teacher)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->mountAction(TestAction::make(ViewAction::class)->table($this->submission))
            ->assertMountedActionModalSee(['Von der Lehrkraft: keine Fehlerart']);
    }

    public function test_unchanged_answers_without_a_type_stay_without_a_teacher_source(): void
    {
        // Vorkontrolle: the answer really has no classification of its own.
        $this->assertNull($this->plainAnswer->mistake_type);
        $this->assertNull($this->plainAnswer->mistake_source);

        // The form offers every answer of the submission and names the threshold the AI obeys (the teacher's own order
        // has priority anyway), so nobody has to guess why an answer was classified at all.
        config()->set('lernplattform.mistakes.threshold', 75);

        Livewire::actingAs($this->teacher)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->mountAction(TestAction::make('correctMistakes')->table($this->submission))
            ->assertMountedActionModalSee(['unter 75 Punkten', 'Fehlerarten speichern']);

        Livewire::actingAs($this->teacher)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->callAction(TestAction::make('correctMistakes')->table($this->submission), data: [
                'mistakes' => [
                    // Unchanged: the answer had no type before and gets none back.
                    $this->plainAnswer->getKey() => '',
                    // Chosen as before: this answer keeps its AI hint, too.
                    $this->answer->getKey() => MistakeType::Concept->value,
                    // Changed in the same save, so the action really runs and writes.
                    $this->otherAnswer->getKey() => MistakeType::Concept->value,
                ],
            ])
            ->assertNotified('Fehlerarten gespeichert');

        $this->plainAnswer->refresh();
        $this->assertNull($this->plainAnswer->mistake_type);
        // Fehlerpfad: saving the form unchanged invents no teacher's order and no confidence.
        $this->assertNull($this->plainAnswer->mistake_source);
        $this->assertNull($this->plainAnswer->mistake_confidence);
        $this->assertSame(50, $this->plainAnswer->score);

        // Positivkontrolle im selben Speichervorgang: die geänderte Antwort trägt die Lehrkraft als Quelle.
        $this->otherAnswer->refresh();
        $this->assertSame(MistakeType::Concept, $this->otherAnswer->mistake_type);
        $this->assertSame(MistakeSource::Teacher, $this->otherAnswer->mistake_source);
        $this->assertNull($this->otherAnswer->mistake_confidence);

        // Die unverändert gespeicherte Antwort behält die Einordnung der KI …
        $this->answer->refresh();
        $this->assertSame(MistakeType::Concept, $this->answer->mistake_type);
        $this->assertSame(MistakeSource::Demo, $this->answer->mistake_source);
        $this->assertSame(72, $this->answer->mistake_confidence);

        // … und eine Antwort einer anderen Abgabe steht in diesem Formular gar nicht.
        $this->outsideAnswer->refresh();
        $this->assertSame(MistakeType::Concept, $this->outsideAnswer->mistake_type);
        $this->assertSame(MistakeSource::Demo, $this->outsideAnswer->mistake_source);
        $this->assertSame(70, $this->outsideAnswer->mistake_confidence);
    }

    public function test_correction_authorized(): void
    {
        $admin = User::factory()->admin()->for($this->schoolClass->school)->create();
        $foreignTeacher = User::factory()->teacher()->create();
        $colleague = User::factory()->teacher()->for($this->schoolClass->school)->create();
        $action = TestAction::make('correctMistakes')->table($this->submission);

        // Positivkontrolle: the class teacher and the school's admin may correct …
        $this->assertTrue($this->teacher->can('correctMistake', $this->answer));
        $this->assertTrue($admin->can('correctMistake', $this->answer));

        // … and the teacher is offered the action in the submission view: the Livewire component renders it and mounts it
        // as visible, so the call below reaches the form.
        Livewire::actingAs($this->teacher)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->assertSee('Fehlerart korrigieren')
            ->assertActionVisible($action);

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
            ->callAction($action, data: [
                'mistakes' => [
                    $this->answer->getKey() => MistakeType::Procedure->value,
                    $this->otherAnswer->getKey() => MistakeType::Procedure->value,
                    $this->plainAnswer->getKey() => '',
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

        // A teacher of another school does not get the action at all: it stays hidden, so there is no path to call it.
        $this->assertFalse($foreignTeacher->can('view', $this->lesson));

        $this->assertCorrectionHiddenFor($foreignTeacher);

        $this->assertSame(MistakeType::Procedure, $this->answer->refresh()->mistake_type);
        $this->assertSame(MistakeSource::Teacher, $this->answer->mistake_source);
        $this->assertSame(MistakeType::Procedure, $this->otherAnswer->refresh()->mistake_type);
        $this->assertSame(MistakeSource::Demo, $this->otherAnswer->mistake_source);

        // Fehlerpfad im Livewire-Pfad: the student moved to another school, so the lesson's own teacher may not see
        // them any more – the component hides the action, and the stored state stays as it was.
        $ownClassId = $this->student->school_class_id;
        $ownSchoolId = $this->student->school_id;
        $this->student->update(['school_class_id' => $foreignClass->id, 'school_id' => $foreignClass->school_id]);

        $this->assertFalse($this->teacher->can('correctMistake', $this->answer));

        Livewire::actingAs($this->teacher)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->assertSee('Ansehen')
            ->assertActionHidden($action);

        $this->assertSame(MistakeType::Procedure, $this->answer->refresh()->mistake_type);
        $this->assertSame(MistakeSource::Teacher, $this->answer->mistake_source);
        $this->assertSame(MistakeType::Procedure, $this->otherAnswer->refresh()->mistake_type);
        // The unclassified answer is still unclassified.
        $this->assertNull($this->plainAnswer->refresh()->mistake_type);
        $this->assertNull($this->plainAnswer->mistake_source);

        // Positivkontrolle in derselben Testmethode: back in her class, the responsible teacher stores exactly that change.
        $this->student->update(['school_class_id' => $ownClassId, 'school_id' => $ownSchoolId]);

        Livewire::actingAs($this->teacher)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->assertSee('Fehlerart korrigieren')
            ->callAction($action, data: [
                'mistakes' => [
                    $this->answer->getKey() => MistakeType::OffTopic->value,
                    $this->otherAnswer->getKey() => MistakeType::Procedure->value,
                    $this->plainAnswer->getKey() => '',
                ],
            ]);

        $this->assertSame(MistakeType::OffTopic, $this->answer->refresh()->mistake_type);
        $this->assertSame(MistakeSource::Teacher, $this->answer->mistake_source);
    }

    /**
     * The Livewire path for a user who may not correct: the action is not offered at all, so there is nothing to call.
     * A forged call cannot reach it either – without a visible action Filament has none to mount.
     */
    private function assertCorrectionHiddenFor(User $viewer): void
    {
        Livewire::actingAs($viewer)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->assertActionHidden(TestAction::make('correctMistakes')->table($this->submission));
    }
}
