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
        Schema::table('pdf_documents', function (Blueprint $table) {
            $table->uuid('order_uuid')->nullable()->after('sequence')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pdf_documents', function (Blueprint $table) {
            $table->dropColumn('order_uuid');
        });
    }
};
