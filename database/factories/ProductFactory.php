<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\User;
use App\Models\Category;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition()
    {
        $user = User::inRandomOrder()->first();
        $category = Category::inRandomOrder()->first();
        $unit = Unit::inRandomOrder()->first();

        return [
            'uuid' => (string) Str::uuid(),
            'name' => $this->faker->words(2, true),
            'description' => $this->faker->sentence(10),
            'category_uuid' => $category ? $category->uuid : null,
            'unit_uuid' => $unit ? $unit->uuid : null,
            'purchase_price' => $this->faker->randomFloat(2, 1000, 50000),
            'sale_price' => $this->faker->randomFloat(2, 5000, 80000),
            'stock_quantity' => $this->faker->numberBetween(0, 200),
            'minimum_stock' => $this->faker->numberBetween(1, 20),
            'is_active' => $this->faker->boolean(90),
            'created_by' => $user ? $user->id : 1,
            'updated_by' => $user ? $user->id : 1,
            'image_file' => null,
        ];
    }
}
