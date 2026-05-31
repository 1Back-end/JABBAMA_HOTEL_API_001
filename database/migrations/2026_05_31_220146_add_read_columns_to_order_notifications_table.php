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
            $table->boolean('is_operational_read')
                ->default(false)
                ->after('is_decisional');

            $table->timestamp('operational_read_at')
                ->nullable()
                ->after('is_operational_read');

            $table->boolean('is_decisional_read')
                ->default(false)
                ->after('operational_read_at');

            $table->timestamp('decisional_read_at')
                ->nullable()
                ->after('is_decisional_read');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_notifications', function (Blueprint $table) {
            $table->dropColumn([
                'is_operational_read',
                'operational_read_at',
                'is_decisional_read',
                'decisional_read_at',
            ]);
        });
    }
};
