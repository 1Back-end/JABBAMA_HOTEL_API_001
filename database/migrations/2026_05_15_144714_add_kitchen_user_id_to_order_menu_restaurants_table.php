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
        Schema::table('orders_menu_restaurants', function (Blueprint $table) {
            $table->foreignId('kitchen_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('bar_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_menu_restaurants', function (Blueprint $table) {
            $table->dropForeign(['kitchen_user_id']);
            $table->dropForeign(['bar_user_id']);

            $table->dropColumn('kitchen_user_id');
            $table->dropColumn('bar_user_id');
        });
    }
};
