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
        Schema::table('order_item_status_events', function (Blueprint $table) {
            Schema::dropIfExists('order_item_status_events');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('order_item_status_events', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }
};
