<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(EmailEventsSeeder::class);
        $this->seedOwnerAccount();
        $this->call(SettingsSeeder::class);
    }

    /**
     * Create the first owner account — the one used to log in and create every
     * other user.
     *
     * Credentials come from the environment (ADMIN_USERNAME / ADMIN_PASSWORD /
     * ADMIN_EMAIL). When no password is set, a strong one is generated and
     * printed once; it is never written to a file or committed.
     *
     * Two deliberate behaviours, both of which the previous version got wrong:
     *
     * 1. It creates, and never updates. The old code used updateOrCreate(), so
     *    every subsequent `db:seed` silently reset the owner's password back to
     *    the seeded value — re-opening the hole long after anyone had changed
     *    it. Re-running this seeder now leaves an existing account untouched.
     *
     * 2. It sets `role` explicitly. isManager()/isOwner() read `role`, and the
     *    column defaults to 'user'. The migration that backfills
     *    role='owner' runs against an EMPTY table on a fresh database, so an
     *    account created here without an explicit role came out as a scoped
     *    `user`: locked out of settings, users, zones and the owner-only System
     *    page, and — with no zone grants — seeing zero vehicles. That never
     *    showed up in dev, where the admin row predates the migration and was
     *    backfilled by it.
     */
    private function seedOwnerAccount(): void
    {
        $username = (string) env('ADMIN_USERNAME', 'admin');

        if ($existing = User::where('dsco_username', $username)->first()) {
            $this->command?->info(
                "Owner account '{$username}' already exists (role: {$existing->role}) — left unchanged."
            );

            // Self-heal the one case that would otherwise lock us out: an owner
            // seeded before this fix, sitting at the default 'user' role.
            if ($existing->role === 'user' && $existing->is_admin) {
                $existing->forceFill(['role' => 'owner', 'all_zones' => true])->save();
                $this->command?->warn("  -> promoted '{$username}' to owner (was 'user' with is_admin set).");
            }

            return;
        }

        $password  = (string) env('ADMIN_PASSWORD', '');
        $generated = $password === '';

        if ($generated) {
            $password = Str::password(24);
        }

        User::create([
            'name'          => (string) env('ADMIN_NAME', 'Administrator'),
            'email'         => env('ADMIN_EMAIL'),   // nullable; needed for password reset
            'dsco_username' => $username,
            'password'      => $password,            // 'hashed' cast hashes this
            'provider'      => null,
            'is_admin'      => true,
            'role'          => 'owner',
            'status'        => 'active',
            'all_zones'     => true,
        ]);

        $this->command?->info("Owner account '{$username}' created.");

        if ($generated) {
            $this->command?->newLine();
            $this->command?->warn('  Generated password (shown once, store it in your password manager now):');
            $this->command?->line("    {$password}");
            $this->command?->newLine();
        }
    }
}
