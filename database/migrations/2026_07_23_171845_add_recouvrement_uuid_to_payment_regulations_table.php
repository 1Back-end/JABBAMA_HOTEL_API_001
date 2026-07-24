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
            $table->uuid('recouvrement_uuid')
                ->nullable()
                ->after('uuid');

            $table->foreign('recouvrement_uuid')
                ->references('uuid')
                ->on('recouvrements')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_regulations', function (Blueprint $table) {
            $table->dropForeign(['recouvrement_uuid']);
            $table->dropColumn('recouvrement_uuid');
        });
    }
};
