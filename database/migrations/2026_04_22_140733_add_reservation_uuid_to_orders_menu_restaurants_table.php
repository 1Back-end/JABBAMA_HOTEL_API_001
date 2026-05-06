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
            $table->uuid('reservation_uuid')->nullable()->after('uuid');
            $table->index('reservation_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_menu_restaurants', function (Blueprint $table) {
            $table->dropIndex(['reservation_uuid']);
            $table->dropColumn('reservation_uuid');
        });
    }
};
