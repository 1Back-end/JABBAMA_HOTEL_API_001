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
        Schema::table('restaurant_drink_configurations', function (Blueprint $table) {
            $table->decimal('default_price', 10, 2)->after('code')->nullable()->comment('Prix par défaut appliqué à tous les clients');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurant_drink_configurations', function (Blueprint $table) {
            $table->dropColumn('default_price');
        });
    }
};
