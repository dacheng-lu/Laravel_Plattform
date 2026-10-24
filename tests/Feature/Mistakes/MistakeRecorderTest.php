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
use Illuminate\Support\Facades\DB;
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

    public function test_the_rules_never_call_a_reflection_answer_off_topic(): void
    {
        config()->set('lernplattform.mistakes.threshold', 80);
        config()->set('lernplattform.mistakes.min_confidence', 0.6);

        // The real offline rules decide here, not a mock: an answer that shares no word with the question.
        $reflection = Question::factory()->for($this->lesson)->create([
            'type' => QuestionType::Reflection,
            'prompt' => 'Was hat dich am Versuch überrascht?',
            'expected_answer' => 'Eine eigene Beobachtung, zum Beispiel die Bläschen am Stängel.',
            'position' => 3,
        ]);

        $submission = Submission::factory()->for($this->lesson)->for($this->student, 'student')->create();

        $reflectionAnswer = Answer::factory()->for($submission)->for($reflection)->create([
            'answer' => 'Die Bläschen am Stängel waren für mich neu.',
            'score' => 40,
        ]);

        // Positivkontrolle: dieselbe Antwort auf eine Verständnisfrage ist „am Thema vorbei".
        $comprehensionAnswer = Answer::factory()->for($submission)->for($this->questions['first'])->create([
            'answer' => 'Die Bläschen am Stängel waren für mich neu.',
            'score' => 40,
        ]);

        $this->record($submission->fresh(['answers.question']));

        // Eine Reflexionsfrage hat keine einzig richtige Antwort: die Regeln sagen hier nichts.
        $this->assertNull($reflectionAnswer->refresh()->mistake_type);
        $this->assertNull($reflectionAnswer->refresh()->mistake_source);

        $this->assertSame(MistakeType::OffTopic, $comprehensionAnswer->refresh()->mistake_type);
        $this->assertSame(MistakeSource::Demo, $comprehensionAnswer->refresh()->mistake_source);
        $this->assertSame(65, $comprehensionAnswer->refresh()->mistake_confidence);
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

    public function test_a_failing_cleanup_does_not_stop_the_next_answer(): void
    {
        app()->instance(MistakeClassifier::class, $this->classifier(
            function (Question $question, Answer $answer): ?MistakeClassification {
                if ($question->is($this->questions['first'])) {
                    throw new RuntimeException('Klassifizierer nicht erreichbar');
                }

                return new MistakeClassification(MistakeType::Incomplete, 80, MistakeSource::Demo);
            },
        ));

        $submission = $this->submission(['first' => 40, 'second' => 40]);
        $failing = $this->answer($submission, 'first');

        // A value no case knows, as a broken row from an older version would have it: reading it while cleaning up
        // throws. Nothing global is touched, so no listener has to be removed afterwards.
        DB::table('answers')->where('id', $failing->getKey())->update(['mistake_type' => 'unbekannt']);

        $this->record($submission);

        // Fehlerpfad: the cleanup failed, so the unusable value is still there and the loop went on.
        $this->assertSame('unbekannt', DB::table('answers')->where('id', $failing->getKey())->value('mistake_type'));

        // Positivkontrolle: the next answer is still classified.
        $this->assertSame(MistakeType::Incomplete, $this->answer($submission, 'second')->mistake_type);
    }

    public function test_a_teacher_correction_stored_after_loading_wins(): void
    {
        $submission = $this->submission(['first' => 50, 'second' => 50]);
        $corrected = $this->answer($submission, 'first');

        // While the classifier runs, the teacher's correction reaches the database: the answer loaded before knows
        // nothing of it, only the conditional write can still see it.
        app()->instance(MistakeClassifier::class, $this->classifier(
            function (Question $question, Answer $answer) use ($corrected): MistakeClassification {
                if ($question->is($this->questions['first'])) {
                    DB::table('answers')->where('id', $corrected->getKey())->update([
                        'mistake_type' => MistakeType::Concept->value,
                        'mistake_confidence' => null,
                        'mistake_source' => MistakeSource::Teacher->value,
                    ]);
                }

                return new MistakeClassification(MistakeType::Procedure, 90, MistakeSource::Claude);
            },
        ));

        $this->record($submission);

        $stored = DB::table('answers')->where('id', $corrected->getKey())->first();
        $this->assertSame(MistakeSource::Teacher->value, $stored->mistake_source);
        $this->assertSame(MistakeType::Concept->value, $stored->mistake_type);

        // Positivkontrolle: the answer without a correction is written.
        $written = $this->answer($submission, 'second');
        $this->assertSame(MistakeType::Procedure, $written->mistake_type);
        $this->assertSame(MistakeSource::Claude, $written->mistake_source);
        $this->assertSame(90, $written->mistake_confidence);
    }

    public function test_the_recorder_runs_in_the_production_path(): void
    {
        $calls = 0;

        app()->instance(MistakeClassifier::class, $this->classifier(
            function (Question $question, Answer $answer) use (&$calls): MistakeClassification {
                $calls++;

                return new MistakeClassification(MistakeType::Incomplete, 80, MistakeSource::Demo);
            },
        ));

        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->expects('evaluateSubmission')->andReturn([
            'understanding_score' => 62,
            'feedback' => 'Fast: das Chlorophyll fehlt.',
            'gaps' => [],
            'answers' => [
                $this->questions['first']->getKey() => ['score' => 84, 'feedback' => 'Richtig erklärt.'],
                $this->questions['second']->getKey() => ['score' => 70, 'feedback' => 'Da fehlt noch Wesentliches.'],
            ],
        ]));

        $this->actingAs($this->student);

        Livewire::test(WorkOnHomework::class, ['lesson' => $this->lesson])
            ->fillForm([
                'summary' => 'Pflanzen stellen mit Licht, Wasser und Kohlendioxid Zucker her, dabei entsteht Sauerstoff.',
                'answers' => [
                    $this->questions['first']->getKey() => 'Licht, Wasser und Kohlendioxid.',
                    $this->questions['second']->getKey() => 'Damit sie wachsen kann und Kraft für die Blüte hat.',
                ],
            ])
            ->call('submit')
            ->assertHasNoFormErrors()
            ->assertNotified('Geschafft!');

        $submission = Submission::query()->sole();

        $this->assertTrue($submission->isEvaluated());
        $this->assertSame(62, $submission->understanding_score);

        // At the threshold nothing is classified, so nothing is stored either.
        $atThreshold = $submission->answers()->whereBelongsTo($this->questions['first'])->sole();
        $this->assertSame(84, $atThreshold->score);
        $this->assertNull($atThreshold->mistake_type);

        // Below the threshold the hint of the classifier is stored: the recorder really runs in this path.
        $belowThreshold = $submission->answers()->whereBelongsTo($this->questions['second'])->sole();
        $this->assertSame(70, $belowThreshold->score);
        $this->assertSame(MistakeType::Incomplete, $belowThreshold->mistake_type);
        $this->assertSame(80, $belowThreshold->mistake_confidence);
        $this->assertSame(MistakeSource::Demo, $belowThreshold->mistake_source);

        $this->assertSame(1, $calls);
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

    public function test_unusable_min_confidence_stores_nothing(): void
    {
        app()->instance(MistakeClassifier::class, $this->alwaysClassifying(
            new MistakeClassification(MistakeType::Procedure, 70, MistakeSource::Demo),
        ));

        // Every unusable value is refused the same way: null (the config's answer to an unreadable value), text, an
        // empty value, a comma as decimal point and anything outside the share 0–1.
        foreach (['null' => null, 'Text' => 'abc', 'leer' => '', 'Komma' => '0,6', 'über 1' => 1.5, 'unter 0' => -0.2] as $case => $value) {
            config()->set('lernplattform.mistakes.min_confidence', $value);

            $submission = $this->submission(['first' => 50]);

            $this->record($submission);

            $this->assertNull($this->answer($submission, 'first')->mistake_type, $case);
            $this->assertNull($this->answer($submission, 'first')->mistake_source, $case);
            $this->assertNull($this->answer($submission, 'first')->mistake_confidence, $case);
        }

        // Positivkontrolle: with 0.6 the same answer and its confidence 70 are stored.
        config()->set('lernplattform.mistakes.min_confidence', 0.6);
        $stored = $this->submission(['first' => 50]);

        $this->record($stored);

        $this->assertSame(MistakeType::Procedure, $this->answer($stored, 'first')->mistake_type);
        $this->assertSame(70, $this->answer($stored, 'first')->mistake_confidence);
    }

    public function test_the_config_file_turns_a_broken_min_confidence_into_null(): void
    {
        // The config array is built again the way a deployment with this environment variable would see it; the
        // application itself is not rebooted, so no test depends on a second boot.
        $this->assertNull($this->configFromEnv('abc')['mistakes']['min_confidence']);
        $this->assertNull($this->configFromEnv('')['mistakes']['min_confidence']);
        $this->assertNull($this->configFromEnv('0,6')['mistakes']['min_confidence']);

        // A number outside 0–1 stays a number here; the recorder refuses it (see the test above).
        $this->assertSame(1.4, $this->configFromEnv('1.4')['mistakes']['min_confidence']);

        // Positivkontrolle: the default and a configured share between 0 and 1 are floats.
        $this->assertSame(0.6, $this->configFromEnv(null)['mistakes']['min_confidence']);
        $this->assertSame(0.6, $this->configFromEnv('0.6')['mistakes']['min_confidence']);
        $this->assertSame(0.75, $this->configFromEnv('0.75')['mistakes']['min_confidence']);
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
     * The lernplattform config as a deployment with the given LERNPLATTFORM_MISTAKES_MIN_CONFIDENCE would load it;
     * the environment is put back afterwards.
     *
     * @return array<string, mixed>
     */
    private function configFromEnv(?string $value): array
    {
        $key = 'LERNPLATTFORM_MISTAKES_MIN_CONFIDENCE';
        $previous = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];

        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        } else {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }

        try {
            /** @var array<string, mixed> $config */
            $config = require config_path('lernplattform.php');

            return $config;
        } finally {
            if ($previous[0] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $previous[0];
            }

            if ($previous[1] === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $previous[1];
            }

            if ($previous[2] === false) {
                putenv($key);
            } else {
                putenv("{$key}={$previous[2]}");
            }
        }
    }

    /**
     * A hand-in with its own student from the same class, because submissions are unique per lesson and student and
     * one test hands in more than once. The questions stay the ones of this lesson.
     *
     * @param  array<string, int|null>  $scores  question name => score
     * @param  array<string, array<string, mixed>>  $attributes  question name => extra answer attributes
     */
    private function submission(array $scores, array $attributes = []): Submission
    {
        $classmate = User::factory()->student($this->student->schoolClass)->create();

        $submission = Submission::factory()->for($this->lesson)->for($classmate, 'student')->create();

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
