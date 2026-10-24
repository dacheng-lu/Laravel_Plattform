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
     * The demo user is only created when demo mode is enabled and both demo
     * credentials are configured. Existing users are never overwritten.
     */
    public function run(): void
    {
        $username = config('app.demo.username');
        $password = config('app.demo.password');

        if (! config('app.demo.enabled') || ! filled($username) || ! filled($password)) {
            $this->command?->warn('Demo user skipped: set DEMO_MODE=true, DEMO_USERNAME and DEMO_PASSWORD to create it.');

            return;
        }

        if (User::where('username', $username)->exists()) {
            $this->command?->warn("Demo user '{$username}' already exists and was left unchanged.");

            return;
        }

        User::create([
            'username' => $username,
            'name' => 'Demo',
            'password' => $password,
        ]);
    }
}
