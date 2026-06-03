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
        Schema::table('notifications_tables', function (Blueprint $table) {
            Schema::dropIfExists('notification_reads');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications_tables', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }
};
