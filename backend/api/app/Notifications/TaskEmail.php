<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Copie par e-mail d'une notification interne. */
class TaskEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $subject, public string $body, public string $taskId) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject)
            ->greeting(__('notifications.mail_greeting', ['name' => $notifiable->name]))
            ->line($this->body)
            ->action(__('notifications.mail_action'), rtrim(config('cousp.web_url'), '/').'/taches/'.$this->taskId);
    }
}
