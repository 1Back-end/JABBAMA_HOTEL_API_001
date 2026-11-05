<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;

class InitBDForAllDataSeeder extends Seeder
{
    public function run(): void
    {
        $defaultPassword = '1234567';
        $userSYSTEM = User::updateOrCreate(
            ['login' => 'SYSTEM'],
            [
                'nom_utilisateur' => 'SYSTEM',
                'email' => 'system@system.system',
                'password' => \Hash::make($defaultPassword),
                'password_expiated_at' => now()->addDay(),
            ]
        );

        Artisan::call('permissions:extract');

        $roleSuperAdmin = Role::firstOrCreate(
            ['name' => 'SUPER_ADMIN', 'guard_name' => 'web'],
            [
                'description' => 'Super utilisateur',
                'created_by' => $userSYSTEM->id,
                'updated_by' => $userSYSTEM->id
            ]
        );

        $roleAdmin = Role::firstOrCreate(
            ['name' => 'ADMIN', 'guard_name' => 'web'],
            [
                'description' => 'Administrateur',
                'created_by' => $userSYSTEM->id,
                'updated_by' => $userSYSTEM->id
            ]
        );

        $superUser = User::updateOrCreate(
            ['login' => 'admin'],
            [
                'nom_utilisateur' => 'SUPER USER',
                'email' => 'admin@admin.admin',
                'password' => \Hash::make($defaultPassword),
                'password_expiated_at' => now()->addDay(),
            ]
        );

        $superUser->roles()->syncWithoutDetaching([
            $roleSuperAdmin->id => [
                'created_by' => $userSYSTEM->id,
                'updated_by' => $userSYSTEM->id
            ]
        ]);

        $otherRoles = [
            'MANAGER', 'RECEPTION', 'COMPTABLE', 'RH', 'TECHNICIEN',
            'GESTIONNAIRE_STOCK', 'CUISINIER', 'ECONOME', 'AGENT_BAR', 'GOUVERNANTE'
        ];

        foreach ($otherRoles as $roleName) {
            $role = Role::firstOrCreate(
                ['name' => $roleName, 'guard_name' => 'web'],
                [
                    'description' => $roleName . ' ROLE',
                    'created_by' => $userSYSTEM->id,
                    'updated_by' => $userSYSTEM->id
                ]
            );

            $user = User::updateOrCreate(
                ['login' => strtolower($roleName)],
                [
                    'nom_utilisateur' => $roleName . ' USER',
                    'email' => strtolower($roleName) . '@example.com',
                    'password' => \Hash::make($defaultPassword),
                    'password_expiated_at' => now()->addDay(),
                ]
            );

            $user->roles()->syncWithoutDetaching([
                $role->id => [
                    'created_by' => $userSYSTEM->id,
                    'updated_by' => $userSYSTEM->id
                ]
            ]);
        }

        User::query()->update([
            'password' => \Hash::make($defaultPassword),
            'password_expiated_at' => now()->addDay(),
        ]);

        info('Seeder exécuté : mots de passe réinitialisés et utilisateurs/rôles mis à jour.');
    }
}
