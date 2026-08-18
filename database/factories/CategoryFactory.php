<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition()
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'name'        => $name,
            'slug'        => Str::slug($name) . '-' . Str::random(6),
            'description' => $this->faker->sentence(),
            'is_active'   => true,
            'sort_order'  => 0,
        ];
    }
}
