<?php

namespace Database\Seeders;

use App\Models\User;
use App\Role;
use App\Support\DemoMode;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs on every deploy, so every seeder must be idempotent and must not use factories (no dev dependencies in production).
 *
 * The competency framework is seeded always. The platform operator and the demo schools are seeded only in demo mode
 * (see App\Support\DemoMode); on every other instance they are skipped, demo accounts that still carry the password
 * published in the repository are locked (lernplattform:lock-demo-accounts, nothing is deleted) and the operator is
 * created with `php artisan lernplattform:create-operator <username>` instead.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(CompetencyFrameworkSeeder::class);

        if (! DemoMode::isActive()) {
            // Through the kernel, so locking also works when the seeder runs from another seeder or a test.
            Artisan::call('lernplattform:lock-demo-accounts');

            $this->command?->warn('Demo data skipped: demo mode is off. Demo accounts with the public demo password were locked. Create the platform operator with: php artisan lernplattform:create-operator <username>');

            return;
        }

        // The demo platform operator belongs to no school (see DemoSchoolSeeder for the demo schools).
        User::updateOrCreate(
            ['username' => 'hackathon'],
            ['name' => 'Hackathon', 'password' => DemoMode::password(), 'role' => Role::Operator, 'school_id' => null, 'school_class_id' => null],
        );

        $this->call(DemoSchoolSeeder::class);
    }
}
