<?php

namespace App\Notifications\Concerns;

use App\Models\EmailEvent;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Applies an email event's configured CC list to a MailMessage.
 *
 * The extra **To** addresses are applied separately, in
 * `User::routeNotificationForMail()` — MailChannel takes the To from the
 * notifiable's route, not from the MailMessage, so that is the only place it can
 * be extended. CC and BCC *are* read off the MailMessage (MailChannel lines
 * 218-227), so they belong here.
 */
trait ResolvesEventRecipients
{
    /** The EmailEvent key this notification belongs to. */
    abstract public function emailEvent(): string;

    protected function applyCc(MailMessage $mail, object $notifiable): MailMessage
    {
        $cc = EmailEvent::recipients(
            $this->emailEvent(),
            is_string($notifiable->email ?? null) ? $notifiable->email : null,
        )['cc'];

        return $cc === [] ? $mail : $mail->cc($cc);
    }
}
