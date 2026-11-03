<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('restaurant_drink_configurations', function (Blueprint $table) {
            $table->decimal('production_cost', 12, 2)->default(0)->after('uuid');
            $table->decimal('additional_cost', 12, 2)->default(0)->after('production_cost');
            $table->decimal('production_cost_final', 12, 2)->default(0)->after('additional_cost');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurant_drink_configurations', function (Blueprint $table) {
            $table->dropColumn([
                'production_cost',
                'additional_cost',
                'production_cost_final',
            ]);
        });
    }
};
