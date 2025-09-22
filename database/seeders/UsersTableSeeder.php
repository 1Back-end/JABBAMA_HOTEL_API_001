<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Profile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;


class UsersTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

            User::insert([
                [
                    'nom_utilisateur' => 'admin',
                    'password' => Hash::make('password'),
                    'password_expiated_at' => now()->addMonths(3),
                    'email' => 'admin@example.com',
                    'status' => 'actif',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'nom_utilisateur' => 'utilisateur1',
                    'password' => Hash::make('password'),
                    'password_expiated_at' => now()->addMonths(3),
                    'email' => 'user1@example.com',
                    'status' => 'actif',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'nom_utilisateur' => 'utilisateur2',
                    'password' => Hash::make('password'),
                    'password_expiated_at' => now()->addMonths(3),
                    'email' => 'user2@example.com',
                    'status' => 'inactif',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'nom_utilisateur' => 'utilisateur3',
                    'password' => Hash::make('password'),
                    'password_expiated_at' => now()->addMonths(3),
                    'email' => 'user3@example.com',
                    'status' => 'actif',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'nom_utilisateur' => 'utilisateur4',
                    'password' => Hash::make('password'),
                    'password_expiated_at' => now()->addMonths(3),
                    'email' => 'user4@example.com',
                    'status' => 'suspendu',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

    }
}
