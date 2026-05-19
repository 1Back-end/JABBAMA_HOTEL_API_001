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
        Schema::table('order_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('kitchen_user_id')->nullable()->after('uuid');
            $table->unsignedBigInteger('bar_user_id')->nullable()->after('kitchen_user_id');

            // si tu veux les relations FK (recommandé)
            $table->foreign('kitchen_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('bar_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_notifications', function (Blueprint $table) {
            $table->dropForeign(['kitchen_user_id']);
            $table->dropForeign(['bar_user_id']);
            $table->dropColumn(['kitchen_user_id', 'bar_user_id']);
        });
    }
};
