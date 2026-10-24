<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\LearningProgress;
use App\Filament\Pages\MyHomework;
use App\Filament\Pages\ParentReport;
use App\Filament\Pages\WorkOnHomework;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Role;
use App\Support\LearningInsights;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private const array TABLES = ['schools', 'users', 'school_classes', 'competencies', 'lessons', 'questions', 'lesson_user', 'submissions', 'answers', 'explanation_sessions', 'explanation_messages'];

    /**
     * The demo data only exists in demo mode; every test below that seeds it switches it on here.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['lernplattform.demo.enabled' => true, 'lernplattform.demo.password' => 'hackathon2026']);
    }

    public function test_seeding_twice_creates_no_duplicates(): void
    {
        $this->seed(DatabaseSeeder::class);
        $countsAfterFirstRun = $this->rowCounts();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($countsAfterFirstRun, $this->rowCounts());
        $this->assertSame(2, $countsAfterFirstRun['schools']);
        $this->assertSame(20, $countsAfterFirstRun['users']);
        $this->assertSame(3, $countsAfterFirstRun['school_classes']);
        $this->assertSame(6, $countsAfterFirstRun['lessons']);
    }

    public function test_the_demo_logins_exist_with_their_roles(): void
    {
        $existingAccount = User::factory()->admin()->create(['username' => 'hackathon']);

        $this->seed(DatabaseSeeder::class);

        $roles = [
            'hackathon' => Role::Operator,
            'schulleitung' => Role::Admin,
            'lehrer' => Role::Teacher,
            'weber' => Role::Teacher,
            'schueler' => Role::Student,
            'gym-admin' => Role::Admin,
            'gym-lehrer' => Role::Teacher,
            'gym-schueler1' => Role::Student,
        ];

        foreach ($roles as $username => $role) {
            $user = User::query()->where('username', $username)->sole();

            $this->assertSame($role, $user->role, $username);
            $this->assertTrue(Hash::check('hackathon2026', $user->password), $username);
        }

        $this->assertSame($existingAccount->id, User::query()->where('username', 'hackathon')->value('id'));

        $mia = User::query()->where('username', 'schueler')->sole();
        $this->assertSame('Mia Hoffmann', $mia->name);
        $this->assertSame('7b', $mia->schoolClass->name);
        $this->assertSame('lehrer', $mia->schoolClass->teacher->username);
        $this->assertSame('Petra Krause', User::query()->where('username', 'schulleitung')->value('name'));
    }

    public function test_every_demo_account_of_the_login_page_exists_with_the_listed_role(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (Login::DEMO_ACCOUNTS as $username => $role) {
            $this->assertSame($role, User::query()->where('username', $username)->sole()->role, $username);
        }
    }

    public function test_there_are_two_demo_schools_and_the_operator_belongs_to_none(): void
    {
        $this->seed(DatabaseSeeder::class);

        $lernpark = School::query()->where('name', 'Gesamtschule am Lernpark')->sole();
        $this->assertSame(['Gesamtschule', 'Fulda', 'Hessen', '4711'], [$lernpark->school_type, $lernpark->city, $lernpark->state, $lernpark->school_number]);

        $gymnasium = School::query()->where('name', 'Gymnasium Musterstadt')->sole();
        $this->assertSame(['Gymnasium', 'Musterstadt', 'Niedersachsen'], [$gymnasium->school_type, $gymnasium->city, $gymnasium->state]);

        $this->assertSame(['hackathon'], User::query()->whereNull('school_id')->pluck('username')->all());
        $this->assertTrue(User::query()->where('username', 'hackathon')->sole()->isOperator());

        $this->assertSame($lernpark->id, User::query()->where('username', 'schulleitung')->value('school_id'));
        $this->assertSame($gymnasium->id, User::query()->where('username', 'gym-admin')->value('school_id'));
        $this->assertNull($lernpark->blocked_at);
        $this->assertNull($gymnasium->blocked_at);
    }

    public function test_every_demo_user_class_and_lesson_belongs_to_the_right_school(): void
    {
        $this->seed(DatabaseSeeder::class);

        $lernpark = School::query()->where('name', 'Gesamtschule am Lernpark')->sole();
        $gymnasium = School::query()->where('name', 'Gymnasium Musterstadt')->sole();

        $this->assertSame(['7a', '7b'], $lernpark->schoolClasses()->orderBy('name')->pluck('name')->all());
        $this->assertSame(['8c'], $gymnasium->schoolClasses()->pluck('name')->all());
        $this->assertSame(['gym-admin', 'gym-lehrer', 'gym-schueler1', 'gym-schueler2'], $gymnasium->users()->orderBy('username')->pluck('username')->all());
        $this->assertSame(15, $lernpark->users()->count());

        foreach (User::query()->whereNotNull('school_class_id')->with('schoolClass')->get() as $student) {
            $this->assertSame($student->schoolClass->school_id, $student->school_id, $student->username);
        }

        foreach (SchoolClass::query()->with('teacher')->get() as $schoolClass) {
            $this->assertSame($schoolClass->school_id, $schoolClass->teacher->school_id, $schoolClass->name);
        }

        foreach (Lesson::query()->with(['schoolClass', 'teacher', 'students'])->get() as $lesson) {
            $this->assertSame($lesson->schoolClass->school_id, $lesson->teacher->school_id, $lesson->topic);
            $this->assertEmpty($lesson->students->where('school_id', '!=', $lesson->schoolClass->school_id), $lesson->topic);
        }

        $this->assertSame(['Lineare Funktionen'], Lesson::query()->whereRelation('schoolClass', 'school_id', $gymnasium->id)->pluck('topic')->all());
        $this->assertSame(5, Lesson::query()->whereRelation('schoolClass', 'school_id', $lernpark->id)->count());
    }

    public function test_every_demo_class_has_its_own_join_code(): void
    {
        $this->seed(DatabaseSeeder::class);
        $codes = SchoolClass::query()->pluck('join_code');

        $this->assertCount(3, $codes);
        $this->assertCount(3, $codes->filter()->unique());
    }

    public function test_accounts_and_classes_from_before_the_schools_existed_move_into_the_demo_school(): void
    {
        $teacher = User::factory()->teacher()->create(['username' => 'lehrer', 'school_id' => null]);
        $schoolClass = SchoolClass::factory()->create(['name' => '7b', 'teacher_id' => $teacher->id, 'school_id' => null]);
        $mia = User::factory()->student($schoolClass)->create(['username' => 'schueler', 'school_id' => null]);
        $joinCode = $schoolClass->join_code;

        $this->seed(DatabaseSeeder::class);

        $lernpark = School::query()->where('name', 'Gesamtschule am Lernpark')->sole();

        $this->assertSame($lernpark->id, $teacher->refresh()->school_id);
        $this->assertSame($lernpark->id, $mia->refresh()->school_id);
        $this->assertSame($lernpark->id, $schoolClass->refresh()->school_id);
        $this->assertSame($joinCode, $schoolClass->join_code);
        $this->assertSame(1, SchoolClass::query()->where('name', '7b')->count());
        $this->assertSame($schoolClass->id, $mia->school_class_id);
    }

    public function test_the_demo_lessons_cover_every_state(): void
    {
        $this->seed(DatabaseSeeder::class);
        $mia = User::query()->where('username', 'schueler')->sole();

        $photosynthesis = Lesson::query()->where('topic', 'Fotosynthese')->sole();
        $this->assertCount(8, $photosynthesis->students);
        $this->assertCount(4, $photosynthesis->questions);
        $this->assertTrue($photosynthesis->submissions()->where('student_id', $mia->id)->whereNotNull('evaluated_at')->exists());

        $pythagoras = Lesson::query()->where('topic', 'Satz des Pythagoras')->sole();
        $this->assertTrue($pythagoras->students->contains($mia));
        $this->assertFalse($pythagoras->submissions()->where('student_id', $mia->id)->exists());

        $draft = Lesson::query()->where('topic', 'Ursachen der Französischen Revolution')->sole();
        $this->assertFalse($draft->isPublished());
        $this->assertCount(0, $draft->questions);

        $remedial = Lesson::query()->where('topic', 'Flächeninhalt von Dreiecken (Förderaufgabe)')->sole();
        $this->assertFalse($remedial->isPracticeTask());
        $this->assertSame(['ben.krueger', 'noah.wagner', 'schueler'], $remedial->students->pluck('username')->sort()->values()->all());

        $this->assertSame(1, $mia->explanationSessions()->whereNotNull('finished_at')->count());
        $this->assertSame(1, $mia->explanationSessions()->whereNull('finished_at')->count());

        $linear = Lesson::query()->where('topic', 'Lineare Funktionen')->sole();
        $this->assertTrue($linear->isPublished());
        $this->assertSame(['gym-schueler1', 'gym-schueler2'], $linear->students->pluck('username')->sort()->values()->all());
        $this->assertCount(2, $linear->questions);
        $this->assertNotContains(null, $linear->questions->pluck('competency_id')->all());
    }

    public function test_the_demo_foerderaufgabe_goes_to_exactly_the_students_who_need_support_in_its_competency(): void
    {
        $this->seed(DatabaseSeeder::class);
        $mia = User::query()->where('username', 'schueler')->sole();

        $task = Lesson::query()->whereNotNull('practice_competency_id')->sole();
        $this->assertSame('Probleme mathematisch lösen', $task->practiceCompetency->name);
        $this->assertSame('7b', $task->schoolClass->name);
        $this->assertSame('lehrer', $task->teacher->username);
        $this->assertTrue($task->isPublished());
        $this->assertTrue($task->due_on->isFuture());
        $this->assertSame(0, $task->submissions()->count());

        $this->assertCount(3, $task->questions);
        $this->assertSame([$task->practice_competency_id], $task->questions->pluck('competency_id')->unique()->all());

        $studentIds = User::query()->withRole(Role::Student)->pluck('id')->all();
        $support = LearningInsights::competencySupport(LearningInsights::supportNeeds(LearningInsights::scoredAnswers($studentIds)), $task->practice_competency_id);

        $this->assertEqualsCanonicalizing($support['student_ids'], $task->students->modelKeys());
        $this->assertSame(['ben.krueger', 'schueler'], $task->students->pluck('username')->sort()->values()->all());

        $practiceRow = LearningInsights::studentCompetencies($mia)['practice']->firstWhere('competency.id', $task->practice_competency_id);
        $this->assertTrue($practiceRow['practice_task']->is($task));

        $this->actingAs($mia);

        Livewire::test(MyHomework::class)->assertCanSeeTableRecords([$task])->assertSee('Förderaufgabe');
        $this->get(WorkOnHomework::getUrl(['lesson' => $task]))->assertOk()->assertSee('Förderaufgabe zu „Probleme mathematisch lösen“');
    }

    public function test_the_insight_pages_render_the_demo_data(): void
    {
        $this->seed(DatabaseSeeder::class);
        $teacher = User::query()->where('username', 'lehrer')->sole();
        $mia = User::query()->where('username', 'schueler')->sole();

        $this->actingAs($teacher)
            ->get(LearningProgress::getUrl())
            ->assertOk()
            ->assertSee(['Ben Krüger', 'Probleme mathematisch lösen', 'Eigenes Lernen reflektieren', 'Satz des Pythagoras']);

        $this->actingAs($teacher)
            ->get(ParentReport::getUrl(['student' => $mia]))
            ->assertOk()
            ->assertSee(['Steigend', 'Offen', 'Noch nicht abgeschlossen', 'Probleme mathematisch lösen (Mathematik): 38 Punkte']);
    }

    public function test_seeders_do_not_depend_on_dev_packages(): void
    {
        foreach (glob(database_path('seeders/*.php')) as $seeder) {
            $this->assertDoesNotMatchRegularExpression('/::factory\(|\bfake\(|Faker/', file_get_contents($seeder), basename($seeder));
        }
    }

    public function test_without_demo_mode_only_the_competency_framework_is_seeded(): void
    {
        config(['lernplattform.demo.enabled' => false]);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('schools', 0);
        $this->assertGreaterThan(0, Competency::query()->count());
    }

    public function test_the_demo_school_seeder_alone_does_nothing_without_demo_mode(): void
    {
        config(['lernplattform.demo.enabled' => false]);

        $this->seed(DemoSchoolSeeder::class);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('schools', 0);
    }

    public function test_demo_accounts_take_the_password_from_the_environment(): void
    {
        config(['lernplattform.demo.password' => 'anderes-demo-passwort-1']);

        $this->seed(DatabaseSeeder::class);

        foreach (['hackathon', 'schueler'] as $username) {
            $password = User::query()->where('username', $username)->sole()->password;

            $this->assertTrue(Hash::check('anderes-demo-passwort-1', $password), $username);
            $this->assertFalse(Hash::check('hackathon2026', $password), $username);
        }
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return collect(self::TABLES)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
