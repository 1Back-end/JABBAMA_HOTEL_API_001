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
        Schema::table('regulation_methods', function (Blueprint $table) {
            $table->integer('type_regulation')->nullable()->after('description');
            $table->boolean('phone_method')->nullable()->default(true)->after('type_regulation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('regulation_methods', function (Blueprint $table) {
            //
        });
    }
};
