<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_tables_metadata', function (Blueprint $table) {
            $table->boolean('is_sensitive_data')->default(false)->after('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('database_tables_metadata', function (Blueprint $table) {
            $table->dropColumn('is_sensitive_data');
        });
    }
};
