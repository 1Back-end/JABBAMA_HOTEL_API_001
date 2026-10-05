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
        $defaultSettings = [
            [
                'key' => 'logout_period',
                'description' => "Durée (en minutes) avant déconnexion automatique d'un utilisateur sur l'interface de facturation",
                'value' => '15',
            ],
            [
                'key' => 'show_decisional_notifications',
                'description' => "Activer ou désactiver l'affichage des notifications décisionnelles (true/false)",
                'value' => 'false',
            ],
        ];

        $userId = User::first()?->id;

        foreach ($defaultSettings as $setting) {
            SettingRestaurant::updateOrCreate(
                ['key' => $setting['key']],
                [
                    'description' => $setting['description'],
                    'value' => $setting['value'],
                    'is_active' => true,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]
            );
        }

        $this->command->info("✅ Paramètres par défaut créés avec code et is_active !");
    }
}
