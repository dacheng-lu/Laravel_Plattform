<?php

namespace Tests\Feature\Guardrails;

use App\Filament\Pages\LearningProgress;
use App\Filament\Pages\ParentReport;
use App\Filament\Pages\ReviewToday;
use App\Filament\Pages\WorkOnHomework;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Answer;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\ReviewItem;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\QuestionType;
use App\ReviewRating;
use App\Support\LearningInsights;
use App\Support\PersonalDataExport;
use App\Support\ReviewQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Leitplanken of "Heute wiederholen" (issue #2): practice only, private to the student, nothing across schools,
 * fail closed. Written against the contract (K) and the signatures of lanes A1/A2, so it stays red until those are
 * merged – that is intended.
 *
 * Every negative assertion has its positive control in the same test: the same data is shown where it belongs,
 * so a "not visible" can never come from an empty page.
 */
#[Group('guardrails-review')]
class ReviewGuardrailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_self_assessment_moves_the_box_but_never_a_score(): void
    {
        $schoolClass = SchoolClass::factory()->create(['name' => 'Klasse 7b']);
        $student = User::factory()->student($schoolClass)->create(['name' => 'Mia Hoffmann']);
        $competency = Competency::factory()->create(['subject' => 'Biologie', 'name' => 'Fachwissen anwenden']);

        $lesson = Lesson::factory()->for($schoolClass)->publishedFor([$student])->create([
            'subject' => 'Biologie',
            'topic' => 'Fotosynthese',
            'held_on' => '2026-10-23',
        ]);
        $gradedQuestion = Question::factory()->for($lesson)->create([
            'competency_id' => $competency->id,
            'type' => QuestionType::Comprehension,
            'prompt' => 'Was braucht eine Pflanze für die Fotosynthese?',
            'position' => 1,
        ]);
        $secondQuestion = Question::factory()->for($lesson)->create([
            'type' => QuestionType::Application,
            'prompt' => 'Wie würdest du den Versuch im Dunkeln wiederholen?',
            'position' => 2,
        ]);

        $submission = Submission::factory()->for($lesson)->for($student, 'student')->evaluated(70)->create();
        $gradedAnswer = Answer::factory()->for($submission)->for($gradedQuestion)->create(['score' => 55]);
        $secondAnswer = Answer::factory()->for($submission)->for($secondQuestion)->create(['score' => 62]);

        $knew = ReviewItem::factory()->for($student, 'student')->for($gradedQuestion)->due()->create();
        $forgot = ReviewItem::factory()->for($student, 'student')->for($secondQuestion)->due()->create();

        $averagesBefore = LearningInsights::competencyAverages(LearningInsights::scoredAnswers([$student->id]));

        // Positivkontrolle für den Vergleich unten: der Durchschnitt ist da und nicht leer.
        $this->assertSame([$competency->id => 55], $averagesBefore[$student->id]);

        $this->actingAs($student);

        Livewire::test(ReviewToday::class)
            ->call('reveal')
            ->call('rate', ReviewRating::Knew->value);

        Livewire::test(ReviewToday::class)
            ->call('reveal')
            ->call('rate', ReviewRating::Forgot->value);

        // Positivkontrolle: beide Selbsteinschätzungen wurden wirklich gespeichert.
        $knew->refresh();
        $forgot->refresh();
        $this->assertSame(1, $knew->knew_count);
        $this->assertSame(2, $knew->box);
        $this->assertNotNull($knew->last_reviewed_at);
        $this->assertSame(1, $forgot->forgot_count);
        $this->assertNotNull($forgot->last_reviewed_at);

        // Practice only: no rating ever reaches a score, the understanding score or the Lernstand.
        $this->assertSame(55, $gradedAnswer->refresh()->score);
        $this->assertSame(62, $secondAnswer->refresh()->score);
        $this->assertSame(70, $submission->refresh()->understanding_score);
        $this->assertSame(
            $averagesBefore,
            LearningInsights::competencyAverages(LearningInsights::scoredAnswers([$student->id])),
        );
    }

    public function test_self_assessments_stay_invisible_to_teachers_parents_and_the_administration(): void
    {
        $schoolClass = SchoolClass::factory()->create(['name' => 'Klasse 7b']);
        $teacher = $schoolClass->teacher;
        $admin = User::factory()->admin()->for($schoolClass->school)->create();
        $competency = Competency::factory()->create(['subject' => 'Biologie', 'name' => 'Fachwissen anwenden']);

        // Three students have rated a card today – the scenario in which a class overview would become tempting.
        $students = collect(['Mia Hoffmann', 'Ben Schulz', 'Lena Wagner'])
            ->map(fn (string $name): User => User::factory()->student($schoolClass)->create(['name' => $name]));
        $viewer = $students->first();

        foreach ($students as $student) {
            $lesson = Lesson::factory()->for($schoolClass)->publishedFor([$student])->create([
                'subject' => 'Biologie',
                'topic' => 'Fotosynthese',
                'held_on' => '2026-10-23',
            ]);
            $ratedQuestion = Question::factory()->for($lesson)->create([
                'competency_id' => $competency->id,
                'type' => QuestionType::Comprehension,
                'prompt' => 'Was braucht eine Pflanze für die Fotosynthese?',
                'position' => 1,
            ]);
            $dueQuestion = Question::factory()->for($lesson)->create([
                'competency_id' => $competency->id,
                'type' => QuestionType::Comprehension,
                'prompt' => 'Wozu braucht die Pflanze den Zucker?',
                'position' => 2,
            ]);

            $submission = Submission::factory()->for($lesson)->for($student, 'student')->evaluated(70)->create();
            Answer::factory()->for($submission)->for($ratedQuestion)->create(['score' => 55]);

            ReviewItem::factory()->for($student, 'student')->for($ratedQuestion)
                ->box(2)
                ->due(today()->addDay()->toDateString())
                ->reviewedToday()
                ->create(['knew_count' => 1]);
            ReviewItem::factory()->for($student, 'student')->for($dueQuestion)->due()->create();
        }

        $labels = [
            ReviewRating::Knew->getLabel(),
            ReviewRating::Hard->getLabel(),
            ReviewRating::Forgot->getLabel(),
        ];

        // Positivkontrolle: die Schülerin sieht die Einschätzung auf ihrer Seite.
        $this->actingAs($viewer);
        Livewire::test(ReviewToday::class)
            ->call('reveal')
            ->assertSee($labels);

        // Lernstand der Lehrkraft: die Klasse ist zu sehen, die Selbsteinschätzung nicht.
        Livewire::actingAs($teacher)->test(LearningProgress::class)
            ->assertOk()
            ->assertSee('Mia Hoffmann')
            ->assertDontSee($labels)
            ->assertDontSee('Heute wiederholen');

        // Elternbericht: die Schülerin ist das Thema, ihre Einschätzung nicht.
        $this->actingAs($teacher)
            ->get(ParentReport::getUrl(['student' => $viewer]))
            ->assertOk()
            ->assertSee('Elterngespräch: Mia Hoffmann')
            ->assertDontSee($labels)
            ->assertDontSee('Heute wiederholen');

        // Die eigene Auskunft enthält die Wiederholungsdaten, die Kopie der Verwaltung nicht.
        $own = PersonalDataExport::for($viewer);
        $this->assertArrayHasKey('wiederholen', $own);
        $this->assertArrayHasKey('sofort_gewusst', $own['wiederholen'][0]);
        $this->assertSame(1, collect($own['wiederholen'])->sum('sofort_gewusst'));

        $forAdmin = PersonalDataExport::for($viewer, $admin);
        $this->assertArrayNotHasKey('wiederholen', $forAdmin);
        $this->assertSame('Mia Hoffmann', $forAdmin['konto']['name']);

        $page = Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $viewer->getRouteKey()])
            ->callAction('downloadData');
        $content = base64_decode($page->effects['download']['content']);

        $this->assertStringContainsString('Mia Hoffmann', $content);
        $this->assertStringNotContainsString('"wiederholen"', $content);

        foreach ([...$labels, 'Heute wiederholen'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $content);
        }
    }

    public function test_a_class_change_takes_the_foreign_questions_out_of_the_queue(): void
    {
        $schoolClass = SchoolClass::factory()->create(['name' => 'Klasse 7b']);
        $student = User::factory()->student($schoolClass)->create();

        $lesson = Lesson::factory()->for($schoolClass)->publishedFor([$student])->create([
            'subject' => 'Biologie',
            'topic' => 'Fotosynthese',
            'held_on' => '2026-10-23',
        ]);
        $question = Question::factory()->for($lesson)->create([
            'type' => QuestionType::Comprehension,
            'prompt' => 'Was braucht eine Pflanze für die Fotosynthese?',
            'position' => 1,
        ]);
        $item = ReviewItem::factory()->for($student, 'student')->for($question)->due()->create();

        // Positivkontrolle: vor dem Wechsel ist die Frage da.
        $this->assertSame(1, ReviewQueue::dueCount($student));
        $this->actingAs($student);
        $this->get(ReviewToday::getUrl())->assertOk()->assertSee($question->prompt);

        $otherClass = SchoolClass::factory()->create(['name' => 'Klasse 9c']);
        $student->update(['school_class_id' => $otherClass->id, 'school_id' => $otherClass->school_id]);
        $student->refresh();

        $this->assertSame(0, ReviewQueue::dueCount($student));
        $this->assertTrue(ReviewQueue::dueToday($student)->isEmpty());
        $this->get(ReviewToday::getUrl())
            ->assertOk()
            ->assertDontSee($question->prompt)
            ->assertSee('Für heute ist alles wiederholt');

        // Nur unsichtbar, nie gelöscht: der Eintrag bleibt Teil der Daten der Schülerin.
        $this->assertModelExists($item);
    }

    public function test_a_hand_in_becomes_a_card_the_next_day_but_its_reflection_does_not(): void
    {
        $this->travelTo('2026-10-24 09:00:00');
        config()->set('services.anthropic.enabled', false);

        $schoolClass = SchoolClass::factory()->create(['name' => 'Klasse 7b']);
        $student = User::factory()->student($schoolClass)->create();

        $lesson = Lesson::factory()->for($schoolClass)->publishedFor([$student])->create([
            'subject' => 'Biologie',
            'topic' => 'Fotosynthese',
            'held_on' => '2026-10-23',
        ]);
        $comprehension = Question::factory()->for($lesson)->create([
            'type' => QuestionType::Comprehension,
            'prompt' => 'Was braucht eine Pflanze für die Fotosynthese?',
            'expected_answer' => 'Licht, Wasser und Kohlenstoffdioxid.',
            'position' => 1,
        ]);
        $application = Question::factory()->for($lesson)->create([
            'type' => QuestionType::Application,
            'prompt' => 'Wie würdest du den Versuch im Dunkeln wiederholen?',
            'expected_answer' => 'Ohne Licht entstehen keine Bläschen.',
            'position' => 2,
        ]);
        $reflection = Question::factory()->for($lesson)->create([
            'type' => QuestionType::Reflection,
            'prompt' => 'Welches Experiment aus der Stunde hat dich überrascht?',
            'expected_answer' => 'Individuelle Antwort ohne einzig richtige Lösung.',
            'position' => 3,
        ]);

        $this->actingAs($student);

        Livewire::test(WorkOnHomework::class, ['lesson' => $lesson])
            ->fillForm([
                'summary' => 'Pflanzen bauen mit Lichtenergie, Wasser und Kohlenstoffdioxid Glucose auf. Der grüne Farbstoff Chlorophyll fängt das Licht ein, dabei entsteht Sauerstoff.',
                'answers' => [
                    $comprehension->id => 'Licht, Wasser und Kohlenstoffdioxid.',
                    $application->id => 'Ich würde die Wasserpest in einen dunklen Schrank stellen und die Bläschen zählen.',
                    $reflection->id => 'Der Versuch mit der Wasserpest unter der Lampe hat mich überrascht.',
                ],
            ])
            ->call('submit')
            ->assertHasNoFormErrors()
            ->assertNotified('Geschafft!');

        $items = ReviewItem::query()->where('student_id', $student->id)->get();

        // Positivkontrolle: die beiden lösbaren Fragen sind da, die Reflexionsfrage nicht.
        $this->assertEqualsCanonicalizing([$comprehension->id, $application->id], $items->pluck('question_id')->all());
        $this->assertSame([1], $items->pluck('box')->unique()->values()->all());
        $this->assertSame(
            ['2026-10-25'],
            $items->map(fn (ReviewItem $item): string => $item->due_on->toDateString())->unique()->values()->all(),
        );

        $this->travelTo('2026-10-25 08:00:00');

        $this->assertSame(2, ReviewQueue::dueCount($student));
        $this->assertEqualsCanonicalizing(
            [$comprehension->id, $application->id],
            ReviewQueue::dueToday($student)->pluck('question_id')->all(),
        );
        $this->get(ReviewToday::getUrl())
            ->assertOk()
            ->assertSee($comprehension->prompt)
            ->assertDontSee($reflection->prompt);
    }

    public function test_switching_the_feature_off_creates_no_cards_and_closes_the_page(): void
    {
        config()->set('lernplattform.review.enabled', false);

        $schoolClass = SchoolClass::factory()->create(['name' => 'Klasse 7b']);
        $student = User::factory()->student($schoolClass)->create();

        $this->submitHomework($student, $schoolClass, 'Fotosynthese', 'Was braucht eine Pflanze für die Fotosynthese?');

        $this->assertSame(0, ReviewItem::query()->count());
        $this->assertTrue(Submission::query()->sole()->isEvaluated());

        $this->actingAs($student);
        $this->get(ReviewToday::getUrl())->assertForbidden();

        // Positivkontrolle: mit der Funktion an entsteht der Eintrag und die Seite öffnet sich.
        config()->set('lernplattform.review.enabled', true);

        $this->submitHomework($student, $schoolClass, 'Zellaufbau', 'Wozu braucht die Zelle einen Zellkern?');

        $this->assertSame(1, ReviewItem::query()->count());
        $this->assertSame(2, Submission::query()->count());
        $this->get(ReviewToday::getUrl())->assertOk();
    }

    /**
     * Hands in one homework of the class through the real page and the offline assistant.
     */
    private function submitHomework(User $student, SchoolClass $schoolClass, string $topic, string $prompt): void
    {
        config()->set('services.anthropic.enabled', false);

        $lesson = Lesson::factory()->for($schoolClass)->publishedFor([$student])->create([
            'subject' => 'Biologie',
            'topic' => $topic,
            'held_on' => '2026-10-23',
        ]);
        $question = Question::factory()->for($lesson)->create([
            'type' => QuestionType::Comprehension,
            'prompt' => $prompt,
            'expected_answer' => 'Licht, Wasser und Kohlenstoffdioxid.',
            'position' => 1,
        ]);

        $this->actingAs($student);

        Livewire::test(WorkOnHomework::class, ['lesson' => $lesson])
            ->fillForm([
                'summary' => 'Pflanzen bauen mit Lichtenergie, Wasser und Kohlenstoffdioxid Glucose auf. Der grüne Farbstoff Chlorophyll fängt das Licht ein, dabei entsteht Sauerstoff.',
                'answers' => [$question->id => 'Licht, Wasser und Kohlenstoffdioxid.'],
            ])
            ->call('submit')
            ->assertHasNoFormErrors()
            ->assertNotified('Geschafft!');
    }
}
