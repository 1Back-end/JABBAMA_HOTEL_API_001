<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SendRdvNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
    }

    public function via($notifiable): array
    {
        return ['mail', 'database', 'broadcast'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->line('');
    }

    public function toDatabase($notifiable): array
    {
        return [];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage([]);
    }

    public function toArray($notifiable): array
    {
        return [];
    }
}
