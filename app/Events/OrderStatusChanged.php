<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderStatusChanged implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public $order;
    public $status;
    public $message;
    public $data;

    /**
     * Create a new event instance.
     */
    public function __construct($order, string $status, string $message, array $data = [])
    {
        $this->order = $order;
        $this->status = $status;
        $this->message = $message;
        $this->data = $data;
    }

    /**
     * Channel de diffusion (temps réel)
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . auth()->id()),
        ];
    }

    /**
     * Nom de l’event côté frontend
     */
    public function broadcastAs(): string
    {
        return 'order.status.changed';
    }

    /**
     * Données envoyées au frontend
     */
    public function broadcastWith(): array
    {
        return [
            'order_uuid' => $this->order->uuid,
            'status' => $this->status,
            'message' => $this->message,
            'data' => $this->data,
        ];
    }
}
