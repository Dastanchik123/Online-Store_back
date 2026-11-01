<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSortTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name, array $attrs = []): Product
    {
        return Product::factory()->create(array_merge([
            'name'           => $name,
            'stock_quantity' => 10,
            'in_stock'       => true,
            'is_active'      => true,
            'sales_count'    => 0,
            'views_count'    => 0,
            'created_at'     => '2026-10-06 19:12:17',
        ], $attrs));
    }

    private function names(array $query): array
    {
        return collect($this->getJson('/api/products?' . http_build_query($query))->assertOk()->json('data'))
            ->pluck('name')->all();
    }

    public function test_popular_puts_in_stock_bestsellers_first_and_sold_out_last()
    {
        $this->product('A мало продаж', ['sales_count' => 1]);
        $this->product('B хит', ['sales_count' => 50]);
        $this->product('C хит, но остаток в минусе', ['sales_count' => 100, 'stock_quantity' => -1]);
        $this->product('D нет в наличии', ['sales_count' => 90, 'in_stock' => false, 'stock_quantity' => 0]);
        $this->product('E без продаж, но смотрят', ['views_count' => 30]);

        $this->assertSame(
            ['B хит', 'A мало продаж', 'E без продаж, но смотрят', 'C хит, но остаток в минусе', 'D нет в наличии'],
            $this->names(['sort_by' => 'popular', 'per_page' => 10])
        );
    }

    public function test_equal_products_keep_stable_order_across_pages()
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->product("Товар {$i}");
        }

        $page1 = $this->names(['sort_by' => 'popular', 'per_page' => 3, 'page' => 1]);
        $page2 = $this->names(['sort_by' => 'popular', 'per_page' => 3, 'page' => 2]);

        $this->assertCount(6, array_unique(array_merge($page1, $page2)));
    }

    public function test_default_sort_is_still_by_name_for_admin_lists()
    {
        $this->product('Бета', ['sales_count' => 10]);
        $this->product('Альфа');

        $this->assertSame(['Альфа', 'Бета'], $this->names(['per_page' => 10]));
    }
}
