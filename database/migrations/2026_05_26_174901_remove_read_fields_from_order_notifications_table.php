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
        Schema::table('order_notifications', function (Blueprint $table) {
            $table->dropColumn(['is_read', 'read_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_notifications', function (Blueprint $table) {
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
        });
    }
};
