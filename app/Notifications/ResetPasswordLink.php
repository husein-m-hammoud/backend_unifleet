<?php

namespace App\Notifications;

use App\Notifications\Concerns\ResolvesEventRecipients;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Password-reset email whose link points at the React SPA reset page
 * (`{frontend_url}/reset-password?token=…&email=…`) rather than a backend route.
 */
class ResetPasswordLink extends Notification
{
    use ResolvesEventRecipients;

    public function __construct(public string $token) {}

    public function emailEvent(): string
    {
        return 'password_reset_link';
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.frontend_url'), '/')
            . '/reset-password?token=' . $this->token
            . '&email=' . urlencode($notifiable->getEmailForPasswordReset());

        $mail = (new MailMessage())
            ->subject('Reset your UNIFLEET password')
            ->greeting('Hello ' . ($notifiable->name ?? '') . ',')
            ->line('You (or an administrator) requested a password reset for your UNIFLEET account.')
            ->action('Set a new password', $url)
            ->line('This link expires in 60 minutes. If you did not request this, you can ignore this email.');

        return $this->applyCc($mail, $notifiable);
    }
}
