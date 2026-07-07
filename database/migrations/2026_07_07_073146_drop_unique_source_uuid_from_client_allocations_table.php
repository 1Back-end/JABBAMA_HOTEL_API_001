<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('client_allocations', function (Blueprint $table) {

            $indexExists = collect(DB::select("
                SHOW INDEX FROM client_allocations
                WHERE Key_name = 'client_allocations_source_uuid_unique'
            "))->isNotEmpty();


            if ($indexExists) {
                $table->dropUnique('client_allocations_source_uuid_unique');
            }

        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_allocations', function (Blueprint $table) {

            $indexExists = collect(DB::select("
                SHOW INDEX FROM client_allocations
                WHERE Key_name = 'client_allocations_source_uuid_unique'
            "))->isNotEmpty();


            if (!$indexExists) {
                $table->unique(
                    'source_uuid',
                    'client_allocations_source_uuid_unique'
                );
            }

        });
    }
};
