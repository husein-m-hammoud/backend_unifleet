<?php

namespace App\Notifications;

use App\Notifications\Concerns\ResolvesEventRecipients;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sends a user their login details — used when an admin creates an account (or
 * regenerates a password) and chooses to email the credentials. `$isNew` tweaks
 * the wording between "your account has been created" and "your password was
 * reset".
 */
class AccountCredentials extends Notification
{
    use ResolvesEventRecipients;

    public function __construct(
        public string $username,
        public string $password,
        public bool $isNew = true,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** A new account and an admin-reset password are configured separately. */
    public function emailEvent(): string
    {
        return $this->isNew ? 'account_created' : 'password_changed';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $loginUrl = rtrim(config('app.frontend_url'), '/') . '/';

        $mail = (new MailMessage())
            ->subject($this->isNew ? 'Your UNIFLEET account is ready' : 'Your UNIFLEET password was reset')
            ->greeting('Hello ' . ($notifiable->name ?? '') . ',');

        if ($this->isNew) {
            $mail->line('An account has been created for you on UNIFLEET. Here are your sign-in details:');
        } else {
            $mail->line('Your UNIFLEET password has been reset. Here are your new sign-in details:');
        }

        $mail
            ->line('**Username:** ' . $this->username)
            ->line('**Password:** ' . $this->password)
            ->action('Sign in', $loginUrl)
            ->line('For your security, please change this password after signing in.');

        return $this->applyCc($mail, $notifiable);
    }
}
