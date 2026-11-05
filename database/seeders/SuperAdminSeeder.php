<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultPassword = '1234567';

        User::updateOrCreate(
            ['login' => 'admin'],
            [
                'nom_utilisateur'      => 'SUPER USER',
                'email'                => 'admin@admin.admin',
                'password'             => Hash::make($defaultPassword),
                'password_expiated_at' => now()->addDay(),
                'is_super_admin'       => true,
            ]
        );
    }
}
