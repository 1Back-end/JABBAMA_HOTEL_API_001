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
            $table->boolean('is_finished_product')
                ->default(false)
                ->comment('Indicates if the drink is a finished product');

            $table->boolean('is_transformable_product')
                ->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurant_drink_configurations', function (Blueprint $table) {
            $table->dropColumn([
                'is_finished_product',
                'is_transformable_product'
            ]);
        });
    }
};
