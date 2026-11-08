<?php

namespace Tests\Feature;

use Dotenv\Dotenv;
use Tests\TestCase;

/**
 * Asserts that .env.example actually parses.
 *
 * This is here because it broke a production deploy. The file is copied to .env
 * as the first step of setting up a server, and a malformed line makes EVERY
 * artisan command fail immediately with "Failed to parse dotenv file" - before
 * key:generate, before migrate, before anything. There is no application left
 * running to diagnose it with.
 *
 * It broke in a way reading the file does not reveal: an edit intended for the
 * MAIL_MAILER setting matched the same text inside a nearby comment instead,
 * which split the comment open and left the trailing words of that sentence as
 * a value line. The file still looked like prose and comments.
 *
 * Uses the same parser Laravel does, so agreement here means agreement at boot.
 */
class EnvExampleTest extends TestCase
{
    public function test_env_example_parses(): void
    {
        $path = base_path();

        $this->assertFileExists($path.'/.env.example');

        $vars = Dotenv::createArrayBacked($path, '.env.example')->load();

        $this->assertNotEmpty($vars, '.env.example parsed to nothing.');
    }

    public function test_env_example_defines_the_keys_setup_depends_on(): void
    {
        $vars = Dotenv::createArrayBacked(base_path(), '.env.example')->load();

        // Not every key - just the ones whose absence breaks a fresh install in
        // a way that is awkward to trace back to a missing line.
        foreach ([
            'APP_ENV', 'APP_DEBUG', 'APP_KEY', 'FRONTEND_URL',
            'DB_CONNECTION', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME',
            'MAIL_MAILER', 'SAFEE_DEFAULT_PROVIDER',
            'ADMIN_USERNAME', 'ADMIN_PASSWORD',
        ] as $key) {
            $this->assertArrayHasKey($key, $vars, "{$key} is missing from .env.example");
        }
    }

    public function test_the_default_provider_is_one_that_is_actually_enabled(): void
    {
        $vars = Dotenv::createArrayBacked(base_path(), '.env.example')->load();

        // A fresh server copies this file, so a default naming a provider that
        // is commented out of config/services.php points the poller at nothing.
        // This is exactly what SAFEE_DEFAULT_PROVIDER=alrakeen did.
        $this->assertArrayHasKey(
            $vars['SAFEE_DEFAULT_PROVIDER'],
            config('services.safee.providers'),
            'SAFEE_DEFAULT_PROVIDER in .env.example names a provider that is not enabled in config/services.php',
        );
    }
}
