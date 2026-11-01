<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\RolePermission;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HotGroupsAndReportsTest extends TestCase
{
    use RefreshDatabase;

    private function groups(): array
    {
        return json_decode(Setting::where('key', 'pos_hot_groups')->value('value') ?: '[]', true);
    }

    public function test_hot_groups_require_products_edit_permission()
    {
        $cashier = User::factory()->create(['role' => 'cashier']);

        $this->actingAs($cashier)->postJson('/api/hot-groups', ['groups' => ['Напитки']])->assertStatus(403);

        RolePermission::create(['role' => 'cashier', 'permission' => 'products.edit']);
        $this->actingAs($cashier)->postJson('/api/hot-groups', ['groups' => ['Напитки']])->assertOk();
    }

    public function test_save_groups_trims_and_drops_duplicates_keeping_order()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->postJson('/api/hot-groups', ['groups' => [' Напитки ', 'Инструменты', 'напитки', 'Краски']])
            ->assertOk()
            ->assertJson(['groups' => ['Напитки', 'Инструменты', 'Краски']]);

        $this->assertSame(['Напитки', 'Инструменты', 'Краски'], $this->groups());

        // Видно в публичных настройках (касса берёт оттуда порядок групп)
        $public = $this->getJson('/api/settings/public')->json();
        $this->assertSame(['Напитки', 'Инструменты', 'Краски'], json_decode($public['pos_hot_groups'], true));
    }

    public function test_rename_group_updates_list_and_only_its_products()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson('/api/hot-groups', ['groups' => ['Напитки', 'Инструменты']]);

        $drink = Product::factory()->create(['is_hot' => true, 'hot_group' => 'Напитки']);
        $tool  = Product::factory()->create(['is_hot' => true, 'hot_group' => 'Инструменты']);

        $this->actingAs($admin)
            ->postJson('/api/hot-groups/rename', ['old_name' => 'Напитки', 'new_name' => 'Вода и соки'])
            ->assertOk();

        $this->assertSame(['Вода и соки', 'Инструменты'], $this->groups());
        $this->assertSame('Вода и соки', $drink->fresh()->hot_group);
        $this->assertSame('Инструменты', $tool->fresh()->hot_group);
    }

    public function test_delete_group_removes_its_products_from_hot_only()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson('/api/hot-groups', ['groups' => ['Напитки', 'Инструменты']]);

        $drink = Product::factory()->create(['is_hot' => true, 'hot_group' => 'Напитки', 'hot_order' => 3]);
        $tool  = Product::factory()->create(['is_hot' => true, 'hot_group' => 'Инструменты']);

        $this->actingAs($admin)->postJson('/api/hot-groups/delete', ['name' => 'Напитки'])->assertOk();

        $this->assertSame(['Инструменты'], $this->groups());
        $this->assertFalse((bool) $drink->fresh()->is_hot);
        $this->assertTrue((bool) $tool->fresh()->is_hot);
        // Сам товар остался в каталоге
        $this->assertNotNull($drink->fresh());
    }

    public function test_stock_status_filter_splits_catalog_without_overlap()
    {
        Product::factory()->create(['stock_quantity' => -2]);
        Product::factory()->create(['stock_quantity' => 0]);
        Product::factory()->create(['stock_quantity' => 3]);
        Product::factory()->create(['stock_quantity' => 5]);
        Product::factory()->create(['stock_quantity' => 6]);

        $count = fn ($s) => $this->getJson("/api/products?stock_status={$s}&per_page=50&fields=list")->json('total');

        $this->assertSame(2, $count('out'));
        $this->assertSame(2, $count('low'));
        $this->assertSame(1, $count('in'));
    }

    public function test_products_excel_is_real_xlsx()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Product::factory()->create(['name' => 'Цемент Кызыл-кыя', 'sku' => '00123', 'price' => 390]);

        $response = $this->actingAs($admin)->get('/api/reports/products-excel');

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('.xlsx', $response->headers->get('Content-Disposition'));

        // Это ZIP (сигнатура PK), внутри лист с кириллицей и артикулом-текстом
        $body = $response->getContent();
        $this->assertSame('PK', substr($body, 0, 2));

        $tmp = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($tmp, $body);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmp) === true);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($tmp);

        $this->assertStringContainsString('Цемент Кызыл-кыя', $sheet);
        $this->assertStringContainsString('<t xml:space="preserve">00123</t>', $sheet);
        $this->assertStringContainsString('<v>390</v>', $sheet);
    }
}
