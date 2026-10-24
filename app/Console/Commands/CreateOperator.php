<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Role;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Creates the platform operator of an instance without demo mode: an account with role Operator that belongs to no
 * school and administers the registered schools and the competency framework.
 *
 * The password is only asked for interactively, never passed as an argument or option, so it cannot end up in the
 * shell history or in a process list. An existing username is never overwritten: the command stops instead, so an
 * operator account cannot be taken over by a second run.
 *
 * The username is normalised and checked the same way the registration form does it (trimmed, lowercase, lowercase
 * letters, digits, dot, minus and underscore), so an operator account can never carry a name the login form or the
 * school pages cannot handle.
 */
#[Signature('lernplattform:create-operator {username} {--name=Plattform-Administration}')]
#[Description('Legt die Plattform-Administration an (Passwort wird interaktiv abgefragt)')]
class CreateOperator extends Command
{
    /**
     * Shortest password the command accepts.
     */
    private const int MINIMUM_PASSWORD_LENGTH = 12;

    /**
     * bcrypt reads at most 72 bytes of a password, so anything longer would be accepted and then silently cut.
     */
    private const int MAXIMUM_PASSWORD_BYTES = 72;

    /**
     * Same character set as the registration form, which allows 3 to 30 characters; an operator name may be longer.
     */
    private const int MINIMUM_USERNAME_LENGTH = 3;

    private const int MAXIMUM_USERNAME_LENGTH = 50;

    public function handle(): int
    {
        $username = Str::lower(trim((string) $this->argument('username')));

        $hasValidUsername = preg_match('/^[a-z0-9._-]+$/', $username) === 1
            && mb_strlen($username) >= self::MINIMUM_USERNAME_LENGTH
            && mb_strlen($username) <= self::MAXIMUM_USERNAME_LENGTH;

        if (! $hasValidUsername) {
            $this->error('Der Benutzername muss '.self::MINIMUM_USERNAME_LENGTH.' bis '.self::MAXIMUM_USERNAME_LENGTH.' Zeichen lang sein und darf nur Kleinbuchstaben, Zahlen, Punkt, Minus und Unterstrich enthalten. Es wurde nichts geändert.');

            return self::FAILURE;
        }

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

        if (strlen($password) > self::MAXIMUM_PASSWORD_BYTES) {
            $this->error('Das Passwort darf höchstens '.self::MAXIMUM_PASSWORD_BYTES.' Byte lang sein, weil bcrypt den Rest abschneidet. Es wurde nichts geändert.');

            return self::FAILURE;
        }

        try {
            // "password" is hashed by the model's cast, so the plain text is never stored.
            $operator = User::query()->create([
                'name' => (string) $this->option('name'),
                'username' => $username,
                'password' => $password,
                'role' => Role::Operator,
                'school_id' => null,
                'school_class_id' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two runs at the same moment: the unique index decides, not the check above.
            $this->error('Der Benutzername „'.$username.'“ ist bereits vergeben. Es wurde nichts geändert.');

            return self::FAILURE;
        }

        $this->info('Die Plattform-Administration „'.$operator->username.'“ wurde angelegt und gehört zu keiner Schule.');

        return self::SUCCESS;
    }
}
