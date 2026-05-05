<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class OrderNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $message,
        public string $status,
        public string $orderUuid,
        public ?string $orderItemUuid = null
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    private function payload(): array
    {
        return [
            'message' => $this->message,
            'order_menu_restaurant_uuid' => $this->orderUuid,
            'order_menu_restaurant_item_uuid' => $this->orderItemUuid,
            'status' => $this->status,
        ];
    }

    public function toArray($notifiable): array
    {
        return $this->payload();
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'data' => $this->payload()
        ]);
    }

    public function broadcastType(): string
    {
        return 'order.notification';
    }
}
