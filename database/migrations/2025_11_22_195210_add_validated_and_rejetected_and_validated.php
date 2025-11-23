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
        Schema::table('passations', function (Blueprint $table) {
            $table->foreignId('validated_by')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('rejected_by')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->onDelete('cascade');
            $table->string('reason_rejected')->nullable();
            $table->string('reason_validated')->nullable();
            $table->string('reason_cancelled')->nullable();
            $table->datetime('rejected_at')->nullable();
            $table->datetime('cancelled_at')->nullable();
            $table->datetime('validated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('passations', function (Blueprint $table) {
            $table->dropForeign('validated_by');
            $table->dropForeign('rejected_by');
            $table->dropForeign('cancelled_by');
            $table->dropColumn(['reason_validated','rejected_reason','reason_cancelled','validated_at','cancelled_at','rejected_at']);

        });
    }
};
