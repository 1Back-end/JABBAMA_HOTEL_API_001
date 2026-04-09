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
        Schema::create('statistics_orders_status_menus_restaurants', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('order_menu_restaurant_item_uuid');
            $table->string('status')->default('transferred');
            $table->integer('quantity')->default(0);

            // FK vers l'item de commande
            $table->foreign('order_menu_restaurant_item_uuid', 'fk_stats_order_item')
                ->references('uuid')
                ->on('orders_menu_restaurant_items')
                ->cascadeOnDelete();

            // Created / Updated by
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()->name('fk_stats_created_by');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete()->name('fk_stats_updated_by');

            // Status timestamps et auteurs
            $table->timestamp('pending_at')->nullable();
            $table->foreignId('make_pending_by')->nullable()->constrained('users')->nullOnDelete()->name('fk_stats_pending_by');

            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('make_rejected_by')->nullable()->constrained('users')->nullOnDelete()->name('fk_stats_rejected_by');

            $table->timestamp('delivered')->nullable();
            $table->foreignId('make_delivered_by')->nullable()->constrained('users')->nullOnDelete()->name('fk_stats_delivered_by');

            $table->timestamp('ready_at')->nullable();
            $table->foreignId('make_ready_by')->nullable()->constrained('users')->nullOnDelete()->name('fk_stats_ready_by');

            $table->timestamp('not_delivered_at')->nullable();
            $table->foreignId('make_not_delivered_by')->nullable()->constrained('users')->nullOnDelete()->name('fk_stats_not_delivered_by');

            $table->timestamp('partial_delivered_at')->nullable();
            $table->foreignId('make_partial_delivered_by')->nullable()->constrained('users')->nullOnDelete()->name('fk_stats_partial_delivered_by');

            $table->timestamp('delivered_in_preparation_at')->nullable();
            $table->foreignId('make_delivered_in_preparation_by')->nullable()->constrained('users')->nullOnDelete()->name('fk_stats_delivered_in_prep_by');

            $table->timestamp('transferred_at')->nullable();
            $table->foreignId('make_transferred_by')->nullable()->constrained('users')->nullOnDelete()->name('fk_stats_transferred_by');

            $table->timestamp('cancel_for_new_update_at')->nullable();
            $table->foreignId('make_cancel_for_new_update_by')->nullable()->constrained('users')->nullOnDelete()->name('fk_stats_cancel_new_update_by');

            $table->timestamp('in_preparation_at')->nullable();
            $table->foreignId('make_in_preparation_by')->nullable()->constrained('users')->nullOnDelete()->name('fk_stats_in_preparation_by');

            $table->timestamp('partial_completed_at')->nullable();
            $table->foreignId('make_partial_completed_by')->nullable()->constrained('users')->nullOnDelete()->name('fk_stats_partial_completed_by');

            $table->timestamp('new_rejected_at')->nullable();
            $table->foreignId('make_new_rejected_by')->nullable()->constrained('users')->nullOnDelete()->name('fk_stats_new_rejected_by');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('statistics_orders_status_menus_restaurants');
    }
};
