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
        Schema::table('drinks_virtuals_temp', function (Blueprint $table) {
            $table->uuid('drink_restaurant_uuid')
                ->nullable()
                ->after('product_uuid');

            $table->foreign('drink_restaurant_uuid')
                ->references('uuid')
                ->on('restaurant_drink_configurations')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drinks_virtuals_temp', function (Blueprint $table) {
            $table->dropForeign(['drink_restaurant_uuid']);

            $table->dropColumn('drink_restaurant_uuid');
        });
    }
};
