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
        Schema::table('order_restaurannts_drinks', function (Blueprint $table) {
            $table->timestamp('cancel_for_new_update_at')->nullable();
            $table->text('reason_of_cancel_for_new_update')->nullable();
            $table->boolean('is_reason_of_cancel_for_new_update')->default(false);
            $table->foreignId('cancel_for_new_update_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_restaurannts_drinks', function (Blueprint $table) {
            $table->dropForeign(['cancel_for_new_update_by']);
            $table->dropColumn([
                'cancel_for_new_update_at',
                'reason_of_cancel_for_new_update',
                'is_reason_of_cancel_for_new_update',
                'cancel_for_new_update_by'
            ]);
        });
    }
};
