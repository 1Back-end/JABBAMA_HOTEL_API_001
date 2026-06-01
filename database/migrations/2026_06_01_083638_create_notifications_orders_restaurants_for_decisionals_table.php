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
        Schema::create('notifications_for_decisionals', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            $table->uuid('order_menu_restaurant_uuid');
            $table->foreign('order_menu_restaurant_uuid')
                ->references('uuid')
                ->on('orders_menu_restaurants')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('status', 50);
            $table->text('message');

            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('order_menu_restaurant_uuid');
            $table->index(['user_id', 'is_read']);
            $table->index('status');
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications_for_decisionals');
    }
};
