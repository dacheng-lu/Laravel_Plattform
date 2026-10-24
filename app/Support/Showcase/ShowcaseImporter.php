<?php

namespace App\Support\Showcase;

use App\MessageAuthor;
use App\MistakeSource;
use App\MistakeType;
use App\Models\Competency;
use App\Models\ConsentForm;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\RecordingConsent;
use App\Models\ReviewItem;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\QuestionType;
use App\Role;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Imports the showcase file of the demo school (schema version 1) into an existing demo school.
 *
 * import() validates the whole file first and writes afterwards, all inside one transaction: a file with a single
 * problem never leaves half a showcase behind. Accounts the file names may already exist in that school; they are
 * used as they are and never changed. Lessons are matched by class, subject and topic, so a second run adds nothing.
 * Everything is resolved inside the given school: a username or class name of another school is rejected instead of
 * being matched.
 */
final class ShowcaseImporter
{
    /**
     * The only schema version this importer understands.
     */
    private const int VERSION = 1;

    /**
     * How long the random password of a new account is. New accounts are not meant to be login-able, and the demo
     * password is never used.
     */
    private const int PASSWORD_LENGTH = 40;

    /**
     * The keys of the result, in the order the command prints them.
     *
     * @var list<string>
     */
    private const array COUNTED = ['teachers', 'classes', 'students', 'lessons', 'questions', 'submissions', 'answers', 'review_items', 'explanation_sessions', 'consents'];

    /**
     * @var array<string, User> known accounts by username
     */
    private array $users = [];

    /**
     * @var array<string, SchoolClass> known classes by name
     */
    private array $classes = [];

    /**
     * @var array<string, int>
     */
    private array $counts = [];

    /**
     * Imports the whole file or nothing at all. Every relative date in the file is counted from $today.
     *
     * @param  array<string, mixed>  $data  the decoded file
     * @return array<string, int> the number of newly created rows per area
     *
     * @throws InvalidShowcase when the file cannot be imported as a whole
     */
    public function import(array $data, CarbonInterface $today): array
    {
        $this->users = [];
        $this->classes = [];
        $this->counts = array_fill_keys(self::COUNTED, 0);

        $this->validate($data);

        return DB::transaction(fn (): array => $this->write($data, $today));
    }

    /**
     * Rejects a file that cannot be imported completely. Reads the school, its classes, its accounts and the
     * competency framework, but writes nothing.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidShowcase
     */
    private function validate(array $data): void
    {
        if (($data['version'] ?? null) !== self::VERSION) {
            $this->fail('version', 'wird nicht verstanden, erwartet wird 1');
        }

        $school = $this->school($data['school_number'] ?? null);

        $fixtureUsers = $this->validateTeachers($data, $school);
        $fixtureClasses = $this->validateClasses($data, $school, $fixtureUsers);
        $fixtureUsers += $this->validateStudents($data, $school, $fixtureClasses);

        $lessons = $this->validateLessons($data, $school, $fixtureClasses, $fixtureUsers);

        $this->validateReviewItems($data, $school, $fixtureUsers, $lessons);
        $this->validateExplanationSessions($data, $school, $fixtureUsers, $lessons);
        $this->validateConsents($data, $school, $fixtureUsers);
    }

    /**
     * The school the file belongs to.
     *
     * @throws InvalidShowcase
     */
    private function school(mixed $value): School
    {
        $number = $this->requireString($value, 'school_number');
        $school = School::query()->where('school_number', $number)->first();

        if ($school === null) {
            throw new InvalidShowcase(sprintf('Die Demo-Schule %s fehlt – zuerst php artisan db:seed.', $number));
        }

        return $school;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, true> the usernames the file itself defines
     *
     * @throws InvalidShowcase
     */
    private function validateTeachers(array $data, School $school): array
    {
        $usernames = [];

        foreach ($this->requireList($data['teachers'] ?? null, 'teachers') as $index => $entry) {
            $path = "teachers[{$index}]";
            $teacher = $this->requireArray($entry, $path);
            $username = $this->requireString($teacher['username'] ?? null, "{$path}.username");

            $this->requireString($teacher['name'] ?? null, "{$path}.name");
            $this->requireAvailableUsername($username, $school, "{$path}.username");

            $usernames[$username] = true;
        }

        return $usernames;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, true>  $fixtureUsers
     * @return array<string, true> the class names the file itself defines
     *
     * @throws InvalidShowcase
     */
    private function validateClasses(array $data, School $school, array $fixtureUsers): array
    {
        $names = [];

        foreach ($this->requireList($data['classes'] ?? null, 'classes') as $index => $entry) {
            $path = "classes[{$index}]";
            $class = $this->requireArray($entry, $path);
            $name = $this->requireString($class['name'] ?? null, "{$path}.name");

            $this->requireUserReference($class['teacher'] ?? null, "{$path}.teacher", $school, $fixtureUsers);

            $names[$name] = true;
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, true>  $fixtureClasses
     * @return array<string, true> the usernames the file itself defines
     *
     * @throws InvalidShowcase
     */
    private function validateStudents(array $data, School $school, array $fixtureClasses): array
    {
        $usernames = [];

        foreach ($this->requireList($data['students'] ?? null, 'students') as $index => $entry) {
            $path = "students[{$index}]";
            $student = $this->requireArray($entry, $path);
            $username = $this->requireString($student['username'] ?? null, "{$path}.username");

            $this->requireString($student['name'] ?? null, "{$path}.name");
            $this->requireClassReference($student['class'] ?? null, "{$path}.class", $school, $fixtureClasses);
            $this->requireAvailableUsername($username, $school, "{$path}.username");

            $usernames[$username] = true;
        }

        return $usernames;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, true>  $fixtureClasses
     * @param  array<string, true>  $fixtureUsers
     * @return array<string, array<string, mixed>> the lessons by their key in the file
     *
     * @throws InvalidShowcase
     */
    private function validateLessons(array $data, School $school, array $fixtureClasses, array $fixtureUsers): array
    {
        $lessons = [];

        foreach ($this->requireList($data['lessons'] ?? null, 'lessons') as $index => $entry) {
            $path = "lessons[{$index}]";
            $lesson = $this->requireArray($entry, $path);
            $key = $this->requireString($lesson['key'] ?? null, "{$path}.key");

            if (isset($lessons[$key])) {
                $this->fail("{$path}.key", "der Schlüssel {$key} kommt in der Datei mehrfach vor");
            }

            $this->requireClassReference($lesson['class'] ?? null, "{$path}.class", $school, $fixtureClasses);
            $this->requireUserReference($lesson['teacher'] ?? null, "{$path}.teacher", $school, $fixtureUsers);
            $this->requireString($lesson['subject'] ?? null, "{$path}.subject");
            $this->requireString($lesson['topic'] ?? null, "{$path}.topic");
            $this->requireInt($lesson['held_days_ago'] ?? null, "{$path}.held_days_ago");
            $this->requireString($lesson['notes'] ?? null, "{$path}.notes");
            $this->requireNullableString($lesson['summary'] ?? null, "{$path}.summary");
            $this->requirePublication($lesson, $path);
            $this->requireNullableInt($lesson['due_days_ago'] ?? null, "{$path}.due_days_ago");
            $this->requireCompetencyReference($lesson['practice_competency'] ?? null, "{$path}.practice_competency");

            $students = $this->validateLessonStudents($lesson, $path, $school, $fixtureUsers);

            $this->validateQuestions($lesson, $path);
            $this->validateSubmissions($lesson, $path, $school, $fixtureUsers, $students);

            $lessons[$key] = $lesson;
        }

        return $lessons;
    }

    /**
     * @param  array<string, mixed>  $lesson
     * @param  array<string, true>  $fixtureUsers
     * @return array<string, true> the usernames of the students the homework is delegated to
     *
     * @throws InvalidShowcase
     */
    private function validateLessonStudents(array $lesson, string $path, School $school, array $fixtureUsers): array
    {
        $students = [];

        foreach ($this->requireList($lesson['students'] ?? null, "{$path}.students") as $index => $username) {
            $this->requireUserReference($username, "{$path}.students[{$index}]", $school, $fixtureUsers);

            $students[(string) $username] = true;
        }

        return $students;
    }

    /**
     * @param  array<string, mixed>  $lesson
     *
     * @throws InvalidShowcase
     */
    private function validateQuestions(array $lesson, string $path): void
    {
        foreach ($this->requireList($lesson['questions'] ?? null, "{$path}.questions") as $index => $entry) {
            $questionPath = "{$path}.questions[{$index}]";
            $question = $this->requireArray($entry, $questionPath);

            $this->requireEnum($question['type'] ?? null, "{$questionPath}.type", QuestionType::class);
            $this->requireCompetencyReference($question['competency'] ?? null, "{$questionPath}.competency");
            $this->requireString($question['prompt'] ?? null, "{$questionPath}.prompt");
            $this->requireString($question['expected_answer'] ?? null, "{$questionPath}.expected_answer");
        }
    }

    /**
     * @param  array<string, mixed>  $lesson
     * @param  array<string, true>  $fixtureUsers
     * @param  array<string, true>  $students  the students of this lesson
     *
     * @throws InvalidShowcase
     */
    private function validateSubmissions(array $lesson, string $path, School $school, array $fixtureUsers, array $students): void
    {
        $seen = [];

        foreach ($this->requireList($lesson['submissions'] ?? null, "{$path}.submissions") as $index => $entry) {
            $submissionPath = "{$path}.submissions[{$index}]";
            $submission = $this->requireArray($entry, $submissionPath);
            $student = $this->requireString($submission['student'] ?? null, "{$submissionPath}.student");

            $this->requireUserReference($student, "{$submissionPath}.student", $school, $fixtureUsers);

            if (! isset($students[$student])) {
                $this->fail("{$submissionPath}.student", "{$student} steht nicht bei den Schüler:innen dieser Stunde");
            }

            if (isset($seen[$student])) {
                $this->fail("{$submissionPath}.student", "{$student} hat in dieser Stunde schon eine Abgabe");
            }

            $seen[$student] = true;

            $this->requireInt($submission['submitted_days_ago'] ?? null, "{$submissionPath}.submitted_days_ago");
            $this->requireTime($submission['submitted_time'] ?? null, "{$submissionPath}.submitted_time");
            $this->requireBool($submission['evaluated'] ?? null, "{$submissionPath}.evaluated");
            $this->requireString($submission['summary'] ?? null, "{$submissionPath}.summary");
            $this->requireNullableScore($submission['understanding_score'] ?? null, "{$submissionPath}.understanding_score");
            $this->requireNullableString($submission['feedback'] ?? null, "{$submissionPath}.feedback");
            $this->requireStringList($submission['gaps'] ?? null, "{$submissionPath}.gaps");

            $this->validateAnswers($submission, $submissionPath, count($lesson['questions']));
        }
    }

    /**
     * @param  array<string, mixed>  $submission
     *
     * @throws InvalidShowcase
     */
    private function validateAnswers(array $submission, string $path, int $questionCount): void
    {
        $seen = [];

        foreach ($this->requireList($submission['answers'] ?? null, "{$path}.answers") as $index => $entry) {
            $answerPath = "{$path}.answers[{$index}]";
            $answer = $this->requireArray($entry, $answerPath);
            $question = $this->requireInt($answer['q'] ?? null, "{$answerPath}.q");

            if ($question < 0 || $question >= $questionCount) {
                $this->fail("{$answerPath}.q", 'zeigt auf keine Frage der Stunde');
            }

            if (isset($seen[$question])) {
                $this->fail("{$answerPath}.q", 'beantwortet eine Frage der Stunde doppelt');
            }

            $seen[$question] = true;

            // An empty answer is allowed: a student may hand in without an attempt.
            $this->requireText($answer['answer'] ?? null, "{$answerPath}.answer");
            $this->requireNullableScore($answer['score'] ?? null, "{$answerPath}.score");
            $this->requireNullableString($answer['feedback'] ?? null, "{$answerPath}.feedback");

            $mistakeType = $this->requireNullableEnum($answer['mistake_type'] ?? null, "{$answerPath}.mistake_type", MistakeType::class);

            $this->requireNullableScore($answer['mistake_confidence'] ?? null, "{$answerPath}.mistake_confidence");

            $mistakeSource = $this->requireNullableEnum($answer['mistake_source'] ?? null, "{$answerPath}.mistake_source", MistakeSource::class);

            if (($mistakeType === null) !== ($mistakeSource === null)) {
                $this->fail($mistakeType === null ? "{$answerPath}.mistake_type" : "{$answerPath}.mistake_source", 'eine Fehlerart und ihre Quelle gehören zusammen');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, true>  $fixtureUsers
     * @param  array<string, array<string, mixed>>  $lessons  the lessons of the file by key
     *
     * @throws InvalidShowcase
     */
    private function validateReviewItems(array $data, School $school, array $fixtureUsers, array $lessons): void
    {
        foreach ($this->requireList($data['review_items'] ?? null, 'review_items') as $index => $entry) {
            $path = "review_items[{$index}]";
            $item = $this->requireArray($entry, $path);
            $username = $this->requireString($item['student'] ?? null, "{$path}.student");

            $this->requireUserReference($username, "{$path}.student", $school, $fixtureUsers);

            $key = $this->requireString($item['lesson'] ?? null, "{$path}.lesson");
            $lesson = $lessons[$key] ?? null;

            if ($lesson === null) {
                $this->fail("{$path}.lesson", "die Stunde {$key} steht nicht in der Datei");
            }

            $question = $this->requireInt($item['q'] ?? null, "{$path}.q");

            if (! isset($lesson['questions'][$question])) {
                $this->fail("{$path}.q", 'zeigt auf keine Frage der Stunde');
            }

            if (QuestionType::from($lesson['questions'][$question]['type']) === QuestionType::Reflection) {
                $this->fail("{$path}.q", 'Reflexionsfragen werden nicht wiederholt');
            }

            $this->requireEvaluatedSubmission($lesson, $username, "{$path}.student");

            $box = $this->requireInt($item['box'] ?? null, "{$path}.box");

            if ($box < 1 || $box > 5) {
                $this->fail("{$path}.box", 'liegt außerhalb von 1 bis 5');
            }

            $this->requireInt($item['due_in_days'] ?? null, "{$path}.due_in_days");
            $this->requireNullableInt($item['last_reviewed_days_ago'] ?? null, "{$path}.last_reviewed_days_ago");
            $this->requireNonNegativeInt($item['knew'] ?? null, "{$path}.knew");
            $this->requireNonNegativeInt($item['hard'] ?? null, "{$path}.hard");
            $this->requireNonNegativeInt($item['forgot'] ?? null, "{$path}.forgot");
        }
    }

    /**
     * A review item only exists for a comprehension or application question of a lesson the student handed in.
     *
     * @param  array<string, mixed>  $lesson
     *
     * @throws InvalidShowcase
     */
    private function requireEvaluatedSubmission(array $lesson, string $username, string $path): void
    {
        foreach ($lesson['submissions'] as $submission) {
            if ($submission['student'] === $username && $submission['evaluated'] === true) {
                return;
            }
        }

        $this->fail($path, "{$username} hat zu dieser Stunde keine bewertete Abgabe in der Datei");
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, true>  $fixtureUsers
     * @param  array<string, array<string, mixed>>  $lessons  the lessons of the file by key
     *
     * @throws InvalidShowcase
     */
    private function validateExplanationSessions(array $data, School $school, array $fixtureUsers, array $lessons): void
    {
        foreach ($this->requireList($data['explanation_sessions'] ?? null, 'explanation_sessions') as $index => $entry) {
            $path = "explanation_sessions[{$index}]";
            $session = $this->requireArray($entry, $path);

            $this->requireUserReference($session['student'] ?? null, "{$path}.student", $school, $fixtureUsers);
            $this->requireLessonReference($session['lesson'] ?? null, "{$path}.lesson", $lessons);
            $this->requireString($session['topic'] ?? null, "{$path}.topic");
            $this->requireInt($session['started_days_ago'] ?? null, "{$path}.started_days_ago");
            $this->requireTime($session['started_time'] ?? null, "{$path}.started_time");
            $this->requireBool($session['finished'] ?? null, "{$path}.finished");
            $this->requireNullableScore($session['understanding_score'] ?? null, "{$path}.understanding_score");
            $this->requireNullableString($session['feedback'] ?? null, "{$path}.feedback");

            foreach ($this->requireList($session['messages'] ?? null, "{$path}.messages") as $position => $message) {
                $messagePath = "{$path}.messages[{$position}]";
                $message = $this->requireArray($message, $messagePath);

                $this->requireEnum($message['author'] ?? null, "{$messagePath}.author", MessageAuthor::class);
                $this->requireString($message['content'] ?? null, "{$messagePath}.content");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, true>  $fixtureUsers
     *
     * @throws InvalidShowcase
     */
    private function validateConsents(array $data, School $school, array $fixtureUsers): void
    {
        foreach ($this->requireList($data['consents'] ?? null, 'consents') as $index => $entry) {
            $path = "consents[{$index}]";
            $consent = $this->requireArray($entry, $path);

            $this->requireUserReference($consent['student'] ?? null, "{$path}.student", $school, $fixtureUsers);
            $this->requireString($consent['guardian_name'] ?? null, "{$path}.guardian_name");
            $this->requirePurposes($consent['purposes'] ?? null, "{$path}.purposes");
            $this->requireInt($consent['granted_days_ago'] ?? null, "{$path}.granted_days_ago");
            $this->requireNullableInt($consent['revoked_days_ago'] ?? null, "{$path}.revoked_days_ago");
        }
    }

    /**
     * @return list<string>
     *
     * @throws InvalidShowcase
     */
    private function requirePurposes(mixed $value, string $path): array
    {
        $purposes = $this->requireStringList($value, $path);

        if ($purposes === []) {
            $this->fail($path, 'darf nicht leer sein');
        }

        foreach ($purposes as $position => $purpose) {
            if (! array_key_exists($purpose, ConsentForm::PURPOSES)) {
                $this->fail("{$path}[{$position}]", "unbekannter Zweck {$purpose}");
            }
        }

        return $purposes;
    }

    /**
     * A class is looked up in this school only, so a class name of another school never matches.
     *
     * @param  array<string, true>  $fixtureClasses
     *
     * @throws InvalidShowcase
     */
    private function requireClassReference(mixed $value, string $path, School $school, array $fixtureClasses): void
    {
        $name = $this->requireString($value, $path);

        if (isset($fixtureClasses[$name])) {
            return;
        }

        $exists = SchoolClass::query()->where('school_id', $school->getKey())->where('name', $name)->exists();

        if (! $exists) {
            $this->fail($path, "die Klasse {$name} gibt es an dieser Schule nicht");
        }
    }

    /**
     * A username reference: either an account the file defines, or an existing account of this school.
     *
     * @param  array<string, true>  $fixtureUsers
     *
     * @throws InvalidShowcase
     */
    private function requireUserReference(mixed $value, string $path, School $school, array $fixtureUsers): void
    {
        $username = $this->requireString($value, $path);

        if (isset($fixtureUsers[$username])) {
            return;
        }

        $user = User::query()->where('username', $username)->first();

        if ($user === null) {
            $this->fail($path, "{$username} steht nicht in der Datei und existiert nicht an dieser Schule");
        }

        if (! $user->isMemberOf($school)) {
            $this->fail($path, "{$username} gehört zu einer anderen Schule");
        }
    }

    /**
     * Usernames are unique across the platform: a new account must not take the username of another school.
     *
     * @throws InvalidShowcase
     */
    private function requireAvailableUsername(string $username, School $school, string $path): void
    {
        $user = User::query()->where('username', $username)->first();

        if ($user !== null && ! $user->isMemberOf($school)) {
            $this->fail($path, "{$username} gehört zu einer anderen Schule");
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $lessons  the lessons of the file by key
     *
     * @throws InvalidShowcase
     */
    private function requireLessonReference(mixed $value, string $path, array $lessons): void
    {
        if ($value === null) {
            return;
        }

        $key = $this->requireString($value, $path);

        if (! isset($lessons[$key])) {
            $this->fail($path, "die Stunde {$key} steht nicht in der Datei");
        }
    }

    /**
     * A competency reference is the subject (null for the cross-subject ones) plus the name, and it has to exist in
     * the framework.
     *
     * @throws InvalidShowcase
     */
    private function requireCompetencyReference(mixed $value, string $path): void
    {
        if ($value === null) {
            return;
        }

        $reference = $this->requireArray($value, $path);
        $subject = $reference['subject'] ?? null;

        if ($subject !== null) {
            $this->requireString($subject, "{$path}.subject");
        }

        $name = $this->requireString($reference['name'] ?? null, "{$path}.name");

        if ($this->competencyId(['subject' => $subject, 'name' => $name]) === null) {
            $this->fail($path, "die Kompetenz {$name} fehlt im Kompetenzraster");
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, int>
     */
    private function write(array $data, CarbonInterface $today): array
    {
        $school = School::query()->where('school_number', $data['school_number'])->firstOrFail();

        foreach ($data['teachers'] as $entry) {
            $user = User::query()->where('username', $entry['username'])->first();

            if ($user === null) {
                $user = $this->createAccount($entry['username'], $entry['name'], Role::Teacher, $school);
                $this->counts['teachers']++;
            }

            $this->users[$entry['username']] = $user;
        }

        foreach ($data['classes'] as $entry) {
            $class = SchoolClass::query()->firstOrCreate(
                ['school_id' => $school->getKey(), 'name' => $entry['name']],
                ['teacher_id' => $this->user($entry['teacher'])->getKey()],
            );

            if ($class->wasRecentlyCreated) {
                $this->counts['classes']++;
            }

            $this->classes[$entry['name']] = $class;
        }

        foreach ($data['students'] as $entry) {
            $user = User::query()->where('username', $entry['username'])->first();

            if ($user === null) {
                $user = $this->createAccount($entry['username'], $entry['name'], Role::Student, $school, $this->schoolClass($school, $entry['class']));
                $this->counts['students']++;
            }

            $this->users[$entry['username']] = $user;
        }

        $lessons = [];

        foreach ($data['lessons'] as $entry) {
            $lessons[$entry['key']] = $this->importLesson($entry, $today, $school);
        }

        $this->importReviewItems($data['review_items'], $today, $lessons);
        $this->importExplanationSessions($data['explanation_sessions'], $today, $lessons);
        $this->importConsents($data['consents'], $school, $today);

        return $this->counts;
    }

    /**
     * Creates the lesson with its questions and hand-ins, but only when it does not exist yet: an existing lesson
     * keeps its questions and hand-ins and nothing is added to it, just like DemoSchoolSeeder leaves them alone.
     *
     * @param  array<string, mixed>  $entry
     */
    private function importLesson(array $entry, CarbonInterface $today, School $school): Lesson
    {
        $publishedAt = ($entry['published_days_ago'] ?? null) === null
            ? null
            : $this->moment($today, $entry['published_days_ago'], $entry['published_time']);

        $dueOn = ($entry['due_days_ago'] ?? null) === null ? null : $this->date($today, $entry['due_days_ago']);

        $lesson = Lesson::query()->firstOrCreate(
            [
                'school_class_id' => $this->schoolClass($school, $entry['class'])->getKey(),
                'subject' => $entry['subject'],
                'topic' => $entry['topic'],
            ],
            [
                'teacher_id' => $this->user($entry['teacher'])->getKey(),
                'held_on' => $this->date($today, $entry['held_days_ago']),
                'notes' => $entry['notes'],
                'summary' => $entry['summary'] ?? null,
                'published_at' => $publishedAt,
                'due_on' => $dueOn,
                'practice_competency_id' => $this->competencyId($entry['practice_competency'] ?? null),
            ],
        );

        if (! $lesson->wasRecentlyCreated) {
            return $lesson;
        }

        $this->counts['lessons']++;

        $lesson->students()->syncWithoutDetaching(
            array_map(fn (string $username): int => $this->user($username)->getKey(), $entry['students']),
        );

        $questions = [];

        foreach ($entry['questions'] as $index => $question) {
            $questions[$index] = $lesson->questions()->create([
                'competency_id' => $this->competencyId($question['competency'] ?? null),
                'type' => QuestionType::from($question['type']),
                'prompt' => $question['prompt'],
                'expected_answer' => $question['expected_answer'],
                'position' => $index + 1,
            ]);

            $this->counts['questions']++;
        }

        foreach ($entry['submissions'] as $submission) {
            $this->importSubmission($lesson, $submission, $questions, $today);
        }

        return $lesson;
    }

    /**
     * The hand-in is stored with the date of the file: created_at and updated_at are the hand-in, evaluated_at is two
     * minutes later when the file marks it as evaluated.
     *
     * @param  list<Question>  $questions  the questions of the lesson in the order of the file
     * @param  array<string, mixed>  $entry
     */
    private function importSubmission(Lesson $lesson, array $entry, array $questions, CarbonInterface $today): void
    {
        $createdAt = $this->moment($today, $entry['submitted_days_ago'], $entry['submitted_time']);

        $submission = Submission::query()->forceCreate([
            'lesson_id' => $lesson->getKey(),
            'student_id' => $this->user($entry['student'])->getKey(),
            'summary' => $entry['summary'],
            'understanding_score' => $entry['understanding_score'] ?? null,
            'feedback' => $entry['feedback'] ?? null,
            'gaps' => $entry['gaps'],
            'evaluated_at' => $entry['evaluated'] ? $createdAt->copy()->addMinutes(2) : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $this->counts['submissions']++;

        foreach ($entry['answers'] as $answer) {
            $submission->answers()->create([
                'question_id' => $questions[$answer['q']]->getKey(),
                'answer' => $answer['answer'],
                'score' => $answer['score'] ?? null,
                'feedback' => $answer['feedback'] ?? null,
                'mistake_type' => ($answer['mistake_type'] ?? null) === null ? null : MistakeType::from($answer['mistake_type']),
                'mistake_confidence' => $answer['mistake_confidence'] ?? null,
                'mistake_source' => ($answer['mistake_source'] ?? null) === null ? null : MistakeSource::from($answer['mistake_source']),
            ]);

            $this->counts['answers']++;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, Lesson>  $lessons  the imported lessons by the key in the file
     */
    private function importReviewItems(array $items, CarbonInterface $today, array $lessons): void
    {
        foreach ($items as $entry) {
            $question = Question::query()
                ->where('lesson_id', $lessons[$entry['lesson']]->getKey())
                ->where('position', $entry['q'] + 1)
                ->firstOrFail();

            $item = ReviewItem::query()->firstOrCreate(
                ['student_id' => $this->user($entry['student'])->getKey(), 'question_id' => $question->getKey()],
                [
                    'box' => $entry['box'],
                    // due_in_days counts into the future, so the due date is added instead of subtracted.
                    'due_on' => $today->copy()->addDays($entry['due_in_days'])->toDateString(),
                    'last_reviewed_at' => ($entry['last_reviewed_days_ago'] ?? null) === null ? null : $this->moment($today, $entry['last_reviewed_days_ago']),
                    'knew_count' => $entry['knew'],
                    'hard_count' => $entry['hard'],
                    'forgot_count' => $entry['forgot'],
                ],
            );

            if ($item->wasRecentlyCreated) {
                $this->counts['review_items']++;
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $sessions
     * @param  array<string, Lesson>  $lessons  the imported lessons by the key in the file
     */
    private function importExplanationSessions(array $sessions, CarbonInterface $today, array $lessons): void
    {
        foreach ($sessions as $entry) {
            $session = ExplanationSession::query()->firstOrNew([
                'student_id' => $this->user($entry['student'])->getKey(),
                'topic' => $entry['topic'],
            ]);

            // One conversation per student and topic: an existing one keeps its messages.
            if ($session->exists) {
                continue;
            }

            $startedAt = $this->moment($today, $entry['started_days_ago'], $entry['started_time']);

            $session->forceFill([
                'lesson_id' => ($entry['lesson'] ?? null) === null ? null : $lessons[$entry['lesson']]->getKey(),
                'understanding_score' => $entry['understanding_score'] ?? null,
                'feedback' => $entry['feedback'] ?? null,
                'finished_at' => $entry['finished'] ? $startedAt->copy()->addMinutes(15) : null,
                'created_at' => $startedAt,
                'updated_at' => $startedAt,
            ])->save();

            $this->counts['explanation_sessions']++;

            foreach ($entry['messages'] as $message) {
                $session->messages()->create([
                    'author' => MessageAuthor::from($message['author']),
                    'content' => $message['content'],
                ]);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $consents
     */
    private function importConsents(array $consents, School $school, CarbonInterface $today): void
    {
        if ($consents === []) {
            return;
        }

        $recordedBy = $school->users()->withRole(Role::Admin)->orderBy('id')->first();
        $form = $school->currentConsentForm() ?? $this->publishConsentForm($school, $today, $recordedBy);

        foreach ($consents as $entry) {
            $student = $this->user($entry['student']);

            // One declaration per student: an existing consent is never overwritten.
            if (RecordingConsent::query()->where('student_id', $student->getKey())->exists()) {
                continue;
            }

            RecordingConsent::query()->create([
                'school_id' => $school->getKey(),
                'student_id' => $student->getKey(),
                'consent_form_id' => $form->getKey(),
                'guardian_name' => $entry['guardian_name'],
                'purposes' => $entry['purposes'],
                'granted_at' => $this->moment($today, $entry['granted_days_ago']),
                'revoked_at' => ($entry['revoked_days_ago'] ?? null) === null ? null : $this->moment($today, $entry['revoked_days_ago']),
                'source' => RecordingConsent::SOURCE_PAPER,
                'recorded_by' => $recordedBy?->getKey(),
            ]);

            $this->counts['consents']++;
        }
    }

    /**
     * A school without a published consent form gets one, as if it had published it 60 days ago: the showcase needs a
     * form the consents of the file can point to.
     */
    private function publishConsentForm(School $school, CarbonInterface $today, ?User $createdBy): ConsentForm
    {
        return $school->consentForms()->create([
            'version' => 1,
            'title' => ConsentForm::defaultTitle(),
            'body' => ConsentForm::defaultBody($school),
            'purposes' => array_keys(ConsentForm::PURPOSES),
            'published_at' => $this->moment($today, 60),
            'created_by' => $createdBy?->getKey(),
        ]);
    }

    /**
     * New accounts are not login-able: the password is random and never the demo password.
     */
    private function createAccount(string $username, string $name, Role $role, School $school, ?SchoolClass $class = null): User
    {
        return User::query()->create([
            'username' => $username,
            'name' => $name,
            'password' => Hash::make(Str::random(self::PASSWORD_LENGTH)),
            'role' => $role,
            'school_id' => $school->getKey(),
            'school_class_id' => $class?->getKey(),
        ]);
    }

    private function user(string $username): User
    {
        return $this->users[$username] ??= User::query()->where('username', $username)->firstOrFail();
    }

    private function schoolClass(School $school, string $name): SchoolClass
    {
        return $this->classes[$name] ??= SchoolClass::query()
            ->where('school_id', $school->getKey())
            ->where('name', $name)
            ->firstOrFail();
    }

    /**
     * @param  array{subject?: string|null, name?: string}|null  $reference
     */
    private function competencyId(?array $reference): ?int
    {
        if ($reference === null) {
            return null;
        }

        $subject = $reference['subject'] ?? null;
        $query = Competency::query()->where('name', $reference['name']);

        return ($subject === null ? $query->whereNull('subject') : $query->where('subject', $subject))->value('id');
    }

    /**
     * A calendar day $daysAgo days before today, as stored in a date column. A negative number lies in the future.
     */
    private function date(CarbonInterface $today, int $daysAgo): string
    {
        return $today->copy()->subDays($daysAgo)->toDateString();
    }

    /**
     * The date-time $daysAgo days before today at the given time of day.
     */
    private function moment(CarbonInterface $today, int $daysAgo, string $time = '00:00'): CarbonInterface
    {
        return $today->copy()->subDays($daysAgo)->setTimeFromTimeString($time);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidShowcase
     */
    private function requireArray(mixed $value, string $path): array
    {
        if (! is_array($value) || array_is_list($value)) {
            $this->fail($path, 'fehlt oder ist kein Objekt');
        }

        return $value;
    }

    /**
     * @return list<mixed>
     *
     * @throws InvalidShowcase
     */
    private function requireList(mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail($path, 'fehlt oder ist keine Liste');
        }

        return $value;
    }

    /**
     * @throws InvalidShowcase
     */
    private function requireString(mixed $value, string $path): string
    {
        if (! is_string($value) || trim($value) === '') {
            $this->fail($path, 'fehlt oder ist kein Text');
        }

        return $value;
    }

    /**
     * Text that may be empty, e.g. an answer a student handed in without an attempt.
     *
     * @throws InvalidShowcase
     */
    private function requireText(mixed $value, string $path): string
    {
        if (! is_string($value)) {
            $this->fail($path, 'fehlt oder ist kein Text');
        }

        return $value;
    }

    /**
     * @throws InvalidShowcase
     */
    private function requireNullableString(mixed $value, string $path): ?string
    {
        return $value === null ? null : $this->requireText($value, $path);
    }

    /**
     * @throws InvalidShowcase
     */
    private function requireInt(mixed $value, string $path): int
    {
        if (! is_int($value)) {
            $this->fail($path, 'fehlt oder ist keine ganze Zahl');
        }

        return $value;
    }

    /**
     * @throws InvalidShowcase
     */
    private function requireNullableInt(mixed $value, string $path): ?int
    {
        return $value === null ? null : $this->requireInt($value, $path);
    }

    /**
     * @throws InvalidShowcase
     */
    private function requireNonNegativeInt(mixed $value, string $path): int
    {
        $number = $this->requireInt($value, $path);

        if ($number < 0) {
            $this->fail($path, 'darf nicht negativ sein');
        }

        return $number;
    }

    /**
     * @throws InvalidShowcase
     */
    private function requireBool(mixed $value, string $path): bool
    {
        if (! is_bool($value)) {
            $this->fail($path, 'fehlt oder ist kein Wahrheitswert');
        }

        return $value;
    }

    /**
     * @throws InvalidShowcase
     */
    private function requireScore(mixed $value, string $path): int
    {
        $score = $this->requireInt($value, $path);

        if ($score < 0 || $score > 100) {
            $this->fail($path, 'liegt außerhalb von 0 bis 100');
        }

        return $score;
    }

    /**
     * @throws InvalidShowcase
     */
    private function requireNullableScore(mixed $value, string $path): ?int
    {
        return $value === null ? null : $this->requireScore($value, $path);
    }

    /**
     * @throws InvalidShowcase
     */
    private function requireTime(mixed $value, string $path): string
    {
        if (! is_string($value) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            $this->fail($path, 'ist keine Uhrzeit im Format HH:MM');
        }

        return $value;
    }

    /**
     * A publication is either a day and a time or nothing at all.
     *
     * @param  array<string, mixed>  $lesson
     *
     * @throws InvalidShowcase
     */
    private function requirePublication(array $lesson, string $path): void
    {
        $days = $this->requireNullableInt($lesson['published_days_ago'] ?? null, "{$path}.published_days_ago");
        $time = $this->requireNullableTime($lesson['published_time'] ?? null, "{$path}.published_time");

        if (($days === null) !== ($time === null)) {
            $this->fail("{$path}.published_time", 'Tag und Uhrzeit der Veröffentlichung gehören zusammen');
        }
    }

    /**
     * @throws InvalidShowcase
     */
    private function requireNullableTime(mixed $value, string $path): ?string
    {
        return $value === null ? null : $this->requireTime($value, $path);
    }

    /**
     * @return list<string>
     *
     * @throws InvalidShowcase
     */
    private function requireStringList(mixed $value, string $path): array
    {
        $list = $this->requireList($value, $path);

        foreach ($list as $position => $entry) {
            if (! is_string($entry)) {
                $this->fail("{$path}[{$position}]", 'ist kein Text');
            }
        }

        return $list;
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     *
     * @throws InvalidShowcase
     */
    private function requireEnum(mixed $value, string $path, string $enum): BackedEnum
    {
        $case = $this->requireNullableEnum($value, $path, $enum);

        if ($case === null) {
            $this->fail($path, 'fehlt oder ist kein bekannter Wert');
        }

        return $case;
    }

    /**
     * Returns the matching case, or null when the field is absent.
     *
     * @param  class-string<BackedEnum>  $enum
     *
     * @throws InvalidShowcase
     */
    private function requireNullableEnum(mixed $value, string $path, string $enum): ?BackedEnum
    {
        if ($value === null) {
            return null;
        }

        $case = is_string($value) ? $enum::tryFrom($value) : null;

        if ($case === null) {
            $this->fail($path, 'unbekannt (erlaubt: '.implode(', ', array_map(fn (BackedEnum $allowed): string => (string) $allowed->value, $enum::cases())).')');
        }

        return $case;
    }

    /**
     * @throws InvalidShowcase
     */
    private function fail(string $path, string $problem): never
    {
        throw new InvalidShowcase("{$path}: {$problem}");
    }
}
