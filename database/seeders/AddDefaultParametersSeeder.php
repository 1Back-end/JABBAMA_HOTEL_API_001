<?php

namespace Database\Seeders;

use App\Models\SettingRestaurant;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AddDefaultParametersSeeder extends Seeder
{
    public function run(): void
    {
        // 🔹 Paramètres par défaut
        $defaultSettings = [
            [
                'key' => 'logout_period',
                'description' => "Durée (en minutes) avant déconnexion automatique d'un utilisateur sur l'interface de facturation",
                'value' => '15',
            ],
        ];

        $userId = User::first()?->id; // Utilisateur par défaut

        foreach ($defaultSettings as $setting) {
            SettingRestaurant::updateOrCreate(
                ['key' => $setting['key']], // clé unique
                [
                    'description' => $setting['description'],
                    'value' => $setting['value'],
                    'is_active' => true,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                    // code sera généré automatiquement via le boot() du modèle
                ]
            );
        }

        $this->command->info("✅ Paramètres par défaut créés avec code et is_active !");
    }
}
