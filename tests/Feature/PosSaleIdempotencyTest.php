<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PosSaleIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        RolePermission::create(['role' => 'cashier', 'permission' => 'pos.access']);
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->product = Product::factory()->create(['stock_quantity' => 10, 'price' => 100, 'sale_price' => null, 'purchase_price' => 0]);
    }

    private function sale(?string $key, int $quantity = 1)
    {
        $headers = $key ? ['Idempotency-Key' => $key] : [];

        return $this->actingAs($this->cashier)->postJson('/api/pos/sales', [
            'items'           => [['product_id' => $this->product->id, 'quantity' => $quantity, 'price' => 100]],
            'cash_amount'     => 100 * $quantity,
            'transfer_amount' => 0,
        ], $headers);
    }

    public function test_repeated_request_with_same_key_creates_one_sale()
    {
        $first  = $this->sale('key-1');
        $second = $this->sale('key-1');

        $first->assertStatus(201);
        $second->assertStatus(201);
        $second->assertHeader('Idempotent-Replayed', 'true');
        $this->assertEquals($first->json('id'), $second->json('id'));

        $this->assertEquals(1, Order::count());
        $this->assertEquals(9, (float) $this->product->fresh()->stock_quantity);
    }

    public function test_different_keys_create_separate_sales()
    {
        $this->sale('key-1')->assertStatus(201);
        $this->sale('key-2')->assertStatus(201);

        $this->assertEquals(2, Order::count());
    }

    public function test_requests_without_key_are_not_deduplicated()
    {
        $this->sale(null)->assertStatus(201);
        $this->sale(null)->assertStatus(201);

        $this->assertEquals(2, Order::count());
    }

    public function test_same_key_with_different_body_is_rejected()
    {
        $this->sale('key-1', 1)->assertStatus(201);
        $this->sale('key-1', 2)->assertStatus(422);

        $this->assertEquals(1, Order::count());
    }

    public function test_failed_request_releases_key_for_retry()
    {
        // Скидка больше суммы чека — 422 при любых правах; ключ не должен «застрять»
        $this->actingAs($this->cashier)->postJson('/api/pos/sales', [
            'items'           => [['product_id' => $this->product->id, 'quantity' => 1, 'price' => 100]],
            'cash_amount'     => 100,
            'transfer_amount' => 0,
            'manual_discount' => 1000,
        ], ['Idempotency-Key' => 'key-1'])->assertStatus(422);

        $this->assertDatabaseMissing('idempotency_keys', ['key' => 'key-1']);
        $this->sale('key-1')->assertStatus(201);
        $this->assertEquals(1, Order::count());
    }

    public function test_request_still_in_progress_returns_409()
    {
        DB::table('idempotency_keys')->insert([
            'key'          => 'key-1',
            'user_id'      => $this->cashier->id,
            'method'       => 'POST',
            'path'         => 'api/pos/sales',
            'request_hash' => 'whatever',
            'status_code'  => null,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // Тело другое (hash не совпадает) — 422, а не повторная продажа
        $this->sale('key-1')->assertStatus(422);

        DB::table('idempotency_keys')->where('key', 'key-1')->update([
            'request_hash' => hash('sha256', 'POST api/pos/sales ' . json_encode([
                'items'           => [['product_id' => $this->product->id, 'quantity' => 1, 'price' => 100]],
                'cash_amount'     => 100,
                'transfer_amount' => 0,
            ])),
        ]);

        $this->sale('key-1')->assertStatus(409);
        $this->assertEquals(0, Order::count());
    }

    public function test_key_belongs_to_its_user()
    {
        $this->sale('key-1')->assertStatus(201);

        $other = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($other)->postJson('/api/pos/sales', [
            'items'           => [['product_id' => $this->product->id, 'quantity' => 1, 'price' => 100]],
            'cash_amount'     => 100,
            'transfer_amount' => 0,
        ], ['Idempotency-Key' => 'key-1'])->assertStatus(422);

        $this->assertEquals(1, Order::count());
    }

    public function test_ping_answers_without_auth()
    {
        $this->getJson('/api/ping')->assertOk()->assertJson(['ok' => true]);
    }
}
