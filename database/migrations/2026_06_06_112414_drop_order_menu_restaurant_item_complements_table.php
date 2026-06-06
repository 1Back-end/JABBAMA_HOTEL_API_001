<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('order_menu_restaurant_item_complements');
    }

    public function down(): void
    {
        Schema::create('order_menu_restaurant_item_complements', function (Blueprint $table) {
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

            $table->foreign('order_menu_restaurant_item_uuid')
                ->references('uuid')
                ->on('order_menu_restaurant_items')
                ->cascadeOnDelete();

            $table->foreign('configuration_complement_uuid')
                ->references('uuid')
                ->on('configurations_complements')
                ->cascadeOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }
};
