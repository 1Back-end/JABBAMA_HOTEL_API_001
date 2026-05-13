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
        Schema::table('order_restaurannts_drinks', function (Blueprint $table) {
            $table->boolean('is_defective')->default(false)->after('status');
            $table->text('reason_of_defective')->nullable()->after('is_defective');
            $table->foreignId('defective_by')->nullable()->constrained('users')->nullOnDelete()->after('reason_of_defective');
            $table->timestamp('defective_at')->nullable()->after('defective_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_restaurannts_drinks', function (Blueprint $table) {
            $table->dropForeign(['defective_by']);
            $table->dropColumn([
                'is_defective',
                'reason_of_defective',
                'defective_by',
                'defective_at',
            ]);
        });
    }
};
