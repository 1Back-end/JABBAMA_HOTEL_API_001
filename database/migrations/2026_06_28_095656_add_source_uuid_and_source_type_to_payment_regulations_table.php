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
        Schema::table('payment_regulations', function (Blueprint $table) {
            $table->uuid('source_uuid')
                ->nullable()
                ->after('payment_uuid');

            $table->string('source_type')
                ->default('income')
                ->after('source_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_regulations', function (Blueprint $table) {
            $table->dropColumn('source_type');
            $table->dropColumn('source_uuid');
        });
    }
};
