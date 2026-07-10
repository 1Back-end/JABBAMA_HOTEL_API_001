<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $notification;

    public function __construct($notification)
    {
        if (method_exists($notification, 'relationLoaded') && method_exists($notification, 'creator')) {
            $this->notification = $notification->load(['creator', 'updater']);
        } else {
            $this->notification = $notification;
        }
    }

    public function broadcastOn()
    {
        return new PrivateChannel('restaurant.notifications');
    }

    public function broadcastAs()
    {
        return 'NotificationCreated';
    }
}
