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
        Schema::create('order_menu_restaurant_defective_items', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            $table->uuid('order_menu_restaurant_item_uuid')->nullable();
            $table->uuid('order_menu_restaurant_uuid')->nullable();

            $table->string('status')->default('cancel_for_new_update');
            $table->integer('quantity')->default(0);
            $table->text('reason')->nullable();
            $table->string('type')->default('menu');

            // ✅ INDEX AVEC NOMS COURTS
            $table->index('order_menu_restaurant_item_uuid', 'idx_defective_item');
            $table->index('order_menu_restaurant_uuid', 'idx_defective_order');
            $table->index('status', 'idx_defective_status');
            $table->index('type', 'idx_defective_type');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreign('order_menu_restaurant_item_uuid', 'fk_defective_item')
                ->references('uuid')->on('orders_menu_restaurant_items')->cascadeOnDelete();

            $table->foreign('order_menu_restaurant_uuid', 'fk_defective_order')
                ->references('uuid')->on('orders_menu_restaurants')->cascadeOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_menu_restaurant_defective_items');
    }
};
