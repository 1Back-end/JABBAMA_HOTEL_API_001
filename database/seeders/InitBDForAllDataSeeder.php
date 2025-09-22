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
        // SYSTEM user
        $userSYSTEM = User::firstOrCreate(
            ['login' => 'SYSTEM'],
            [
                'nom_utilisateur' => 'SYSTEM',
                'email' => 'system@system.sytem',
                'password' => \Hash::make('SYSTEM@2025'),
                'password_expiated_at' => now()->addDay(),
            ]
        );

        Artisan::call('permissions:extract');

        // Rôles principaux
        $roleSuperAdmin = Role::firstOrCreate(
            ['name' => 'Super Admin'],
            ['description' => 'Super utilisateur', 'created_by' => $userSYSTEM->id, 'updated_by' => $userSYSTEM->id]
        );

        $roleAdmin = Role::firstOrCreate(
            ['name' => 'Admin'],
            ['description' => 'Administrateur', 'created_by' => $userSYSTEM->id, 'updated_by' => $userSYSTEM->id]
        );

        // SUPER ADMIN USER
        $superUser = User::firstOrCreate(
            ['login' => 'admin'],
            [
                'nom_utilisateur' => 'SUPER USER',
                'email' => 'admin@admin.admin',
                'password' => \Hash::make('SUPERADMIN2145@2025'),
                'password_expiated_at' => now()->addDay(),
            ]
        );

        $roleSuperAdmin->users()->syncWithoutDetaching([
            $superUser->id => ['created_by' => $userSYSTEM->id, 'updated_by' => $userSYSTEM->id]
        ]);

        // Création d'autres rôles
        $otherRoles = ['Manager', 'Reception', 'Comptable', 'RH', 'Technicien'];
        $users = [];

        foreach ($otherRoles as $roleName) {
            $role = Role::firstOrCreate(
                ['name' => $roleName],
                ['description' => $roleName . ' role', 'created_by' => $userSYSTEM->id, 'updated_by' => $userSYSTEM->id]
            );

            // Création d'un utilisateur pour chaque rôle
            $user = User::firstOrCreate(
                ['login' => strtolower($roleName)],
                [
                    'nom_utilisateur' => $roleName . ' User',
                    'email' => strtolower($roleName) . '@example.com',
                    'password' => \Hash::make('Password@123'),
                    'password_expiated_at' => now()->addDay(),
                ]
            );

            $role->users()->syncWithoutDetaching([
                $user->id => ['created_by' => $userSYSTEM->id, 'updated_by' => $userSYSTEM->id]
            ]);

            $users[] = $user;
        }

        // Optionnel : afficher les utilisateurs créés
        info('Utilisateurs créés : ' . implode(', ', array_map(fn($u) => $u->login, array_merge([$superUser], $users))));
    }


    //
}
