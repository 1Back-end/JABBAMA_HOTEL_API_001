<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Permission;
use App\Models\PermissionCategory;
use App\Models\User;
use Spatie\Permission\Models\Role;

class PermissionsSeeder extends Seeder
{
    /**
     * Liste des permissions à créer si elles n'existent pas
     */
    protected $permissions = [
        'view_all_products' => [
            'description' => 'Accéder à tous les produits, indépendamment de son rôle..',
            'category' => 'Permissions supplémentaires',
        ],
        'view_all_warehouses' => [
            'description' => 'Accéder à tous les entrepôts, indépendamment de son rôle..',
            'category' => 'Permissions supplémentaires',
        ],
        'view_all_passations' => [
            'description' => "Accéder à toutes les passations de stocks, indépendamment de son rôle..",
            'category' => 'Permissions supplémentaires',
        ]
    ];

    public function run(): void
    {
        $userSYSTEM = User::where('login', 'SYSTEM')->first();
        $systemId = $userSYSTEM?->id ?? 1;

        foreach ($this->permissions as $name => $data) {
            // Crée ou met à jour la catégorie
            $category = PermissionCategory::updateOrCreate(
                ['libelle' => $data['category']],
                [
                    'description' => $data['category'],
                    'created_by' => $systemId,
                    'updated_by' => $systemId
                ]
            );

            // Crée ou met à jour la permission
            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'description' => $data['description'],
                    'category_id' => $category->id,
                    'system' => true,
                    'active' => true,
                    'created_by' => $systemId,
                    'updated_by' => $systemId
                ]
            );

            $this->command->info($permission->wasRecentlyCreated ? "✅ Créée : {$name}" : "🔁 Existe déjà : {$name}");

            // Optionnel : attach à SUPER_ADMIN
            $role = Role::where('name', 'SUPER_ADMIN')->first();
            if ($role && !$role->permissions->contains($permission->id)) {
                $role->permissions()->attach($permission->id, [
                    'created_by' => $systemId,
                    'updated_by' => $systemId
                ]);
                $this->command->info("✅ Permission attachée au rôle SUPER_ADMIN");
            }
        }
    }
}
