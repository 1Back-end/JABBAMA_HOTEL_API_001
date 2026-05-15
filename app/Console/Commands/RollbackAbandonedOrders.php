<?php

namespace App\Console\Commands;

use App\Models\VirtualOrderMenuRestaurant;
use Illuminate\Console\Command;

class RollbackAbandonedOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:rollback-abandoned-orders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $limit = now()->subMinutes(30);

        $orders = VirtualOrderMenuRestaurant::where('status', 'pending')
            ->where('updated_at', '<=', $limit)
            ->get();

        foreach ($orders as $order) {

            $orderUuid = $order->orders_menu_restaurant_uuid;

            // 👉 ici tu peux appeler ta logique

            app()->call('App\Http\Controllers\TonController@cancelRervationsAfterValidation', [
                'request' => new \Illuminate\Http\Request([
                    'order_menu_restaurant_uuid' => $orderUuid
                ])
            ]);
        }

        $this->info('Rollback exécuté');
    }
}
