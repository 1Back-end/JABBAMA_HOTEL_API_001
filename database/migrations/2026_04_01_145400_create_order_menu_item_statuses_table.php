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
        Schema::create('order_menu_item_statuses', function (Blueprint $table) {
            $table->uuid('uuid')->primary()->unique();
            $table->uuid('order_menu_restaurant_item_uuid');
            $table->foreign('order_menu_restaurant_item_uuid')->references('uuid')->on('orders_menu_restaurant_items')->cascadeOnDelete();
            $table->string('status')->default('transferred');
            $table->integer('quantity')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_menu_item_statuses');
    }
};
