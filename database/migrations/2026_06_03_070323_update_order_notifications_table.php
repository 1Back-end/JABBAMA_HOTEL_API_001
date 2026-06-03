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

            // Suppression des clés étrangères
            $table->dropForeign(['kitchen_user_id']);
            $table->dropForeign(['bar_user_id']);

            // Ajout du nouveau champ
            $table->foreignId('user_id')
                ->nullable()
                ->after('order_menu_restaurant_uuid')
                ->constrained('users')
                ->nullOnDelete();

            // Suppression des colonnes inutiles
            $table->dropColumn([
                'order_menu_restaurant_item_uuid',
                'kitchen_user_id',
                'bar_user_id',
                'is_read',
                'read_at',
                'is_operational',
                'is_decisional',
                'is_operational_read',
                'operational_read_at',
                'is_decisional_read',
                'decisional_read_at',
            ]);
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_notifications', function (Blueprint $table) {

            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');

            $table->uuid('order_menu_restaurant_item_uuid')->nullable();

            $table->unsignedBigInteger('kitchen_user_id')->nullable();
            $table->unsignedBigInteger('bar_user_id')->nullable();

            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();

            $table->boolean('is_operational')->default(false);
            $table->boolean('is_decisional')->default(false);

            $table->boolean('is_operational_read')->default(false);
            $table->timestamp('operational_read_at')->nullable();

            $table->boolean('is_decisional_read')->default(false);
            $table->timestamp('decisional_read_at')->nullable();

            // Recréation des clés étrangères
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
};
