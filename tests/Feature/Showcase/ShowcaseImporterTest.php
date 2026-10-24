<?php

namespace Tests\Feature\Showcase;

use App\MessageAuthor;
use App\Models\ConsentForm;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\RecordingConsent;
use App\Models\ReviewItem;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\Role;
use App\Support\Showcase\InvalidShowcase;
use App\Support\Showcase\ShowcaseImporter;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * The importer fills the demo school from the showcase file of the orchestrator. The tests use the sample file that
 * ships with the application, so they check the documented schema version against a real file.
 */
class ShowcaseImporterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private const array TABLES = ['schools', 'users', 'school_classes', 'competencies', 'lessons', 'questions', 'lesson_user', 'submissions', 'answers', 'review_items', 'explanation_sessions', 'explanation_messages', 'consent_forms', 'recording_consents'];

    /**
     * @var array<string, int>
     */
    private const array NOTHING_CREATED = [
        'teachers' => 0,
        'classes' => 0,
        'students' => 0,
        'lessons' => 0,
        'questions' => 0,
        'submissions' => 0,
        'answers' => 0,
        'review_items' => 0,
        'explanation_sessions' => 0,
        'consents' => 0,
    ];

    public function test_the_sample_file_creates_exactly_the_expected_rows(): void
    {
        $this->seedDemo();
        $countsBefore = $this->rowCounts();

        $counts = $this->importer()->import($this->sampleData(), today());

        $this->assertSame([
            'teachers' => 1,
            'classes' => 1,
            'students' => 4,
            'lessons' => 4,
            'questions' => 5,
            'submissions' => 3,
            'answers' => 7,
            'review_items' => 2,
            'explanation_sessions' => 2,
            'consents' => 3,
        ], $counts);

        $countsAfter = $this->rowCounts();

        $this->assertSame($countsBefore['schools'], $countsAfter['schools']);
        $this->assertSame($countsBefore['competencies'], $countsAfter['competencies']);
        $this->assertSame($countsBefore['users'] + 5, $countsAfter['users']);
        $this->assertSame($countsBefore['school_classes'] + 1, $countsAfter['school_classes']);
        $this->assertSame($countsBefore['lessons'] + 4, $countsAfter['lessons']);
        $this->assertSame($countsBefore['questions'] + 5, $countsAfter['questions']);
        $this->assertSame($countsBefore['submissions'] + 3, $countsAfter['submissions']);
        $this->assertSame($countsBefore['answers'] + 7, $countsAfter['answers']);
        $this->assertSame($countsBefore['review_items'] + 2, $countsAfter['review_items']);
        $this->assertSame($countsBefore['explanation_sessions'] + 2, $countsAfter['explanation_sessions']);
        $this->assertSame($countsBefore['explanation_messages'] + 5, $countsAfter['explanation_messages']);
        $this->assertSame($countsBefore['consent_forms'] + 1, $countsAfter['consent_forms']);
        $this->assertSame($countsBefore['recording_consents'] + 3, $countsAfter['recording_consents']);

        // All four lessons are in the new class 8a, with five questions and three hand-ins in the whole file.
        $class = SchoolClass::query()
            ->where('school_id', $this->demoSchool()->getKey())
            ->where('name', '8a')
            ->firstOrFail();

        $this->assertSame(4, $class->lessons()->count());
        $this->assertSame(5, DB::table('questions')->whereIn('lesson_id', $class->lessons()->pluck('id'))->count());
        $this->assertSame(3, DB::table('submissions')->whereIn('lesson_id', $class->lessons()->pluck('id'))->count());
    }

    public function test_a_second_run_creates_nothing(): void
    {
        $this->seedDemo();
        $importer = $this->importer();
        $importer->import($this->sampleData(), today());
        $countsAfterFirstRun = $this->rowCounts();

        $counts = $importer->import($this->sampleData(), today());

        $this->assertSame(self::NOTHING_CREATED, $counts);
        $this->assertSame($countsAfterFirstRun, $this->rowCounts());
    }

    public function test_the_relative_dates_are_counted_from_the_given_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-20 10:00:00'));
        $this->seedDemo();

        $this->importer()->import($this->sampleData(), today());

        $lesson = $this->lessonByKey('8a-ge-01');

        $this->assertSame('2026-04-30', $lesson->held_on->toDateString());
        $this->assertSame('2026-04-30 15:10', $lesson->published_at->format('Y-m-d H:i'));
        $this->assertSame('2026-05-05', $lesson->due_on->toDateString());

        // An open homework may be due in the future; the draft has neither a publication nor a due date.
        $openHomework = $this->lessonByKey('8a-ge-02');

        $this->assertSame('2026-05-18 14:30', $openHomework->published_at->format('Y-m-d H:i'));
        $this->assertSame('2026-05-23', $openHomework->due_on->toDateString());

        $draft = $this->lessonByKey('8a-ge-03-entwurf');

        $this->assertSame('2026-05-21', $draft->held_on->toDateString());
        $this->assertNull($draft->published_at);
        $this->assertNull($draft->due_on);

        $aylin = $this->user('aylin.demir');
        $submission = Submission::query()
            ->where('lesson_id', $lesson->getKey())
            ->where('student_id', $aylin->getKey())
            ->firstOrFail();

        $this->assertSame('2026-05-02 17:40', $submission->created_at->format('Y-m-d H:i'));
        $this->assertSame('2026-05-02 17:40', $submission->updated_at->format('Y-m-d H:i'));
        $this->assertSame('2026-05-02 17:42', $submission->evaluated_at->format('Y-m-d H:i'));

        $item = ReviewItem::query()->where('student_id', $aylin->getKey())->firstOrFail();

        $this->assertSame('2026-05-20', $item->due_on->toDateString());
        $this->assertSame('2026-05-13', $item->last_reviewed_at->toDateString());

        $consent = RecordingConsent::query()->where('student_id', $aylin->getKey())->firstOrFail();

        $this->assertSame('2026-04-20', $consent->granted_at->toDateString());
        $this->assertNull($consent->revoked_at);

        $revoked = RecordingConsent::query()->where('student_id', $this->user('marie.vogel')->getKey())->firstOrFail();

        $this->assertSame('2026-04-10', $revoked->granted_at->toDateString());
        $this->assertSame('2026-05-10', $revoked->revoked_at->toDateString());
        $this->assertSame(['audio', 'transcript'], $revoked->purposes);

        $session = ExplanationSession::query()
            ->where('student_id', $this->user('finn.hartmann')->getKey())
            ->firstOrFail();

        $this->assertSame('2026-05-03 18:20', $session->created_at->format('Y-m-d H:i'));
        $this->assertSame('2026-05-03 18:35', $session->finished_at->format('Y-m-d H:i'));

        // Without a published consent form the importer publishes one, dated 60 days back.
        $form = ConsentForm::query()->firstOrFail();

        $this->assertSame('2026-03-21', $form->published_at->toDateString());
        $this->assertSame(array_keys(ConsentForm::PURPOSES), $form->purposes);
    }

    public function test_new_accounts_are_not_loginable_and_existing_accounts_stay_untouched(): void
    {
        $this->seedDemo();

        $existing = $this->user('schueler');
        $password = $existing->password;
        $name = $existing->name;
        $class = $existing->school_class_id;

        // The file may name existing accounts of the school for a lesson; they are used, never changed.
        $data = $this->sampleData();
        $data['lessons'][0]['teacher'] = 'lehrer';
        $data['lessons'][0]['students'][] = 'schueler';

        $this->importer()->import($data, today());

        $unchanged = $this->user('schueler');

        $this->assertSame($password, $unchanged->password);
        $this->assertSame($name, $unchanged->name);
        $this->assertSame($class, $unchanged->school_class_id);
        $this->assertTrue(Hash::check(DemoSchoolSeeder::PASSWORD, $unchanged->password));

        $school = $this->demoSchool();
        $class8a = SchoolClass::query()->where('school_id', $school->getKey())->where('name', '8a')->firstOrFail();

        $newcomer = $this->user('aylin.demir');

        $this->assertSame(Role::Student, $newcomer->role);
        $this->assertSame($school->getKey(), $newcomer->school_id);
        $this->assertSame($class8a->getKey(), $newcomer->school_class_id);
        $this->assertFalse(Hash::check(DemoSchoolSeeder::PASSWORD, $newcomer->password));

        $teacher = $this->user('selin.kaya');

        $this->assertSame(Role::Teacher, $teacher->role);
        $this->assertSame($school->getKey(), $teacher->school_id);
        $this->assertNull($teacher->school_class_id);

        // The lesson took the existing teacher of the school.
        $this->assertSame($this->user('lehrer')->getKey(), $this->lessonByKey('8a-ge-01')->teacher_id);
    }

    public function test_a_username_of_another_school_is_rejected(): void
    {
        $this->seedDemo();
        $countsBefore = $this->rowCounts();

        $data = $this->sampleData();
        $data['students'][] = ['username' => 'gym-schueler1', 'name' => 'Finn Albrecht', 'class' => '8a'];

        try {
            $this->importer()->import($data, today());
            $this->fail('Ein Konto einer anderen Schule hätte abgelehnt werden müssen.');
        } catch (InvalidShowcase $exception) {
            $this->assertStringContainsString('students[4].username', $exception->getMessage());
            $this->assertStringContainsString('anderen Schule', $exception->getMessage());
        }

        $this->assertSame($countsBefore, $this->rowCounts());
    }

    public function test_a_class_of_another_school_is_rejected(): void
    {
        $this->seedDemo();
        $countsBefore = $this->rowCounts();

        // 8c exists only at the Gymnasium Musterstadt.
        $data = $this->sampleData();
        $data['students'][0]['class'] = '8c';

        try {
            $this->importer()->import($data, today());
            $this->fail('Eine Klasse einer anderen Schule hätte abgelehnt werden müssen.');
        } catch (InvalidShowcase $exception) {
            $this->assertStringContainsString('students[0].class', $exception->getMessage());
        }

        $this->assertSame($countsBefore, $this->rowCounts());
    }

    public function test_a_free_explanation_topic_is_stored_without_a_lesson(): void
    {
        $this->seedDemo();

        $this->importer()->import($this->sampleData(), today());

        $free = ExplanationSession::query()->where('student_id', $this->user('marie.vogel')->getKey())->firstOrFail();

        $this->assertNull($free->lesson_id);
        $this->assertNull($free->finished_at);
        $this->assertSame([
            'Ein Vulkan ist ein Berg, aus dem Lava kommt.',
            'Woher kommt die Lava, bevor sie oben austritt?',
        ], $free->messages->pluck('content')->all());
        $this->assertSame([MessageAuthor::Student, MessageAuthor::Tutor], $free->messages->pluck('author')->all());

        // Positivkontrolle: a session that names a lesson stays attached to that lesson.
        $bound = ExplanationSession::query()->where('student_id', $this->user('finn.hartmann')->getKey())->firstOrFail();

        $this->assertSame($this->lessonByKey('8a-ge-01')->getKey(), $bound->lesson_id);
    }

    public function test_a_failure_while_writing_rolls_back_every_row(): void
    {
        $this->seedDemo();
        $countsBefore = $this->rowCounts();

        // The file is valid, so the failure happens after lessons and questions were written.
        Event::listen('eloquent.creating: '.Submission::class, fn (): never => throw new RuntimeException('Schreibfehler beim Anlegen der Abgabe.'));

        try {
            $this->importer()->import($this->sampleData(), today());
            $this->fail('Der Schreibfehler hätte den Import abbrechen müssen.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Schreibfehler beim Anlegen der Abgabe.', $exception->getMessage());
        }

        $this->assertSame($countsBefore, $this->rowCounts());
    }

    public function test_a_file_of_another_version_is_rejected(): void
    {
        $this->assertInvalidShowcase(function (array $data): array {
            $data['version'] = 2;

            return $data;
        }, 'version');
    }

    public function test_an_unknown_question_type_is_rejected(): void
    {
        $this->assertInvalidShowcase(function (array $data): array {
            $data['lessons'][0]['questions'][0]['type'] = 'multiple_choice';

            return $data;
        }, 'lessons[0].questions[0].type');
    }

    public function test_an_unknown_mistake_type_is_rejected(): void
    {
        $this->assertInvalidShowcase(function (array $data): array {
            $data['lessons'][0]['submissions'][0]['answers'][0]['mistake_type'] = 'careless';

            return $data;
        }, 'lessons[0].submissions[0].answers[0].mistake_type');
    }

    public function test_a_mistake_type_without_a_source_is_rejected(): void
    {
        $this->assertInvalidShowcase(function (array $data): array {
            $data['lessons'][0]['submissions'][0]['answers'][0]['mistake_type'] = 'concept';

            return $data;
        }, 'lessons[0].submissions[0].answers[0].mistake_source');
    }

    public function test_an_unknown_competency_is_rejected(): void
    {
        $this->assertInvalidShowcase(function (array $data): array {
            $data['lessons'][0]['questions'][0]['competency']['name'] = 'Weltraumkompetenz';

            return $data;
        }, 'lessons[0].questions[0].competency');
    }

    public function test_an_answer_to_a_question_outside_the_lesson_is_rejected(): void
    {
        $this->assertInvalidShowcase(function (array $data): array {
            $data['lessons'][0]['submissions'][0]['answers'][0]['q'] = 3;

            return $data;
        }, 'lessons[0].submissions[0].answers[0].q');
    }

    public function test_a_duplicate_lesson_key_is_rejected(): void
    {
        $this->assertInvalidShowcase(function (array $data): array {
            $data['lessons'][1]['key'] = '8a-ge-01';

            return $data;
        }, 'lessons[1].key');
    }

    public function test_a_review_item_on_a_reflection_question_is_rejected(): void
    {
        $this->assertInvalidShowcase(function (array $data): array {
            // The third question of the first lesson is a reflection question.
            $data['review_items'][0]['q'] = 2;

            return $data;
        }, 'review_items[0].q');
    }

    public function test_a_review_item_without_an_evaluated_submission_is_rejected(): void
    {
        $this->assertInvalidShowcase(function (array $data): array {
            $data['review_items'][] = [
                'student' => 'marie.vogel',
                'lesson' => '8a-ge-01',
                'q' => 0,
                'box' => 2,
                'due_in_days' => 1,
                'last_reviewed_days_ago' => null,
                'knew' => 0,
                'hard' => 0,
                'forgot' => 0,
            ];

            return $data;
        }, 'review_items[2]');
    }

    /**
     * The sample file is imported as a positive control, then the changed copy has to be rejected with the path of
     * the offending field, and no row may be left behind.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    private function assertInvalidShowcase(callable $change, string $expectedPath): void
    {
        $this->seedDemo();
        $this->importer()->import($this->sampleData(), today());
        $countsBefore = $this->rowCounts();

        try {
            $this->importer()->import($change($this->sampleData()), today());
            $this->fail("Der Import hätte eine InvalidShowcase für {$expectedPath} werfen müssen.");
        } catch (InvalidShowcase $exception) {
            $this->assertStringContainsString($expectedPath, $exception->getMessage());
        }

        $this->assertSame($countsBefore, $this->rowCounts());
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleData(): array
    {
        $json = file_get_contents(database_path('seeders/demo/showcase.sample.json'));

        $this->assertIsString($json);

        $data = json_decode($json, true);

        $this->assertIsArray($data);

        return $data;
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

    private function importer(): ShowcaseImporter
    {
        return new ShowcaseImporter;
    }

    private function seedDemo(): void
    {
        $this->seed(DatabaseSeeder::class);
    }

    private function demoSchool(): School
    {
        return School::query()->where('school_number', '4711')->firstOrFail();
    }

    private function user(string $username): User
    {
        return User::query()->where('username', $username)->firstOrFail();
    }

    /**
     * The imported lesson of the sample file with the given key.
     */
    private function lessonByKey(string $key): Lesson
    {
        foreach ($this->sampleData()['lessons'] as $entry) {
            if ($entry['key'] === $key) {
                return Lesson::query()
                    ->where('subject', $entry['subject'])
                    ->where('topic', $entry['topic'])
                    ->firstOrFail();
            }
        }

        $this->fail("Die Beispieldatei enthält keine Stunde {$key}.");
    }
}
