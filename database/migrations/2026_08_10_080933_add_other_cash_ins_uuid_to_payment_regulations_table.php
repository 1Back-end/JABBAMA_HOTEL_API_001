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
            $table->uuid('other_cash_ins_uuid')->nullable()->after('uuid');

            $table->foreign('other_cash_ins_uuid')
                ->references('uuid')
                ->on('other_cash_ins')
                ->onDelete('cascade')
                ->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_regulations', function (Blueprint $table) {
            $table->dropForeign(['other_cash_ins_uuid']);
            $table->dropColumn('other_cash_ins_uuid');
        });
    }
};
