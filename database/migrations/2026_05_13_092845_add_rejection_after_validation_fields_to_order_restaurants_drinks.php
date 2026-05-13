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
            $table->boolean('is_reason_of_rejected_after_validation')->default(false);
            $table->text('reason_of_rejected_after_validation')->nullable();
            $table->foreignId('rejected_after_validation_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('rejected_after_validation_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_restaurannts_drinks', function (Blueprint $table) {
            $table->dropForeign(['rejected_after_validation_by']);
            $table->dropColumn([
                'is_reason_of_rejected_after_validation',
                'reason_of_rejected_after_validation',
                'rejected_after_validation_by',
                'rejected_after_validation_at'
            ]);
        });
    }
};
