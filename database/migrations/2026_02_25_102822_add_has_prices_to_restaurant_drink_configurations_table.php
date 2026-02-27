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
            $table->boolean('has_prices')->default(false)->after('is_active')
                ->comment('Indique si la boisson a déjà des prix configurés');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurant_drink_configurations', function (Blueprint $table) {
            $table->dropColumn('has_prices');
        });
    }
};
