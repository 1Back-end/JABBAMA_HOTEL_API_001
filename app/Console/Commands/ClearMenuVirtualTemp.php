<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\MenuVirtualTemp;
use App\Models\SettingRestaurant;

class ClearMenuVirtualTemp extends Command
{
    protected $signature = 'menu:clear-virtual-temp';
    protected $description = 'Clean expired virtual menu temps';

    public function handle()
    {
        $this->clearExpiredVirtualTemps();
        return 0;
    }

    private function getLogoutPeriod()
    {
        return (int) SettingRestaurant::where('key', 'logout_period')->where('is_active', true)->value('value') ?? 30;
    }

    private function clearExpiredVirtualTemps()
    {
        $minutes = $this->getLogoutPeriod();
        $limit = now()->subMinutes($minutes);
        return MenuVirtualTemp::where('last_activity_at', '<', $limit)->whereNotNull('reservation_uuid')
            ->whereNull('order_menu_restaurant_uuid')->delete();
    }
}
