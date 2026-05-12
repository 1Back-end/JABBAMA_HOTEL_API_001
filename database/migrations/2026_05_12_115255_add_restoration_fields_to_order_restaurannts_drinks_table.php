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
            $table->boolean('is_restored')->default(false)->after('is_defective');
            $table->text('reason_of_restoration')->nullable()->after('is_restored');
            $table->unsignedBigInteger('restorated_by')->nullable()->after('reason_of_restoration');
            $table->timestamp('restorated_at')->nullable()->after('restorated_by');
            $table->foreign('restorated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_restaurannts_drinks', function (Blueprint $table) {
            $table->dropForeign(['restorated_by']);

            $table->dropColumn([
                'is_restored',
                'reason_of_restoration',
                'restorated_by',
                'restorated_at'
            ]);
        });
    }
};
