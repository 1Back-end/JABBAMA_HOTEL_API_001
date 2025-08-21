<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition()
    {
        return [
            'login' => $this->faker->userName,
            'email' => $this->faker->safeEmail,
            'password' => Hash::make('password'),
            'nom_utilisateur' => $this->faker->name,
            'prenom' => $this->faker->name,
            'password_expiated_at' => now()->addDays(15),
        ];
    }
}
