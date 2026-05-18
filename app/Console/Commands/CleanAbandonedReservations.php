<?php

namespace App\Console\Commands;

use App\Models\DrinksVirtualTemp;
use App\Models\MenuVirtualTemp;
use App\Models\SettingRestaurant;
use Illuminate\Console\Command;

class CleanAbandonedReservations extends Command
{
    protected $signature = 'restaurant:clean-abandoned';

    protected $description = 'Supprime les réservations abandonnées';

    public function handle()
    {
        try {

            $setting = SettingRestaurant::where('key', 'logout_period')
                ->where('is_active', true)
                ->first();
            $minutes = $setting ? (int)$setting->value : 30;
            $limitDate = now()->subMinutes($minutes);

            $menuDeleted = MenuVirtualTemp::where('type', 'initial')
                ->whereNull('order_menu_restaurant_uuid')
                ->where('last_activity_at', '<=', $limitDate)
                ->delete();

            $drinkDeleted = DrinksVirtualTemp::where('type', 'initial')
                ->whereNull('order_menu_restaurant_uuid')
                ->where('last_activity_at', '<=', $limitDate)
                ->delete();

            $this->info("Menus supprimés: $menuDeleted");
            $this->info("Boissons supprimées: $drinkDeleted");

        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }
    }
}
