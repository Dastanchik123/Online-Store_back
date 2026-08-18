<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition()
    {
        $name = $this->faker->unique()->words(3, true);

        return [
            'name'           => $name,
            'slug'           => Str::slug($name) . '-' . Str::random(6),
            'description'    => $this->faker->paragraph(),
            'sku'            => 'SKU-' . Str::upper(Str::random(8)),
            'purchase_price' => 50,
            'price'          => 100,
            'sale_price'     => null,
            'stock_quantity' => 10,
            'in_stock'       => true,
            'is_active'      => true,
            'category_id'    => Category::factory(),
            'unit'           => 'шт',
        ];
    }

    public function outOfStock()
    {
        return $this->state(fn (array $attributes) => [
            'stock_quantity' => 0,
            'in_stock'       => false,
        ]);
    }
}
