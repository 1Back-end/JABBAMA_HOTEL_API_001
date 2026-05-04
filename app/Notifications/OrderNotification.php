<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderNotification extends Notification
{
    public function __construct(
        public string $message,
        public string $status,
        public string $orderUuid
    ) {}

    public function via($notifiable)
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable)
    {
        return [
            'message' => $this->message,
            'status' => $this->status,
            'order_uuid' => $this->orderUuid,
        ];
    }

    public function toBroadcast($notifiable)
    {
        return new BroadcastMessage([
            'message' => $this->message,
            'status' => $this->status,
            'order_uuid' => $this->orderUuid,
        ]);
    }
    public function broadcastType(): string
    {
        return 'order.notification';
    }
}
