<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('orders_restaurant_item_complements');
    }

    public function down(): void
    {
        Schema::create('orders_restaurant_item_complements', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->uuid('order_menu_restaurant_item_uuid');
            $table->uuid('configuration_complement_uuid');

            $table->timestamps();
            $table->softDeletes();
        });
    }
};
