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
        Schema::table('supplies', function (Blueprint $table) {
            $table->text('reason_cancel')->nullable()->after('notes');

            // Utilisateur qui a annulé
            $table->foreignId('cancelled_by')->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->after('validated_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->dropColumn('reason_cancel');
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn('cancelled_by');
        });
    }
};
