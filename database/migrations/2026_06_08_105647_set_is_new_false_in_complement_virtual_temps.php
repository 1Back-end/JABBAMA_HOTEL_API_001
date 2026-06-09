<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('complement_virtual_temps')
            ->whereNull('is_new')
            ->update([
                'is_new' => false
            ]);
    }

    public function down(): void
    {
        // rollback optionnel (on remet à null si besoin)
        DB::table('complement_virtual_temps')
            ->where('is_new', false)
            ->update([
                'is_new' => null
            ]);
    }
};
