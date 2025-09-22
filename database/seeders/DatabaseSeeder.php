<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
//        User::factory()->create([
//            'nom_utilisateur' => 'SYSTEM',
//            'login' => 'SYSTEM',
//            'email' => 'system@system.sytem',
//            'password' => \Hash::make('SYSTEM@2025'),
//            'password_expiated_at' => now()->addDay(),
//        ]);

        $this->call([
            InitBDForAllDataSeeder::class,
//            UsersTableSeeder::class,
        ]);
    }
}
