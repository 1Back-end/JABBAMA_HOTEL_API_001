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
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('transfered_by')
                ->nullable()
                ->after('approved_by')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('transfered_at')
                ->nullable()
                ->after('transfered_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['transfered_by']);
            $table->dropColumn(['transfered_by', 'transfered_at']);
        });
    }
};
