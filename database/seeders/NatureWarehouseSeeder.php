<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\NatureEntrepot;

class NatureWarehouseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $userId = 2; // ID de l'utilisateur créateur / modificateur

        $natureEntrepots = [
            [
                'abbreviation' => 'PC',
                'name' => 'Point de consommation',
                'description' => null,
                'is_active' => 1,
                'created_by' => $userId,
                'updated_by' => $userId,
            ],
            [
                'abbreviation' => 'PS',
                'name' => 'Point de Stockage',
                'description' => null,
                'is_active' => 1,
                'created_by' => $userId,
                'updated_by' => $userId,
            ],
            [
                'abbreviation' => 'PT',
                'name' => 'Point de transformation',
                'description' => null,
                'is_active' => 1,
                'created_by' => $userId,
                'updated_by' => $userId,
            ],
            [
                'abbreviation' => 'PD',
                'name' => 'Point de distribution',
                'description' => null,
                'is_active' => 1,
                'created_by' => $userId,
                'updated_by' => $userId,
            ],
        ];

        foreach ($natureEntrepots as $entrepot) {
            NatureEntrepot::updateOrCreate(
                ['name' => $entrepot['name']],
                $entrepot
            );
        }
    }
}
