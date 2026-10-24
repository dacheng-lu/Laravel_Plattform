<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Closure;
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

    /**
     * The config file is required directly instead of refreshing the application, because a fresh
     * boot lets LoadEnvironmentVariables rewrite the environment through Laravel's immutable env
     * repository and would silently mask the values prepared by this test.
     */
    #[DataProvider('demoModeValues')]
    public function test_demo_mode_env_value_is_parsed_strictly(string $value, bool $expected): void
    {
        $this->withEnvironment(['APP_ENV' => 'testing', 'DEMO_MODE' => $value], function () use ($expected): void {
            $config = require config_path('app.php');

            $this->assertSame($expected, $config['demo']['enabled']);
        });
    }

    public function test_demo_mode_is_always_disabled_in_production(): void
    {
        $this->withEnvironment(['APP_ENV' => 'production', 'DEMO_MODE' => 'true'], function (): void {
            $config = require config_path('app.php');

            $this->assertFalse($config['demo']['enabled']);
        });
    }

    public function test_demo_mode_is_disabled_when_the_variable_is_not_set(): void
    {
        $this->withEnvironment(['APP_ENV' => 'testing', 'DEMO_MODE' => null], function (): void {
            $config = require config_path('app.php');

            $this->assertFalse($config['demo']['enabled']);
        });
    }

    public function test_demo_mode_can_be_enabled_outside_production(): void
    {
        $this->withEnvironment(['APP_ENV' => 'staging', 'DEMO_MODE' => 'true'], function (): void {
            $config = require config_path('app.php');

            $this->assertTrue($config['demo']['enabled']);
        });
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

    /**
     * Run the callback with the given environment values and restore the previous state afterwards.
     *
     * A null value removes the variable for the duration of the callback.
     *
     * @param  array<string, string|null>  $values
     */
    private function withEnvironment(array $values, Closure $callback): mixed
    {
        $original = [];

        foreach ($values as $name => $value) {
            $original[$name] = [
                'env' => $_ENV[$name] ?? null,
                'server' => $_SERVER[$name] ?? null,
                'putenv' => getenv($name),
            ];

            $this->setEnvironmentVariable($name, $value);
        }

        try {
            return $callback();
        } finally {
            foreach ($original as $name => $previous) {
                $this->restoreEnvironmentVariable($name, $previous);
            }
        }
    }

    /**
     * Write an environment variable to $_ENV, $_SERVER and the process environment, or remove it.
     */
    private function setEnvironmentVariable(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            return;
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv("{$name}={$value}");
    }

    /**
     * Restore a single environment variable to its previously captured state.
     *
     * @param  array{env: string|null, server: string|null, putenv: string|false}  $previous
     */
    private function restoreEnvironmentVariable(string $name, array $previous): void
    {
        if ($previous['env'] === null) {
            unset($_ENV[$name]);
        } else {
            $_ENV[$name] = $previous['env'];
        }

        if ($previous['server'] === null) {
            unset($_SERVER[$name]);
        } else {
            $_SERVER[$name] = $previous['server'];
        }

        $previousPutenv = $previous['putenv'];

        if ($previousPutenv === false) {
            putenv($name);
        } else {
            putenv("{$name}={$previousPutenv}");
        }
    }
}
