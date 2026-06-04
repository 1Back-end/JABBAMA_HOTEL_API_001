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
        Schema::table('user_order_notifications', function (Blueprint $table) {
            Schema::dropIfExists('user_order_notifications');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_order_notifications', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }
};
