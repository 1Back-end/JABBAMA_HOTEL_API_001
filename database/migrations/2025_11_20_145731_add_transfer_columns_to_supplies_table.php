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
            $table->timestamp('transferred_at')->nullable()->after('supply_date')->comment('Date de transfert');
            $table->foreignId('transferred_by')->nullable()->constrained('users')->nullOnDelete()->after('transferred_at');
            $table->foreignId('receiver_by')->nullable()->constrained('users')->nullOnDelete()->after('transferred_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->dropForeign(['transferred_by']);
            $table->dropForeign(['receiver_by']);
            $table->dropColumn(['transferred_at', 'transferred_by', 'receiver_by']);
        });
    }
};
