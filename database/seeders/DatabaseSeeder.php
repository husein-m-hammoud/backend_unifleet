<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Default admin account. Logs in locally (no provider) and sees every
        // vehicle/site/alert across all providers merged. Temporary until a
        // proper user-management screen exists.
        User::updateOrCreate(
            ['dsco_username' => 'admin'],
            [
                'name'     => 'Administrator',
                'email'    => 'admin@unifleet.local',
                'password' => Hash::make('admin'),
                'provider' => null,
                'is_admin' => true,
            ],
        );

        $this->call(SettingsSeeder::class);
    }
}
