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
        Schema::table('restaurant_partners', function (Blueprint $table) {
            $table->boolean('is_whatsapp')->default(false)->after('second_phone_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurant_partners', function (Blueprint $table) {
            $table->dropColumn('is_whatsapp');
        });
    }
};
