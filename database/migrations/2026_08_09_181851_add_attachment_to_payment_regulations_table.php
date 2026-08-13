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
        if (!Schema::hasColumn('payment_regulations', 'attachment')) {
            Schema::table('payment_regulations', function (Blueprint $table) {
                $table->string('attachment')->nullable()->after('type');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('payment_regulations', 'attachment')) {
            Schema::table('payment_regulations', function (Blueprint $table) {
                $table->dropColumn('attachment');
            });
        }
    }
};
