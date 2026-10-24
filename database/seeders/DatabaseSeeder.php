<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * The demo user is only created when demo mode is enabled and a demo
     * password is configured.
     */
    public function run(): void
    {
        if (! config('app.demo.enabled') || ! filled(config('app.demo.password'))) {
            $this->command?->warn('Demo user skipped: set DEMO_MODE=true and DEMO_PASSWORD to create it.');

            return;
        }

        User::updateOrCreate(
            ['username' => config('app.demo.username')],
            ['name' => 'Demo', 'password' => config('app.demo.password')],
        );
    }
}
