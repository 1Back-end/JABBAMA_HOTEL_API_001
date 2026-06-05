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
        Schema::create('complement_virtual_temps', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code')->nullable();

            $table->integer('quantity')->default(0);
            $table->integer('quantity_used')->default(0);

            $table->uuid('product_uuid')->nullable();
            $table->uuid('reservation_uuid')->nullable();
            $table->uuid('order_menu_restaurant_uuid')->nullable();
            $table->uuid('complement_uuid')->nullable();

            $table->foreign('product_uuid')
                ->references('uuid')
                ->on('produits')
                ->nullOnDelete();

            $table->foreign('order_menu_restaurant_uuid')
                ->references('uuid')
                ->on('orders_menu_restaurants')
                ->nullOnDelete();

            $table->foreign('complement_uuid')
                ->references('uuid')
                ->on('configurations_complements')
                ->nullOnDelete();


            $table->string('status')->default('pending');

            $table->timestamps();
            $table->timestamp('last_activity_at')->nullable();
            $table->softDeletes();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('complement_virtual_temps');
    }
};
