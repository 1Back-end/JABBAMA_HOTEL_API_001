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
        Schema::create('order_item_status_events', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            $table->uuid('order_menu_restaurant_uuid');
            $table->uuid('order_menu_restaurant_item_uuid');

            $table->string('status');
            $table->integer('quantity')->default(0);

            $table->string('action_type')->nullable();
            $table->text('comment')->nullable();

            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_item_status_events');
    }
};
