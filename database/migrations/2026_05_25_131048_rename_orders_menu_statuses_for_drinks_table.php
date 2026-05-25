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
        Schema::rename(
            'orders_menu_statuses_for_drinks',
            'orders_menus_status_drinks'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename(
            'orders_menus_status_drinks',
            'orders_menu_statuses_for_drinks'
        );
    }
};
