<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncPushIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_operation_uuid_is_applied_only_once()
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $product = Product::factory()->create(['stock_quantity' => 5]);
        $opUuid = (string) Str::uuid();

        // PRODUCT_UPDATE — офлайн-корректировка остатка (реалистичный кейс POS)
        $payload = [
            'operations' => [
                [
                    'op_uuid' => $opUuid,
                    'type'    => 'PRODUCT_UPDATE',
                    'payload' => [
                        'uuid'   => $product->uuid,
                        'fields' => ['stock_quantity' => 42],
                    ],
                ],
            ],
        ];

        // Первая отправка — операция применяется, остаток обновляется
        $first = $this->actingAs($user)->postJson('/api/sync/push', $payload);
        $first->assertStatus(200);
        $first->assertJsonPath('operation_results.0.status', 'success');
        $product->refresh();
        $this->assertEquals(42, (float) $product->stock_quantity);
        $this->assertDatabaseHas('sync_logs', ['operation_uuid' => $opUuid]);

        // Меняем остаток вручную, чтобы отличить "применилось повторно" от "пропущено"
        $product->update(['stock_quantity' => 7]);

        // Повторная отправка того же op_uuid — должна быть пропущена как дубликат,
        // а не применить обновление ещё раз
        $second = $this->actingAs($user)->postJson('/api/sync/push', $payload);
        $second->assertStatus(200);
        $second->assertJsonPath('operation_results.0.status', 'success');
        $second->assertJsonPath('operation_results.0.message', 'Duplicate skip');

        $product->refresh();
        $this->assertEquals(7, (float) $product->stock_quantity, 'Повторная отправка не должна переприменять операцию');
        $this->assertEquals(1, \DB::table('sync_logs')->where('operation_uuid', $opUuid)->count());
    }

    public function test_product_create_operation_generates_slug_and_succeeds()
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $category = \App\Models\Category::factory()->create();
        $opUuid = (string) Str::uuid();

        $payload = [
            'operations' => [
                [
                    'op_uuid' => $opUuid,
                    'type'    => 'PRODUCT_CREATE',
                    'payload' => [
                        'uuid'           => (string) Str::uuid(),
                        'name'           => 'Новый товар с кассы',
                        'sku'            => 'SYNC-SKU-OK',
                        'category_id'    => $category->id,
                        'price'          => 100,
                        'stock_quantity' => 5,
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($user)->postJson('/api/sync/push', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('operation_results.0.status', 'success');

        $product = Product::where('sku', 'SYNC-SKU-OK')->first();
        $this->assertNotNull($product);
        $this->assertNotEmpty($product->slug);
        $this->assertEquals($category->id, $product->category_id);
        $this->assertEquals(1, \DB::table('sync_logs')->where('operation_uuid', $opUuid)->count());
    }

    public function test_product_create_operation_without_category_fails_with_clear_error()
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $opUuid = (string) Str::uuid();

        $payload = [
            'operations' => [
                [
                    'op_uuid' => $opUuid,
                    'type'    => 'PRODUCT_CREATE',
                    'payload' => [
                        'uuid'           => (string) Str::uuid(),
                        'name'           => 'Товар без категории',
                        'sku'            => 'SYNC-SKU-NO-CATEGORY',
                        'price'          => 100,
                        'stock_quantity' => 5,
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($user)->postJson('/api/sync/push', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('operation_results.0.status', 'error');
        $this->assertEquals(0, Product::where('sku', 'SYNC-SKU-NO-CATEGORY')->count());
        // op_uuid не должен считаться "применённым", раз операция реально провалилась
        $this->assertDatabaseMissing('sync_logs', ['operation_uuid' => $opUuid]);
    }

    public function test_duplicate_order_uuid_in_push_does_not_decrement_stock_twice()
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $orderUuid = (string) Str::uuid();

        $orderPayload = [
            'orders' => [
                [
                    'uuid'            => $orderUuid,
                    'order_number'    => 'k1-999',
                    'total_amount'    => 100,
                    'payment_method'  => 'cash',
                    'cash_amount'     => 100,
                    'transfer_amount' => 0,
                    'created_at'      => now()->toDateTimeString(),
                    'items'           => [
                        [
                            'uuid'         => (string) Str::uuid(),
                            'product_uuid' => $product->uuid,
                            'quantity'     => 2,
                            'price'        => 50,
                            'name'         => $product->name,
                            'sku'          => $product->sku,
                        ],
                    ],
                ],
            ],
        ];

        $first = $this->actingAs($user)->postJson('/api/sync/push', $orderPayload);
        $first->assertStatus(200);
        $first->assertJsonPath('results.0.status', 'success');

        $product->refresh();
        $this->assertEquals(8, (float) $product->stock_quantity);
        $this->assertEquals(1, \App\Models\Order::where('uuid', $orderUuid)->count());

        // Повторная отправка того же заказа (тот же order uuid) — должна быть
        // распознана как дубликат (Order::where('uuid', ...)->exists()) и не
        // списывать остаток повторно
        $second = $this->actingAs($user)->postJson('/api/sync/push', $orderPayload);
        $second->assertStatus(200);
        $second->assertJsonPath('results.0.status', 'success');
        $second->assertJsonPath('results.0.message', 'Duplicate skip');

        $product->refresh();
        $this->assertEquals(8, (float) $product->stock_quantity, 'Остаток не должен списываться дважды при повторной push того же заказа');
        $this->assertEquals(1, \App\Models\Order::where('uuid', $orderUuid)->count());
    }

    public function test_sync_push_requires_authentication()
    {
        $response = $this->postJson('/api/sync/push', ['operations' => []]);
        $response->assertStatus(401);
    }

    public function test_weighted_offline_sale_push_recomputes_price_from_catalog()
    {
        $user    = User::factory()->create(['role' => 'cashier']);
        $product = Product::factory()->create([
            'is_weighted'    => true,
            'unit'           => 'кг',
            'purchase_price' => 5,
            'price'          => 12.90,
            'sale_price'     => null,
            'stock_quantity' => 100,
        ]);
        $barcode   = app(\App\Services\WeightedBarcodeService::class)->generate($product, 1.25);
        $orderUuid = (string) Str::uuid();

        $response = $this->actingAs($user)->postJson('/api/sync/push', [
            'orders' => [
                [
                    'uuid'            => $orderUuid,
                    'order_number'    => 'k1-998',
                    // Кассы локальный кэш цены мог устареть — сервер игнорирует
                    // это значение для весового товара со штрихкодом.
                    'total_amount'    => 1.25,
                    'payment_method'  => 'cash',
                    'cash_amount'     => 16.13,
                    'transfer_amount' => 0,
                    'created_at'      => now()->toDateTimeString(),
                    'items'           => [
                        [
                            'uuid'         => (string) Str::uuid(),
                            'product_uuid' => $product->uuid,
                            'quantity'     => 1.25,
                            'price'        => 1,
                            'unit'         => 'кг',
                            'barcode'      => $barcode,
                            'name'         => $product->name,
                            'sku'          => $product->sku,
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('results.0.status', 'success');

        $order = \App\Models\Order::where('uuid', $orderUuid)->first();
        $item  = $order->items()->first();
        $this->assertEqualsWithDelta(16.125, (float) $item->total, 0.01);
        $this->assertEqualsWithDelta(12.90, (float) $item->price, 0.01);
        $this->assertSame('кг', $item->unit);

        $product->refresh();
        $this->assertEqualsWithDelta(98.75, (float) $product->stock_quantity, 0.001);
    }

    public function test_weighted_offline_sale_push_rejects_mismatched_barcode()
    {
        $user    = User::factory()->create(['role' => 'cashier']);
        $product = Product::factory()->create(['is_weighted' => true, 'stock_quantity' => 10]);
        $other   = Product::factory()->create(['is_weighted' => true, 'stock_quantity' => 10]);
        $barcode = app(\App\Services\WeightedBarcodeService::class)->generate($other, 1.0);
        $orderUuid = (string) Str::uuid();

        $response = $this->actingAs($user)->postJson('/api/sync/push', [
            'orders' => [
                [
                    'uuid'            => $orderUuid,
                    'order_number'    => 'k1-997',
                    'total_amount'    => 10,
                    'payment_method'  => 'cash',
                    'cash_amount'     => 10,
                    'transfer_amount' => 0,
                    'created_at'      => now()->toDateTimeString(),
                    'items'           => [
                        [
                            'uuid'         => (string) Str::uuid(),
                            'product_uuid' => $product->uuid,
                            'quantity'     => 1.0,
                            'price'        => 10,
                            'barcode'      => $barcode,
                            'name'         => $product->name,
                            'sku'          => $product->sku,
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('results.0.status', 'error');
        $this->assertEquals(0, \App\Models\Order::where('uuid', $orderUuid)->count());
    }
}
