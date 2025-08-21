<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;

class InitBDForAllDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $userSYSTEM = User::factory()->create([
            'nom_utilisateur' => 'SYSTEM',
            'login' => 'SYSTEM',
            'email' => 'system@system.sytem',
            'password' => \Hash::make('SYSTEM@2025'),
            'password_expiated_at' => now()->addDay(),
        ]);
        Artisan::call('permissions:extract');

        $role = \App\Models\Role::create([
            'name' => 'Super Admin',
            'description' => "Super utilisateur",
            'created_by' => $userSYSTEM->id,
            'updated_by' => $userSYSTEM->id
        ]);
        $role->permissions()->syncWithPivotValues(Permission::pluck('id')->toArray(), [
            'created_by' => $userSYSTEM->id,
            'updated_by' => $userSYSTEM->id
        ]);
        // Create SUPER ADMIN USER
        $superUser = User::factory()->create([
            'nom_utilisateur' => 'SUPER USER',
            'login' => 'admin',
            'email' => 'admin@admin.admin',
            'password' => \Hash::make('SUPERADMIN2145@2025'),
            'password_expiated_at' => now()->addDay(),
        ]);

        $role->users()->attach($superUser->id, [
            'created_by' => $userSYSTEM->id,
            'updated_by' => $userSYSTEM->id
        ]);
    }
        //
}
