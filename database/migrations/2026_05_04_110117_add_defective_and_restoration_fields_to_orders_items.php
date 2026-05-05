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
            $table->foreignId('defective_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('defective_at')->nullable();

            $table->foreignId('restorated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restorated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {
            $table->dropForeign(['defective_by']);
            $table->dropForeign(['restorated_by']);
            $table->dropColumn(['defective_by', 'defective_at', 'restorated_by', 'restorated_at']);
        });
    }
};
