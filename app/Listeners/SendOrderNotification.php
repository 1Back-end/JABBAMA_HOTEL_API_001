<?php

namespace App\Listeners;

use App\Enums\OrderMenuRestaurantItemStatus;
use App\Events\OrderStatusChanged;
use App\Notifications\OrderNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendOrderNotification
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(OrderStatusChanged $event)
    {
        $user = $event->user;

        if (!$user) {
            return;
        }

        $statusEnum = OrderMenuRestaurantItemStatus::from($event->status);
        $message = "📦 Commande (#{$event->order->uuid}) : {$statusEnum->label()}";

        $user->notify(new OrderNotification(
            $message,
            $event->status,
            $event->order->uuid
        ));
    }
}
