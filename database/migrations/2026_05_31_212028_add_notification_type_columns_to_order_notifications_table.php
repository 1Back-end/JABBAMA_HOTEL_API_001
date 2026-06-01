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
            $table->boolean('is_operational')->default(false)->after('read_at');
            $table->boolean('is_decisional')->default(false)->after('is_operational');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_notifications', function (Blueprint $table) {
            $table->dropColumn([
                'is_operational',
                'is_decisional'
            ]);
        });
    }
};
