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
            $table->foreignId('partially_validated_by')
                ->nullable()
                ->after('validated_by')
                ->constrained('users')
                ->nullOnDelete();

            $table->text('partial_validation_reason')
                ->nullable()
                ->after('partially_validated_by')
                ->comment('Motif de la validation partielle');


            $table->foreignId('rejected_by')
                ->nullable()
                ->after('partial_validation_reason')
                ->constrained('users')
                ->nullOnDelete();

            $table->text('rejection_reason')
                ->nullable()
                ->after('rejected_by')
                ->comment('Motif du rejet');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->dropForeign(['partially_validated_by']);
            $table->dropColumn(['partially_validated_by', 'partial_validation_reason']);
            $table->dropForeign(['rejected_by']);
            $table->dropColumn(['rejected_by', 'rejection_reason']);
        });
    }
};
