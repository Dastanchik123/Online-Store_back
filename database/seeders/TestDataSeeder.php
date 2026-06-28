<?php
namespace Database\Seeders;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TestDataSeeder extends Seeder
{
    public function run()
    {
        $category = Category::firstOrCreate([
            'name' => 'Общая категория',
        ], [
            'slug' => 'obshaya-kategoriya',
            'is_active' => true,
        ]);

        for ($i = 1; $i <= 100; $i++) {
            Product::create([
                'name' => 'Тестовый товар #' . $i,
                'slug' => Str::slug('Тестовый товар ' . $i . '-' . Str::random(5)),
                'description' => 'Описание для тестового товара номер ' . $i,
                'sku' => 'TEST-' . str_pad($i, 5, '0', STR_PAD_LEFT),
                'purchase_price' => rand(100, 500),
                'price' => rand(600, 2000),
                'stock_quantity' => rand(10, 100),
                'is_active' => true,
                'category_id' => $category->id,
                'in_stock' => true,
            ]);
        }
    }
}
