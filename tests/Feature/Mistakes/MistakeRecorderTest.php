<?php

namespace Tests\Feature\Mistakes;

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
use App\Support\MistakeRecorder;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Livewire\Livewire;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * What reaches the answers: threshold, confidence, in-band values from the evaluation, a teacher's correction and
 * every failure path. Each negative case carries its positive control in the same test.
 */
class MistakeRecorderTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Lesson $lesson;

    /**
     * @var array<string, Question>
     */
    private array $questions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->student()->create();
        $this->lesson = Lesson::factory()->publishedFor([$this->student])->create([
            'summary' => 'Offizielle Zusammenfassung der Lehrkraft über die Fotosynthese.',
        ]);

        $this->questions = [
            'first' => Question::factory()->for($this->lesson)->create([
                'type' => QuestionType::Comprehension,
                'prompt' => 'Was braucht eine Pflanze für die Fotosynthese?',
                'expected_answer' => 'Licht, Wasser und Kohlendioxid, daraus entsteht Zucker und Sauerstoff.',
                'position' => 1,
            ]),
            'second' => Question::factory()->for($this->lesson)->create([
                'type' => QuestionType::Comprehension,
                'prompt' => 'Wozu braucht die Pflanze den Zucker?',
                'expected_answer' => 'Der Zucker ist der Nährstoff der Pflanze, aus ihm wächst sie.',
                'position' => 2,
            ]),
        ];
    }

    public function test_threshold(): void
    {
        config()->set('lernplattform.mistakes.threshold', 80);
        app()->instance(MistakeClassifier::class, $this->alwaysClassifying(
            new MistakeClassification(MistakeType::Procedure, 90, MistakeSource::Demo),
        ));

        $submission = $this->submission(['first' => 80, 'second' => 79]);

        $this->record($submission);

        $atThreshold = $this->answer($submission, 'first');
        $this->assertNull($atThreshold->mistake_type);
        $this->assertNull($atThreshold->mistake_confidence);
        $this->assertNull($atThreshold->mistake_source);

        // Positivkontrolle: one point below the threshold is classified.
        $belowThreshold = $this->answer($submission, 'second');
        $this->assertSame(MistakeType::Procedure, $belowThreshold->mistake_type);
        $this->assertSame(90, $belowThreshold->mistake_confidence);
        $this->assertSame(MistakeSource::Demo, $belowThreshold->mistake_source);
    }

    public function test_min_confidence(): void
    {
        config()->set('lernplattform.mistakes.min_confidence', 0.6);
        app()->instance(MistakeClassifier::class, $this->classifier(
            fn (Question $question, Answer $answer): MistakeClassification => new MistakeClassification(
                MistakeType::Concept,
                $question->is($this->questions['first']) ? 59 : 61,
                MistakeSource::Demo,
            ),
        ));

        $submission = $this->submission(['first' => 50, 'second' => 50]);

        $this->record($submission);

        $unsure = $this->answer($submission, 'first');
        $this->assertNull($unsure->mistake_type);
        $this->assertNull($unsure->mistake_confidence);
        $this->assertNull($unsure->mistake_source);

        // Positivkontrolle: 61 of 100 reaches the configured 0.6.
        $sure = $this->answer($submission, 'second');
        $this->assertSame(MistakeType::Concept, $sure->mistake_type);
        $this->assertSame(61, $sure->mistake_confidence);
        $this->assertSame(MistakeSource::Demo, $sure->mistake_source);
    }

    /**
     * @return array<string, array{Throwable}>
     */
    public static function classifierFailures(): array
    {
        return [
            'Ausnahme des Klassifizierers' => [new RuntimeException('Klassifizierer nicht erreichbar')],
            'Verbindungsfehler des Klassifizierers' => [new ConnectionException('Zeitüberschreitung')],
        ];
    }

    #[DataProvider('classifierFailures')]
    public function test_classifier_failure_fails_closed(Throwable $failure): void
    {
        app()->instance(MistakeClassifier::class, $this->classifier(
            function (Question $question, Answer $answer) use ($failure): ?MistakeClassification {
                if ($question->is($this->questions['first'])) {
                    throw $failure;
                }

                return new MistakeClassification(MistakeType::Incomplete, 80, MistakeSource::Demo);
            },
        ));

        $submission = $this->submission(['first' => 40, 'second' => 40]);

        $this->record($submission);

        $failed = $this->answer($submission, 'first');
        $this->assertNull($failed->mistake_type);
        $this->assertNull($failed->mistake_confidence);
        $this->assertNull($failed->mistake_source);
        $this->assertSame(40, $failed->score);
        $this->assertSame('Licht und Wasser', $failed->answer);

        // Positivkontrolle: the failure of one answer does not stop the next one.
        $this->assertSame(MistakeType::Incomplete, $this->answer($submission, 'second')->mistake_type);
    }

    public function test_classifier_failure_fails_closed_over_work_on_homework(): void
    {
        app()->instance(MistakeClassifier::class, $this->classifier(
            fn (Question $question, Answer $answer): ?MistakeClassification => throw new RuntimeException('Klassifizierer nicht erreichbar'),
        ));

        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->expects('evaluateSubmission')->andReturn([
            'understanding_score' => 62,
            'feedback' => 'Fast: das Chlorophyll fehlt.',
            'gaps' => [],
            'answers' => [
                $this->questions['first']->getKey() => ['score' => 40, 'feedback' => 'Da fehlt noch Wesentliches.'],
                $this->questions['second']->getKey() => ['score' => 84, 'feedback' => 'Richtig erklärt.'],
            ],
        ]));

        $this->actingAs($this->student);

        Livewire::test(WorkOnHomework::class, ['lesson' => $this->lesson])
            ->fillForm([
                'summary' => 'Pflanzen stellen mit Licht, Wasser und Kohlendioxid Zucker her, dabei entsteht Sauerstoff.',
                'answers' => [
                    $this->questions['first']->getKey() => 'Licht und Wasser',
                    $this->questions['second']->getKey() => 'Damit sie wachsen kann und Kraft für die Blüte hat.',
                ],
            ])
            ->call('submit')
            ->assertHasNoFormErrors()
            ->assertNotified('Geschafft!');

        $submission = Submission::query()->sole();

        $this->assertTrue($submission->isEvaluated());
        $this->assertSame(62, $submission->understanding_score);
        $this->assertSame(2, $submission->answers()->count());

        $belowThreshold = $submission->answers()->whereBelongsTo($this->questions['first'])->sole();
        $this->assertSame(40, $belowThreshold->score);
        $this->assertNull($belowThreshold->mistake_type);

        // Positivkontrolle: the scores of both answers were stored, only the hint is missing.
        $this->assertSame(84, $submission->answers()->whereBelongsTo($this->questions['second'])->sole()->score);
    }

    public function test_teacher_correction_wins(): void
    {
        app()->instance(MistakeClassifier::class, $this->alwaysClassifying(
            new MistakeClassification(MistakeType::Procedure, 90, MistakeSource::Demo),
        ));

        $submission = $this->submission(
            ['first' => 50, 'second' => 50],
            [
                'first' => [
                    'mistake_type' => MistakeType::Concept,
                    'mistake_confidence' => null,
                    'mistake_source' => MistakeSource::Teacher,
                ],
                'second' => [
                    'mistake_type' => MistakeType::Concept,
                    'mistake_confidence' => 80,
                    'mistake_source' => MistakeSource::Demo,
                ],
            ],
        );

        $evaluation = ['answers' => [
            $this->questions['first']->getKey() => ['score' => 50, 'feedback' => 'Fast.', 'mistake_type' => 'procedure', 'mistake_confidence' => 90],
            $this->questions['second']->getKey() => ['score' => 50, 'feedback' => 'Fast.', 'mistake_type' => 'procedure', 'mistake_confidence' => 90],
        ]];

        $this->record($submission, $evaluation);
        $this->record($submission->fresh(['answers.question']), $evaluation);

        $corrected = $this->answer($submission, 'first');
        $this->assertSame(MistakeType::Concept, $corrected->mistake_type);
        $this->assertSame(MistakeSource::Teacher, $corrected->mistake_source);
        $this->assertNull($corrected->mistake_confidence);

        // Positivkontrolle: a model's own classification is replaced.
        $reclassified = $this->answer($submission, 'second');
        $this->assertSame(MistakeType::Procedure, $reclassified->mistake_type);
        $this->assertSame(MistakeSource::Claude, $reclassified->mistake_source);
        $this->assertSame(90, $reclassified->mistake_confidence);
    }

    public function test_in_band_classification(): void
    {
        app()->instance(MistakeClassifier::class, $this->alwaysClassifying(
            new MistakeClassification(MistakeType::OffTopic, 70, MistakeSource::Demo),
        ));

        $submission = $this->submission(['first' => 50, 'second' => 50]);

        $this->record($submission, ['answers' => [
            $this->questions['first']->getKey() => ['score' => 50, 'feedback' => 'Fast.', 'mistake_type' => 'procedure', 'mistake_confidence' => 90],
            $this->questions['second']->getKey() => ['score' => 50, 'feedback' => 'Fast.', 'mistake_type' => 'xyz', 'mistake_confidence' => 90],
        ]]);

        $inBand = $this->answer($submission, 'first');
        $this->assertSame(MistakeType::Procedure, $inBand->mistake_type);
        $this->assertSame(90, $inBand->mistake_confidence);
        $this->assertSame(MistakeSource::Claude, $inBand->mistake_source);

        // Positivkontrolle: an unknown type counts as "no classification", so the classifier steps in.
        $invalid = $this->answer($submission, 'second');
        $this->assertSame(MistakeType::OffTopic, $invalid->mistake_type);
        $this->assertSame(MistakeSource::Demo, $invalid->mistake_source);
    }

    public function test_disabled_or_broken_config(): void
    {
        app()->instance(MistakeClassifier::class, $this->alwaysClassifying(
            new MistakeClassification(MistakeType::Procedure, 90, MistakeSource::Demo),
        ));

        config()->set('lernplattform.mistakes.enabled', false);
        $disabled = $this->submission(['first' => 50]);
        $this->record($disabled);
        $this->assertNull($this->answer($disabled, 'first')->mistake_type);

        // Positivkontrolle: switched on, the same answer is classified.
        config()->set('lernplattform.mistakes.enabled', true);
        $enabled = $this->submission(['first' => 50]);
        $this->record($enabled);
        $this->assertSame(MistakeType::Procedure, $this->answer($enabled, 'first')->mistake_type);

        // Fehlerpfad: a minimum outside 0–1 is unusable, so no confidence reaches it.
        config()->set('lernplattform.mistakes.min_confidence', 5.0);
        $broken = $this->submission(['first' => 50]);
        $this->record($broken);
        $this->assertNull($this->answer($broken, 'first')->mistake_type);
        $this->assertNull($this->answer($broken, 'first')->mistake_source);
    }

    public function test_unscored_answers_are_left_alone(): void
    {
        app()->instance(MistakeClassifier::class, $this->alwaysClassifying(
            new MistakeClassification(MistakeType::Procedure, 90, MistakeSource::Demo),
        ));

        $submission = $this->submission(['first' => null, 'second' => 50]);

        $this->record($submission);

        $this->assertNull($this->answer($submission, 'first')->mistake_type);

        // Positivkontrolle: the scored answer of the same submission is classified.
        $this->assertSame(MistakeType::Procedure, $this->answer($submission, 'second')->mistake_type);
    }

    /**
     * @param  array<string, int|null>  $scores  question name => score
     * @param  array<string, array<string, mixed>>  $attributes  question name => extra answer attributes
     */
    private function submission(array $scores, array $attributes = []): Submission
    {
        $submission = Submission::factory()->for($this->lesson)->for($this->student, 'student')->create();

        foreach ($scores as $name => $score) {
            Answer::factory()->for($submission)->for($this->questions[$name])->create([
                'answer' => 'Licht und Wasser',
                'score' => $score,
                ...($attributes[$name] ?? []),
            ]);
        }

        return $submission->fresh(['answers.question']);
    }

    private function answer(Submission $submission, string $name): Answer
    {
        return $submission->answers()->whereBelongsTo($this->questions[$name])->sole();
    }

    /**
     * @param  array<string, mixed>  $evaluation
     */
    private function record(Submission $submission, array $evaluation = ['answers' => []]): void
    {
        app(MistakeRecorder::class)->recordForSubmission($submission, $evaluation);
    }

    /**
     * A classifier that runs the given closure, so every test can say what the model answers.
     *
     * @param  callable(Question, Answer): ?MistakeClassification  $classify
     */
    private function classifier(callable $classify): MistakeClassifier
    {
        $closure = Closure::fromCallable($classify);

        return new class($closure) implements MistakeClassifier
        {
            public function __construct(private readonly Closure $classify) {}

            public function classify(Question $question, Answer $answer): ?MistakeClassification
            {
                return ($this->classify)($question, $answer);
            }
        };
    }

    private function alwaysClassifying(?MistakeClassification $classification): MistakeClassifier
    {
        return $this->classifier(fn (Question $question, Answer $answer): ?MistakeClassification => $classification);
    }
}
