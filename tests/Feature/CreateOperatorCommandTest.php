<?php

namespace Tests\Feature;

use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateOperatorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_command_creates_a_platform_operator(): void
    {
        $this->artisan('lernplattform:create-operator', ['username' => 'plattform-admin'])
            ->expectsQuestion('Passwort', 'sicheres-passwort-1')
            ->expectsQuestion('Passwort wiederholen', 'sicheres-passwort-1')
            ->assertExitCode(0);

        $operator = User::query()->where('username', 'plattform-admin')->sole();

        $this->assertSame(Role::Operator, $operator->role);
        $this->assertSame('Plattform-Administration', $operator->name);
        $this->assertNull($operator->school_id);
        $this->assertNull($operator->school_class_id);
        $this->assertNotSame('sicheres-passwort-1', $operator->password);
        $this->assertTrue(Hash::check('sicheres-passwort-1', $operator->password));
    }

    public function test_the_name_can_be_given_as_an_option(): void
    {
        $this->artisan('lernplattform:create-operator', ['username' => 'plattform-admin', '--name' => 'Dacheng Lu'])
            ->expectsQuestion('Passwort', 'sicheres-passwort-1')
            ->expectsQuestion('Passwort wiederholen', 'sicheres-passwort-1')
            ->assertExitCode(0);

        $this->assertSame('Dacheng Lu', User::query()->where('username', 'plattform-admin')->sole()->name);
    }

    public function test_an_existing_username_is_never_overwritten(): void
    {
        $existing = User::factory()->operator()->create(['username' => 'plattform-admin']);
        $passwordBefore = $existing->password;
        $countBefore = User::query()->count();

        $this->artisan('lernplattform:create-operator', ['username' => 'plattform-admin'])
            ->assertExitCode(1);

        $this->assertSame($countBefore, User::query()->count());
        $this->assertSame($passwordBefore, $existing->refresh()->password);
        $this->assertTrue(Hash::check('password', $existing->password));
    }

    public function test_mismatched_passwords_create_nothing(): void
    {
        $this->artisan('lernplattform:create-operator', ['username' => 'plattform-admin'])
            ->expectsQuestion('Passwort', 'sicheres-passwort-1')
            ->expectsQuestion('Passwort wiederholen', 'sicheres-passwort-2')
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->count());
    }

    public function test_a_password_shorter_than_twelve_characters_is_rejected(): void
    {
        $this->artisan('lernplattform:create-operator', ['username' => 'plattform-admin'])
            ->expectsQuestion('Passwort', str_repeat('a', 11))
            ->expectsQuestion('Passwort wiederholen', str_repeat('a', 11))
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->count());
    }

    public function test_a_password_of_twelve_characters_is_accepted(): void
    {
        $this->artisan('lernplattform:create-operator', ['username' => 'plattform-admin'])
            ->expectsQuestion('Passwort', str_repeat('a', 12))
            ->expectsQuestion('Passwort wiederholen', str_repeat('a', 12))
            ->assertExitCode(0);

        $this->assertTrue(Hash::check(str_repeat('a', 12), User::query()->where('username', 'plattform-admin')->sole()->password));
    }
}
