<?php

namespace Tests\Feature\Contract;

use App\Ai\ClaudeMistakeClassifier;
use App\Ai\DemoMistakeClassifier;
use App\Ai\LessonAssistant;
use App\Ai\MistakeClassification;
use App\Ai\MistakeClassifier;
use App\Filament\Pages\WorkOnHomework;
use App\MistakeSource;
use App\MistakeType;
use App\Models\Answer;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\Submission;
use App\Models\User;
use App\QuestionType;
use App\ReviewRating;
use App\Support\MistakeRecorder;
use App\Support\ReviewScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * The contract both follow-up lanes build on: schema, enums, the fail-closed paths and the hook in WorkOnHomework.
 * Everything here must hold before issues #2 and #3 are built in parallel.
 */
class ContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_has_review_and_mistake_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('review_items', [
            'id',
            'student_id',
            'question_id',
            'box',
            'due_on',
            'last_reviewed_at',
            'knew_count',
            'hard_count',
            'forgot_count',
            'created_at',
            'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('answers', ['mistake_type', 'mistake_confidence', 'mistake_source']));
    }

    public function test_enums_match_the_specs(): void
    {
        $this->assertSame([
            'knew' => 'Sofort gewusst',
            'hard' => 'Mit Mühe',
            'forgot' => 'Nicht gewusst',
        ], collect(ReviewRating::cases())
            ->mapWithKeys(fn (ReviewRating $rating): array => [$rating->value => $rating->getLabel()])
            ->all());

        $this->assertCount(5, MistakeType::cases());

        $this->assertSame([
            'concept' => ['Grundidee noch unklar', 'Hier lohnt es sich, die Grundidee noch einmal anzuschauen.'],
            'procedure' => ['Weg/Schritt falsch', 'Du hast richtig angesetzt, ein Schritt ist noch nicht ganz richtig.'],
            'incomplete' => ['Unvollständig', 'Guter Anfang! Es fehlt noch ein Teil der Antwort.'],
            'off_topic' => ['Am Thema vorbei', 'Deine Antwort passt zu einer anderen Frage. Lies die Frage noch einmal genau.'],
            'empty' => ['Kein Versuch', 'Probier es ruhig: Auch eine unvollständige Antwort hilft dir weiter.'],
        ], collect(MistakeType::cases())
            ->mapWithKeys(fn (MistakeType $type): array => [$type->value => [$type->getLabel(), $type->feedbackHint()]])
            ->all());

        $this->assertSame([
            'demo' => 'Demo-Regeln',
            'claude' => 'KI (Claude)',
            'clef' => 'KI (lokal)',
            'teacher' => 'Lehrkraft',
        ], collect(MistakeSource::cases())
            ->mapWithKeys(fn (MistakeSource $source): array => [$source->value => $source->getLabel()])
            ->all());

        foreach (MistakeSource::cases() as $source) {
            $this->assertSame($source !== MistakeSource::Teacher, $source->isAi());
        }
    }

    public function test_classification_parsing_fails_closed(): void
    {
        $implausible = [
            'unbekannte Fehlerart' => ['mistake_type' => 'foo', 'mistake_confidence' => 70],
            'Konfidenz über 100' => ['mistake_type' => 'procedure', 'mistake_confidence' => 101],
            'negative Konfidenz' => ['mistake_type' => 'procedure', 'mistake_confidence' => -1],
            'Konfidenz als Zeichenkette' => ['mistake_type' => 'procedure', 'mistake_confidence' => '80'],
            'Konfidenz als Kommazahl' => ['mistake_type' => 'procedure', 'mistake_confidence' => 0.8],
            'Konfidenz fehlt' => ['mistake_type' => 'procedure'],
            'Fehlerart fehlt' => ['mistake_confidence' => 70],
            'beides fehlt' => [],
        ];

        foreach ($implausible as $case => $result) {
            $this->assertNull(MistakeClassification::fromEvaluation($result, MistakeSource::Claude), $case);
        }

        // Positivkontrolle: the strict parsing above must not simply reject everything.
        $classification = MistakeClassification::fromEvaluation(
            ['mistake_type' => 'procedure', 'mistake_confidence' => 70],
            MistakeSource::Claude,
        );

        $this->assertInstanceOf(MistakeClassification::class, $classification);
        $this->assertSame(MistakeType::Procedure, $classification->type);
        $this->assertSame(70, $classification->confidence);
        $this->assertSame(MistakeSource::Claude, $classification->source);
    }

    public function test_follow_ups_fail_closed(): void
    {
        $student = User::factory()->student()->create();
        $lesson = Lesson::factory()->publishedFor([$student])->create();
        $question = Question::factory()->for($lesson)->create([
            'type' => QuestionType::Comprehension,
            'prompt' => 'Was braucht eine Pflanze für die Fotosynthese?',
            'position' => 1,
        ]);

        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->expects('evaluateSubmission')->andReturn([
            'understanding_score' => 82,
            'feedback' => 'Du hast die Fotosynthese gut erklärt.',
            'gaps' => [],
            'answers' => [$question->id => ['score' => 55, 'feedback' => 'Fast: das Chlorophyll fehlt.']],
        ]));
        $this->mock(MistakeRecorder::class, fn (MockInterface $mock) => $mock->expects('recordForSubmission')
            ->once()
            ->andThrow(new RuntimeException('Fehlerart-Speicherung fehlgeschlagen')));
        $this->mock(ReviewScheduler::class, fn (MockInterface $mock) => $mock->expects('scheduleForSubmission')
            ->once()
            ->andThrow(new RuntimeException('Wiederholungsplanung fehlgeschlagen')));

        $this->actingAs($student);

        Livewire::test(WorkOnHomework::class, ['lesson' => $lesson])
            ->fillForm([
                'summary' => 'Pflanzen stellen mit Licht, Wasser und Kohlendioxid Zucker her, dabei entsteht Sauerstoff.',
                'answers' => [$question->id => 'Licht und Wasser'],
            ])
            ->call('submit')
            ->assertHasNoFormErrors()
            ->assertNotified('Geschafft!');

        $submission = Submission::query()->sole();
        $this->assertTrue($submission->isEvaluated());
        $this->assertSame(82, $submission->understanding_score);
        $this->assertSame(55, $submission->answers()->sole()->score);
    }

    public function test_classifier_binding(): void
    {
        config()->set('services.anthropic.enabled', false);
        $this->assertInstanceOf(DemoMistakeClassifier::class, $this->app->make(MistakeClassifier::class));

        config()->set('services.anthropic.enabled', true);
        $this->assertInstanceOf(ClaudeMistakeClassifier::class, $this->app->make(MistakeClassifier::class));
    }

    public function test_hint_shown_only_with_mistake_type(): void
    {
        $student = User::factory()->student()->create();
        $lesson = Lesson::factory()->publishedFor([$student])->create();
        $classified = Question::factory()->for($lesson)->create([
            'type' => QuestionType::Comprehension,
            'prompt' => 'Was braucht eine Pflanze für die Fotosynthese?',
            'position' => 1,
        ]);
        $unclassified = Question::factory()->for($lesson)->create([
            'type' => QuestionType::Comprehension,
            'prompt' => 'Wozu braucht die Pflanze den Zucker?',
            'position' => 2,
        ]);

        $submission = Submission::factory()->for($lesson)->for($student, 'student')->evaluated(60)->create();
        Answer::factory()->for($submission)->for($classified)->mistake(MistakeType::Procedure)->create([
            'answer' => 'Licht und Wasser reichen schon.',
            'score' => 55,
        ]);
        Answer::factory()->for($submission)->for($unclassified)->create([
            'answer' => 'Damit sie wachsen kann.',
            'score' => 55,
        ]);

        $this->actingAs($student);

        $response = $this->get(WorkOnHomework::getUrl(['lesson' => $lesson]));

        $hint = 'Du hast richtig angesetzt, ein Schritt ist noch nicht ganz richtig.';

        $response->assertOk()
            ->assertSee($hint)
            ->assertSee(['Licht und Wasser reichen schon.', 'Damit sie wachsen kann.']);

        // Positivkontrolle: exactly one of the two answers carries a type, so the hint appears exactly once.
        $content = (string) $response->getContent();
        $this->assertSame(1, substr_count($content, $hint));
        $this->assertStringNotContainsString(MistakeType::Concept->feedbackHint(), $content);
    }
}
