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

        // 1️⃣ SYSTEM user
        $userSYSTEM = User::updateOrCreate(
            ['login' => 'SYSTEM'],
            [
                'nom_utilisateur' => 'SYSTEM',
                'email' => 'system@system.system',
                'password' => \Hash::make($defaultPassword),
                'password_expiated_at' => now()->addDay(),
            ]
        );

        // 2️⃣ Extraire les permissions
        Artisan::call('permissions:extract');

        // 3️⃣ Rôles principaux avec guard 'web'
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

        // 4️⃣ SUPER ADMIN USER
        $superUser = User::updateOrCreate(
            ['login' => 'admin'],
            [
                'nom_utilisateur' => 'SUPER USER',
                'email' => 'admin@admin.admin',
                'password' => \Hash::make($defaultPassword),
                'password_expiated_at' => now()->addDay(),
            ]
        );

        // 5️⃣ Assigner rôle Super Admin avec pivot
        $superUser->roles()->syncWithoutDetaching([
            $roleSuperAdmin->id => [
                'created_by' => $userSYSTEM->id,
                'updated_by' => $userSYSTEM->id
            ]
        ]);

        // 6️⃣ Autres rôles et utilisateurs
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

            // Assigner rôle avec pivot created_by / updated_by
            $user->roles()->syncWithoutDetaching([
                $role->id => [
                    'created_by' => $userSYSTEM->id,
                    'updated_by' => $userSYSTEM->id
                ]
            ]);
        }

        // 7️⃣ Réinitialiser le mot de passe de tous les utilisateurs existants
        User::query()->update([
            'password' => \Hash::make($defaultPassword),
            'password_expiated_at' => now()->addDay(),
        ]);

        info('Seeder exécuté : mots de passe réinitialisés et utilisateurs/rôles mis à jour.');
    }
}
