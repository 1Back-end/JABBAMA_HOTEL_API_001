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
        Schema::table('settings_restaurants', function (Blueprint $table) {
            $table->string('code')->unique()->nullable()->after('key');
            $table->boolean('is_active')->default(true)->after('value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings_restaurants', function (Blueprint $table) {
            $table->dropColumn(['code', 'is_active']);
        });
    }
};
