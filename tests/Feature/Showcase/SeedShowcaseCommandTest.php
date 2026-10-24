<?php

namespace Tests\Feature\Showcase;

use App\Models\Lesson;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The command is the only way the showcase file reaches a database: it decides where it may run, reports an
 * unusable file, and names the field of a file that cannot be imported as a whole.
 */
class SeedShowcaseCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private const array TABLES = ['schools', 'users', 'school_classes', 'competencies', 'lessons', 'questions', 'lesson_user', 'submissions', 'answers', 'review_items', 'explanation_sessions', 'explanation_messages', 'consent_forms', 'recording_consents'];

    public function test_the_command_refuses_to_run_outside_the_demo_environments(): void
    {
        $this->seedDemo();
        $countsBefore = $this->rowCounts();

        // Like on a server: the environment is detected before the command does anything.
        app()->detectEnvironment(fn (): string => 'production');

        $exitCode = Artisan::call('lernplattform:seed-showcase', ['--path' => $this->samplePath()]);
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('nur in local, testing, staging oder demo', $output);
        $this->assertSame($countsBefore, $this->rowCounts());
    }

    public function test_the_command_imports_the_sample_file_in_testing(): void
    {
        $this->seedDemo();
        $lessonsBefore = Lesson::query()->count();

        $exitCode = Artisan::call('lernplattform:seed-showcase', ['--path' => $this->samplePath()]);
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Lehrkräfte', $output);
        $this->assertStringContainsString('Einwilligungen', $output);
        $this->assertSame($lessonsBefore + 4, Lesson::query()->count());
        $this->assertDatabaseHas('users', ['username' => 'selin.kaya', 'role' => 'teacher']);
    }

    public function test_the_command_fails_without_the_base_demo(): void
    {
        // Without db:seed there is no demo school 4711 and nothing may be created.
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('lernplattform:seed-showcase', ['--path' => $this->samplePath()]);
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('php artisan db:seed', $output);
        $this->assertSame($countsBefore, $this->rowCounts());
    }

    public function test_the_command_reports_a_missing_file(): void
    {
        $this->seedDemo();
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('lernplattform:seed-showcase', ['--path' => base_path('database/seeders/demo/fehlt.json')]);
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('fehlt oder ist nicht lesbar', $output);
        $this->assertSame($countsBefore, $this->rowCounts());
    }

    public function test_the_command_reports_a_file_that_is_no_json(): void
    {
        $this->seedDemo();
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('lernplattform:seed-showcase', ['--path' => base_path('README.md')]);
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('kein gültiges JSON-Objekt', $output);
        $this->assertSame($countsBefore, $this->rowCounts());
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

    private function samplePath(): string
    {
        return database_path('seeders/demo/showcase.sample.json');
    }

    private function seedDemo(): void
    {
        $this->seed(DatabaseSeeder::class);
    }
}
