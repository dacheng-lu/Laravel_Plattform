<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\DemoMode;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Locks the demo accounts whose password was published in the repository: until now every demo account had the same
 * password, which anyone could read in the public code, and could therefore sign in as a platform operator, as a
 * school administration or as a teacher of an instance that still carried the demo data.
 *
 * The command only touches accounts whose username is one of DemoSchoolSeeder::USERNAMES and whose password still
 * matches one of COMPROMISED_PASSWORDS. Every other account and every account with a password of its own is left
 * alone. Nothing is deleted: role, school and all data stay as they are, only the password is replaced by a random
 * one and the remember token is dropped, so an existing session ends with the next request.
 *
 * Unlocking is a deliberate act: a school administration resets the password in the user management, or an operator
 * runs `php artisan lernplattform:create-operator <username>` for a fresh operator account. On a demo instance
 * (APP_ENV=demo with DEMO_MODE=true) the seeder hands out the password from DEMO_PASSWORD again.
 */
#[Signature('lernplattform:lock-demo-accounts')]
#[Description('Sperrt Demo-Konten mit dem früher öffentlichen Demo-Passwort (löscht keine Konten)')]
class LockCompromisedDemoAccounts extends Command
{
    /**
     * Passwords that were public in the repository and must never grant access on a real instance.
     */
    public const array COMPROMISED_PASSWORDS = ['hackathon2026'];

    /**
     * The replacement password is random and never printed; bcrypt ignores everything after 72 bytes, so 64 is safe.
     */
    private const int LOCKED_PASSWORD_LENGTH = 64;

    public function handle(): int
    {
        if (DemoMode::isActive()) {
            // The seeder sets the demo password from DEMO_PASSWORD, so locking would only break the demo accounts.
            $this->info('Demo mode is on, nothing locked.');

            return self::SUCCESS;
        }

        $locked = [];

        foreach (User::query()->whereIn('username', DemoSchoolSeeder::USERNAMES)->get() as $user) {
            if (! $this->hasCompromisedPassword($user)) {
                continue;
            }

            $user->forceFill([
                'password' => Str::password(self::LOCKED_PASSWORD_LENGTH),
                'remember_token' => null,
            ])->save();

            $locked[] = $user->username;
        }

        if ($locked === []) {
            $this->info('Gesperrte Demo-Konten: 0');

            return self::SUCCESS;
        }

        $this->warn('Gesperrte Demo-Konten: '.count($locked).' ('.implode(', ', $locked).'). Die Konten bleiben bestehen, das Passwort ist jetzt ein zufälliges.');
        $this->line('Passwort zurücksetzen: in der Benutzerverwaltung der Schule oder mit php artisan lernplattform:create-operator <name> für die Plattform-Administration.');

        return self::SUCCESS;
    }

    private function hasCompromisedPassword(User $user): bool
    {
        foreach (self::COMPROMISED_PASSWORDS as $compromisedPassword) {
            if (Hash::check($compromisedPassword, (string) $user->password)) {
                return true;
            }
        }

        return false;
    }
}
