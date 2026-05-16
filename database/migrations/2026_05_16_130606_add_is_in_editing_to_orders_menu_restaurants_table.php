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
            $table->boolean('is_in_editing')
                ->default(false)
                ->after('status')
                ->index();

            $table->foreignId('editing_by')->nullable()->constrained('users')->nullOnDelete()->after('is_in_editing');

            $table->timestamp('editing_started_at')
                ->nullable()
                ->after('editing_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_menu_restaurants', function (Blueprint $table) {
            $table->dropForeign(['editing_by']);
            $table->dropColumn([
                'is_in_editing',
                'editing_by',
                'editing_started_at',
            ]);
        });
    }
};
