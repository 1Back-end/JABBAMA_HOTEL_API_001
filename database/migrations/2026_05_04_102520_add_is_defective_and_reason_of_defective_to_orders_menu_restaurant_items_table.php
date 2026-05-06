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
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {
            $table->boolean('is_defective')->default(false)->after('status');
            $table->text('reason_of_defective')->nullable()->after('is_defective');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {
            $table->dropColumn(['is_defective', 'reason_of_defective']);
        });
    }
};
