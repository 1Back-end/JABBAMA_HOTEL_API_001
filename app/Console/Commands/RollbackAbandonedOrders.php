<?php

namespace App\Console\Commands;

use App\Models\DrinksVirtualTemp;
use App\Models\MenuVirtualTemp;
use App\Models\OrderMenuRestaurant;
use App\Models\OrderMenuRestaurantItem;
use App\Models\OrderRestaurantDrink;
use App\Models\SettingRestaurant;
use App\Models\VirtualOrderMenuRestaurant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
        $setting = SettingRestaurant::where('key', 'logout_period')
            ->where('is_active', true)
            ->first();

        $minutes = $setting ? (int) $setting->value : 30;
        $limitDate = now()->subMinutes($minutes);

        $orders = OrderMenuRestaurant::where('is_in_editing', true)
            ->where('editing_started_at', '<', $limitDate)
            ->whereNull('rollback_at')
            ->get();

        foreach ($orders as $order) {

            $orderUuid = $order->uuid;

            $this->info("Rollback order: {$orderUuid}");

            DB::transaction(function () use ($order, $orderUuid) {

                // =======================
                // MENUS CLEAN
                // =======================
                MenuVirtualTemp::where('order_menu_restaurant_uuid', $orderUuid)
                    ->where(function ($query) {
                        $query->whereIn('type', ['initial', 'editing','not_used'])
                            ->orWhereNull('reservation_uuid');
                    })
                    ->delete();

                $virtualItems = VirtualOrderMenuRestaurant::where('orders_menu_restaurant_uuid', $orderUuid)
                    ->where('status', 'pending')
                    ->where('item_type', 'menu')
                    ->get();

                $itemMenus = OrderMenuRestaurantItem::where('order_menu_restaurant_uuid', $orderUuid)
                    ->get()
                    ->keyBy('uuid'); // 🔥 optimisation

                foreach ($virtualItems as $item) {
                    $menuItem = $itemMenus->get($item->item_uuid);
                    if (!$menuItem) continue;
                    MenuVirtualTemp::create([
                        'order_menu_restaurant_uuid' => $orderUuid,
                        'menus_restaurant_uuid' => $menuItem->menus_restaurant_uuid,
                        'product_uuid' => $item->product_uuid,
                        'type' => 'initial',
                        'quantity' => $item->quantity,
                        'quantity_used' => $item->quantity_exactly,
                        'status' => 'pending',
                        'created_by' => $order->editing_by,
                        'updated_by' => $order->editing_by,
                    ]);
                }


                DrinksVirtualTemp::where('order_menu_restaurant_uuid', $orderUuid)
                    ->where(function ($query) {
                        $query->whereIn('type', ['initial', 'editing'])
                            ->orWhereNull('reservation_uuid');
                    })
                    ->delete();

                $virtualItemsDrinks = VirtualOrderMenuRestaurant::where('orders_menu_restaurant_uuid', $orderUuid)
                    ->where('status', 'pending')
                    ->where('item_type', 'drink')
                    ->get();

                $itemDrinks = OrderRestaurantDrink::where('order_menu_restaurant_uuid', $orderUuid)
                    ->get()
                    ->keyBy('uuid');

                foreach ($virtualItemsDrinks as $item) {
                    $realDrink = $itemDrinks->get($item->item_uuid);
                    if (!$realDrink) continue;
                    DrinksVirtualTemp::create([
                        'order_menu_restaurant_uuid' => $orderUuid,
                        'product_uuid' => $item->product_uuid,
                        'drink_restaurant_uuid' => $realDrink->drink_restaurant_uuid,
                        'type' => 'initial',
                        'quantity' => $item->quantity,
                        'quantity_used' => $item->quantity_exactly,
                        'status' => 'pending',
                        'created_by' => $order->editing_by,
                        'updated_by' => $order->editing_by,
                    ]);
                }

                $order->update([
                    'is_in_editing' => false,
                    'editing_by' => null,
                    'editing_started_at' => null,
                    'rollback_at' => now()
                ]);
            });
        }

        $this->info('Rollback exécuté avec succès');
    }
}
