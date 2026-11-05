<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Sends one plain test email, so mail delivery can be proven on the server
 * before any real account is created.
 *
 * This exists because the failure mode is invisible. `MAIL_MAILER=log` writes
 * every message to storage/logs/laravel.log and reports success, and
 * UserController::tryNotify() swallows transport errors so creating a user
 * succeeds either way. Without this command the first evidence that mail is
 * broken is a person telling you they never got their password.
 *
 * CLI only, deliberately — there is no HTTP route for this. A send-test
 * endpoint was added and reverted twice; an authenticated-but-reachable
 * "send mail to an address of your choosing" API is a spam relay waiting to be
 * found, while an artisan command needs shell access on the box.
 */
class MailTestCommand extends Command
{
    protected $signature = 'unifleet:mail-test
                            {address : Where to send the test message}';

    protected $description = 'Send one test email to prove mail delivery works';

    public function handle(): int
    {
        $address = (string) $this->argument('address');

        if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            $this->error("Not a valid email address: {$address}");

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        $from   = (string) config('mail.from.address');

        $this->newLine();
        $this->line('  Mailer    : ' . $mailer);

        if ($mailer === 'smtp' || $mailer === 'directmail') {
            $this->line('  Host      : ' . config("mail.mailers.{$mailer}.host")
                . ':' . config("mail.mailers.{$mailer}.port"));
            $this->line('  Username  : ' . (config("mail.mailers.{$mailer}.username") ?: '(none)'));
        }

        $this->line('  From      : ' . $from . ' <' . config('mail.from.name') . '>');
        $this->line('  To        : ' . $address);
        $this->newLine();

        // The whole point of the command is defeated if this case passes quietly.
        if ($mailer === 'log' || $mailer === 'array') {
            $this->warn("  MAIL_MAILER is '{$mailer}' — NOTHING IS SENT.");
            $this->line("  The message is written to storage/logs/laravel.log instead.");
            $this->line('  Configure a real mailer before creating any user account,');
            $this->line('  or they will be told their account is ready and receive nothing.');
            $this->newLine();

            return self::FAILURE;
        }

        if ($from === '' || str_contains($from, 'example.com')) {
            $this->warn('  MAIL_FROM_ADDRESS is unset or still the example value.');
            $this->line('  Most providers reject or spam-filter an unrecognised sender.');
            $this->newLine();
        }

        try {
            Mail::raw(
                "This is a UNIFLEET test message.\n\n"
                . "If you are reading it, outbound mail works: account-setup and\n"
                . "password-reset emails will reach their recipients.\n\n"
                . 'Sent ' . now()->toDateTimeString() . ' (' . config('app.timezone') . ")\n"
                . 'From host: ' . gethostname() . "\n",
                function ($message) use ($address) {
                    $message->to($address)->subject('UNIFLEET mail test');
                },
            );
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('  Send FAILED: ' . $e->getMessage());
            $this->newLine();
            $this->line('  Common causes:');
            $this->line('   - the provider rejects the From address (it must be one it owns)');
            $this->line('   - SMTP AUTH is disabled for the mailbox (Microsoft 365 does this by default)');
            $this->line('   - outbound port 587/465 is blocked by the security group');
            $this->newLine();

            return self::FAILURE;
        }

        $this->info('  Sent with no transport error.');
        $this->line('  That means the provider ACCEPTED it, not that it reached the inbox —');
        $this->line('  check the mailbox, and the spam folder, before trusting it.');
        $this->newLine();

        return self::SUCCESS;
    }
}
