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
        // Vérifie si la table existe déjà
        if (Schema::hasTable('menu_virtuals_temp')) {
            Schema::dropIfExists('menu_virtuals_temp');
        }

        // Recréation de la table
        Schema::create('menu_virtuals_temp', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            $table->string('code')->nullable();

            $table->integer('quantity')->default(0);

            $table->uuid('menus_restaurant_uuid')->nullable();
            $table->uuid('reservation_uuid')->nullable();

            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();

            $table->uuid('product_uuid')->nullable();

            $table->integer('quantity_used')->default(0);

            $table->string('status')->nullable();

            $table->uuid('order_menu_restaurant_uuid')->nullable();

            $table->timestamp('last_activity_at')->nullable();

            $table->string('type')->nullable();

            $table->boolean('is_not_used_stock')->default(false);

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('menu_virtuals_temp');
    }
};
