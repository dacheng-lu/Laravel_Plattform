<?php

namespace Tests\Feature;

use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LockCompromisedDemoAccountsCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The command only acts outside demo mode, which is also what an instance with real schools runs.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['lernplattform.demo.enabled' => false, 'lernplattform.demo.password' => null]);
    }

    public function test_it_locks_a_demo_account_that_still_has_the_public_password(): void
    {
        $user = User::factory()->teacher()->create(['username' => 'lehrer', 'password' => 'hackathon2026']);

        $this->artisan('lernplattform:lock-demo-accounts')->assertExitCode(0);

        $user->refresh();

        $this->assertFalse(Hash::check('hackathon2026', $user->password));
        $this->assertNull($user->remember_token);
        $this->assertSame(Role::Teacher, $user->role);
        $this->assertDatabaseHas('users', ['username' => 'lehrer']);
    }

    public function test_it_leaves_a_demo_account_with_a_password_of_its_own_alone(): void
    {
        $user = User::factory()->teacher()->create(['username' => 'lehrer', 'password' => 'eigenes-sicheres-passwort']);

        $this->artisan('lernplattform:lock-demo-accounts')->assertExitCode(0);

        $this->assertTrue(Hash::check('eigenes-sicheres-passwort', $user->refresh()->password));
    }

    public function test_it_leaves_accounts_of_other_usernames_alone(): void
    {
        // Documented limit: only the demo usernames are known to be compromised, so a weak password elsewhere stays.
        $user = User::factory()->student()->create(['username' => 'max.muster', 'password' => 'hackathon2026']);

        $this->artisan('lernplattform:lock-demo-accounts')->assertExitCode(0);

        $this->assertTrue(Hash::check('hackathon2026', $user->refresh()->password));
    }

    public function test_it_does_nothing_while_demo_mode_is_on(): void
    {
        config(['lernplattform.demo.enabled' => true, 'lernplattform.demo.password' => 'hackathon2026']);

        $user = User::factory()->operator()->create(['username' => 'hackathon', 'password' => 'hackathon2026']);

        $this->artisan('lernplattform:lock-demo-accounts')
            ->expectsOutputToContain('Demo mode is on, nothing locked.')
            ->assertExitCode(0);

        $this->assertTrue(Hash::check('hackathon2026', $user->refresh()->password));
    }

    public function test_a_second_run_locks_nothing(): void
    {
        User::factory()->teacher()->create(['username' => 'lehrer', 'password' => 'hackathon2026']);

        $this->artisan('lernplattform:lock-demo-accounts')->assertExitCode(0);
        $passwordAfterFirstRun = User::query()->where('username', 'lehrer')->sole()->password;

        $this->artisan('lernplattform:lock-demo-accounts')
            ->expectsOutputToContain('Gesperrte Demo-Konten: 0')
            ->assertExitCode(0);

        $this->assertSame($passwordAfterFirstRun, User::query()->where('username', 'lehrer')->sole()->password);
    }
}
