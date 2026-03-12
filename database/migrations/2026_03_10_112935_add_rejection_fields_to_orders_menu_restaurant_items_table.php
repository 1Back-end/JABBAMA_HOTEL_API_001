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
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->boolean('is_rejected')->default(false)->after('rejected_at');
            $table->text('reason')->nullable()->after('is_rejected');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {
            $table->dropColumn(['rejected_by', 'rejected_at', 'is_rejected', 'reason']);
        });
    }
};
