<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {
            $table->decimal('snapshot_additional_cost', 10, 2)->default(0)->after('unit_price');
            $table->decimal('snapshot_composition_cost', 10, 2)->default(0)->after('snapshot_additional_cost');
            $table->decimal('snapshot_complements_cost', 10, 2)->default(0)->after('snapshot_composition_cost');
        });
    }

    public function down(): void
    {
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {
            $table->dropColumn([
                'snapshot_additional_cost',
                'snapshot_composition_cost',
                'snapshot_complements_cost',
            ]);
        });
    }
};
