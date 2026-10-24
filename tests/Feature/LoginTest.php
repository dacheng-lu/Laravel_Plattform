<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_asset_urls_use_https_behind_a_tls_terminating_proxy(): void
    {
        $this->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('/login')
            ->assertOk()
            ->assertSee('src="https://', false)
            ->assertDontSee('src="http://', false);
    }

    public function test_the_seeded_default_user_can_log_in_with_username(): void
    {
        $this->enableDemoMode();

        $this->seed(DatabaseSeeder::class);

        Livewire::test(Login::class)
            ->fillForm(['username' => 'hackathon', 'password' => 'hackathon2026'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticated();
        $this->get('/')->assertOk()->assertSee('Mein Lernbereich');
    }

    public function test_the_login_form_is_prefilled_with_the_demo_credentials(): void
    {
        $this->enableDemoMode();

        Livewire::test(Login::class)
            ->assertFormSet(['username' => 'hackathon', 'password' => 'hackathon2026']);
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $this->enableDemoMode();

        $this->seed(DatabaseSeeder::class);

        Livewire::test(Login::class)
            ->fillForm(['username' => 'hackathon', 'password' => 'falsch'])
            ->call('authenticate')
            ->assertHasFormErrors(['username']);

        $this->assertGuest();
    }

    public function test_the_login_form_is_empty_when_demo_mode_is_disabled(): void
    {
        config([
            'app.demo.enabled' => false,
            'app.demo.username' => 'hackathon',
            'app.demo.password' => 'hackathon2026',
        ]);

        Livewire::test(Login::class)
            ->assertFormSet(['username' => null, 'password' => null]);
    }

    public function test_the_login_form_is_empty_when_demo_password_is_missing(): void
    {
        config([
            'app.demo.enabled' => true,
            'app.demo.username' => 'hackathon',
            'app.demo.password' => '',
        ]);

        Livewire::test(Login::class)
            ->assertFormSet(['username' => null, 'password' => null]);
    }

    public function test_the_login_form_is_empty_when_demo_username_is_missing(): void
    {
        config([
            'app.demo.enabled' => true,
            'app.demo.username' => '',
            'app.demo.password' => 'hackathon2026',
        ]);

        Livewire::test(Login::class)
            ->assertFormSet(['username' => null, 'password' => null]);
    }

    public function test_the_seeder_does_not_create_a_demo_user_when_demo_mode_is_disabled(): void
    {
        config([
            'app.demo.enabled' => false,
            'app.demo.username' => 'hackathon',
            'app.demo.password' => 'hackathon2026',
        ]);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_seeder_does_not_create_a_demo_user_when_demo_password_is_missing(): void
    {
        config([
            'app.demo.enabled' => true,
            'app.demo.username' => 'hackathon',
            'app.demo.password' => '',
        ]);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_seeder_does_not_create_a_demo_user_when_demo_username_is_missing(): void
    {
        config([
            'app.demo.enabled' => true,
            'app.demo.username' => '',
            'app.demo.password' => 'hackathon2026',
        ]);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_seeder_creates_the_demo_user_in_demo_mode(): void
    {
        $this->enableDemoMode();

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', ['username' => 'hackathon']);
    }

    public function test_the_seeder_does_not_overwrite_an_existing_user(): void
    {
        User::factory()->create([
            'username' => 'hackathon',
            'password' => 'original-secret',
        ]);

        $this->enableDemoMode();

        $this->seed(DatabaseSeeder::class);

        $user = User::where('username', 'hackathon')->firstOrFail();

        $this->assertTrue(Hash::check('original-secret', $user->password));
        $this->assertDatabaseCount('users', 1);
    }

    #[DataProvider('demoModeValues')]
    public function test_demo_mode_env_value_is_parsed_strictly(string $value, bool $expected): void
    {
        $_ENV['DEMO_MODE'] = $value;
        $_SERVER['DEMO_MODE'] = $value;
        putenv("DEMO_MODE={$value}");

        try {
            $this->refreshApplication();

            $this->assertSame($expected, config('app.demo.enabled'));
        } finally {
            unset($_ENV['DEMO_MODE'], $_SERVER['DEMO_MODE']);
            putenv('DEMO_MODE');
        }
    }

    /**
     * Provide raw DEMO_MODE values and the expected boolean interpretation.
     *
     * @return array<string, array{string, bool}>
     */
    public static function demoModeValues(): array
    {
        return [
            'no' => ['no', false],
            'off' => ['off', false],
            'zero' => ['0', false],
            'false' => ['false', false],
            'empty string' => ['', false],
            'disabled' => ['disabled', false],
            'true' => ['true', true],
            'one' => ['1', true],
            'on' => ['on', true],
            'yes' => ['yes', true],
        ];
    }

    /**
     * Enable demo mode with the credentials used by the tests.
     */
    private function enableDemoMode(): void
    {
        config([
            'app.demo.enabled' => true,
            'app.demo.username' => 'hackathon',
            'app.demo.password' => 'hackathon2026',
        ]);
    }
}
