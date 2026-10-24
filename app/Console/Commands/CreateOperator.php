<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Role;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Creates the platform operator of an instance without demo mode: an account with role Operator that belongs to no
 * school and administers the registered schools and the competency framework.
 *
 * The password is only asked for interactively, never passed as an argument or option, so it cannot end up in the
 * shell history or in a process list. An existing username is never overwritten: the command stops instead, so an
 * operator account cannot be taken over by a second run.
 */
#[Signature('lernplattform:create-operator {username} {--name=Plattform-Administration}')]
#[Description('Legt die Plattform-Administration an (Passwort wird interaktiv abgefragt)')]
class CreateOperator extends Command
{
    /**
     * Shortest password the command accepts.
     */
    private const int MINIMUM_PASSWORD_LENGTH = 12;

    public function handle(): int
    {
        $username = (string) $this->argument('username');

        if (User::query()->where('username', $username)->exists()) {
            $this->error('Der Benutzername „'.$username.'“ ist bereits vergeben. Es wurde nichts geändert.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Passwort');
        $repeatedPassword = (string) $this->secret('Passwort wiederholen');

        if ($password !== $repeatedPassword) {
            $this->error('Die beiden Passwörter stimmen nicht überein. Es wurde nichts geändert.');

            return self::FAILURE;
        }

        if (mb_strlen($password) < self::MINIMUM_PASSWORD_LENGTH) {
            $this->error('Das Passwort muss mindestens '.self::MINIMUM_PASSWORD_LENGTH.' Zeichen lang sein. Es wurde nichts geändert.');

            return self::FAILURE;
        }

        // "password" is hashed by the model's cast, so the plain text is never stored.
        $operator = User::query()->create([
            'name' => (string) $this->option('name'),
            'username' => $username,
            'password' => $password,
            'role' => Role::Operator,
            'school_id' => null,
            'school_class_id' => null,
        ]);

        $this->info('Die Plattform-Administration „'.$operator->username.'“ wurde angelegt und gehört zu keiner Schule.');

        return self::SUCCESS;
    }
}
