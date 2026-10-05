<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\RolePermission;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierReturnTest extends TestCase
{
    use RefreshDatabase;

    private function staffWithPermission(string $permission, string $role = 'purchaser'): User
    {
        RolePermission::firstOrCreate(['role' => $role, 'permission' => $permission]);
        return User::factory()->create(['role' => $role]);
    }

    private function purchaseWithItem(Supplier $supplier, Product $product, float $quantity, float $buyPrice, float $paidAmount = null): array
    {
        $total = $quantity * $buyPrice;
        $paid  = $paidAmount ?? $total;

        $purchase = Purchase::create([
            'supplier_id'  => $supplier->id,
            'total_amount' => $total,
            'paid_amount'  => $paid,
        ]);
        $item = PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'product_id'  => $product->id,
            'quantity'    => $quantity,
            'buy_price'   => $buyPrice,
            'total'       => $total,
        ]);
        $product->increment('stock_quantity', $quantity); // симулируем эффект PurchaseController::store
        if ($total > $paid) {
            $supplier->increment('debt_to_supplier', $total - $paid);
        }

        return [$purchase, $item];
    }

    private function createReturn(User $staff, Supplier $supplier, PurchaseItem $item, float $quantity): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($staff)->postJson('/api/supplier-returns', [
            'supplier_id' => $supplier->id,
            'reason'      => 'Брак',
            'items'       => [
                ['purchase_item_id' => $item->id, 'quantity' => $quantity],
            ],
        ]);
    }

    // ---------------------------------------------------------------
    // 1. Создание draft-возврата
    // ---------------------------------------------------------------

    public function test_create_draft_return_computes_total_correctly()
    {
        $staff    = $this->staffWithPermission('supplier_returns.manage');
        $supplier = Supplier::create(['name' => 'ACME']);
        $product  = Product::factory()->create(['stock_quantity' => 0]);
        [$purchase, $item] = $this->purchaseWithItem($supplier, $product, 100, 100);

        $response = $this->createReturn($staff, $supplier, $item, 20);

        $response->assertStatus(201);
        $response->assertJsonFragment(['status' => 'draft', 'total_amount' => '2000.00']);
        $this->assertDatabaseHas('supplier_return_items', [
            'purchase_item_id' => $item->id,
            'quantity'         => 20,
            'unit_cost'        => 100,
            'total'            => 2000,
        ]);
        // draft ещё не влияет на склад/долг
        $this->assertEquals(100, (float) $product->fresh()->stock_quantity);
        $this->assertEquals(0, (float) $supplier->fresh()->debt_to_supplier);
    }

    // ---------------------------------------------------------------
    // 2. Нельзя запросить в draft больше доступного
    // ---------------------------------------------------------------

    public function test_cannot_create_return_exceeding_available_quantity()
    {
        $staff    = $this->staffWithPermission('supplier_returns.manage');
        $supplier = Supplier::create(['name' => 'ACME']);
        $product  = Product::factory()->create(['stock_quantity' => 0]);
        [, $item] = $this->purchaseWithItem($supplier, $product, 100, 100);

        $response = $this->createReturn($staff, $supplier, $item, 101);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('supplier_returns', ['supplier_id' => $supplier->id]);
    }

    // ---------------------------------------------------------------
    // 3. confirm списывает остаток, увеличивает returned_quantity, уменьшает долг
    // ---------------------------------------------------------------

    public function test_confirm_decrements_stock_and_supplier_debt_and_increments_returned_quantity()
    {
        $staff    = $this->staffWithPermission('supplier_returns.manage');
        $supplier = Supplier::create(['name' => 'ACME']);
        $product  = Product::factory()->create(['stock_quantity' => 0]);
        // закупка полностью оплачена -> после возврата долг должен уйти в минус
        [, $item] = $this->purchaseWithItem($supplier, $product, 100, 100, 10000);

        $createResp = $this->createReturn($staff, $supplier, $item, 20);
        $returnId   = $createResp->json('id');

        $confirmResp = $this->actingAs($staff)->postJson("/api/supplier-returns/{$returnId}/confirm");
        $confirmResp->assertStatus(200);
        $confirmResp->assertJsonFragment(['status' => 'confirmed']);

        $this->assertEquals(80, (float) $product->fresh()->stock_quantity, 'Остаток должен уменьшиться на возвращённое количество');
        $this->assertEquals(20, (float) $item->fresh()->returned_quantity);
        $this->assertEquals(-2000, (float) $supplier->fresh()->debt_to_supplier, 'Поставщик оплачен полностью — после возврата он должен нам 2000');

        $this->assertDatabaseHas('inventory_adjustments', [
            'product_id'     => $product->id,
            'reason'         => 'supplier_return',
            'reference_type' => SupplierReturn::class,
            'reference_id'   => $returnId,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action'         => 'supplier_return.confirmed',
            'auditable_type' => SupplierReturn::class,
            'auditable_id'   => $returnId,
        ]);
    }

    // ---------------------------------------------------------------
    // 4. confirm отклоняется, если физически на складе меньше, чем в закупке
    // (Этап 19 Сценарий 8)
    // ---------------------------------------------------------------

    public function test_confirm_is_rejected_when_current_stock_is_lower_than_requested_return()
    {
        $staff    = $this->staffWithPermission('supplier_returns.manage');
        $supplier = Supplier::create(['name' => 'ACME']);
        $product  = Product::factory()->create(['stock_quantity' => 0]);
        [, $item] = $this->purchaseWithItem($supplier, $product, 100, 100);

        $createResp = $this->createReturn($staff, $supplier, $item, 50);
        $returnId   = $createResp->json('id');

        // товар распродан после закупки — на складе осталось только 20
        $product->update(['stock_quantity' => 20]);

        $confirmResp = $this->actingAs($staff)->postJson("/api/supplier-returns/{$returnId}/confirm");

        $confirmResp->assertStatus(422);
        $this->assertEquals(20, (float) $product->fresh()->stock_quantity, 'Остаток не должен трогаться при заблокированном confirm');
        $this->assertEquals(0, (float) $item->fresh()->returned_quantity);
        $this->assertEquals('draft', SupplierReturn::find($returnId)->status);
    }

    // ---------------------------------------------------------------
    // 5. Полный возврат проходит; попытка вернуть ещё 1 — отклоняется
    // (Этап 19 Сценарий 3)
    // ---------------------------------------------------------------

    public function test_returning_entire_quantity_then_more_is_rejected()
    {
        $staff    = $this->staffWithPermission('supplier_returns.manage');
        $supplier = Supplier::create(['name' => 'ACME']);
        $product  = Product::factory()->create(['stock_quantity' => 0]);
        [, $item] = $this->purchaseWithItem($supplier, $product, 100, 100);

        $firstReturn = $this->createReturn($staff, $supplier, $item, 100);
        $this->actingAs($staff)->postJson("/api/supplier-returns/{$firstReturn->json('id')}/confirm")->assertStatus(200);

        $this->assertEquals(100, (float) $item->fresh()->returned_quantity);

        // повторный возврат по этой же позиции — доступно уже 0
        $secondResponse = $this->createReturn($staff, $supplier, $item, 1);
        $secondResponse->assertStatus(422);
    }

    // ---------------------------------------------------------------
    // 6. Повторный confirm одного и того же документа — идемпотентность
    // (Этап 19 Сценарий 10)
    // ---------------------------------------------------------------

    public function test_confirming_same_return_twice_is_rejected_and_does_not_double_apply()
    {
        $staff    = $this->staffWithPermission('supplier_returns.manage');
        $supplier = Supplier::create(['name' => 'ACME']);
        $product  = Product::factory()->create(['stock_quantity' => 0]);
        [, $item] = $this->purchaseWithItem($supplier, $product, 100, 100);

        $createResp = $this->createReturn($staff, $supplier, $item, 20);
        $returnId   = $createResp->json('id');

        $this->actingAs($staff)->postJson("/api/supplier-returns/{$returnId}/confirm")->assertStatus(200);
        $secondConfirm = $this->actingAs($staff)->postJson("/api/supplier-returns/{$returnId}/confirm");

        $secondConfirm->assertStatus(409);
        $this->assertEquals(80, (float) $product->fresh()->stock_quantity, 'Остаток не должен списаться дважды');
        $this->assertEquals(20, (float) $item->fresh()->returned_quantity);
    }

    // ---------------------------------------------------------------
    // 7. cancel после confirm восстанавливает всё (Этап 19 Сценарий 4)
    // ---------------------------------------------------------------

    public function test_cancel_restores_stock_returned_quantity_and_supplier_debt()
    {
        $staff    = $this->staffWithPermission('supplier_returns.manage');
        $supplier = Supplier::create(['name' => 'ACME']);
        $product  = Product::factory()->create(['stock_quantity' => 0]);
        [, $item] = $this->purchaseWithItem($supplier, $product, 100, 100, 10000);

        $createResp = $this->createReturn($staff, $supplier, $item, 30);
        $returnId   = $createResp->json('id');
        $this->actingAs($staff)->postJson("/api/supplier-returns/{$returnId}/confirm")->assertStatus(200);

        $this->assertEquals(70, (float) $product->fresh()->stock_quantity);
        $this->assertEquals(-3000, (float) $supplier->fresh()->debt_to_supplier);

        $cancelResp = $this->actingAs($staff)->postJson("/api/supplier-returns/{$returnId}/cancel");
        $cancelResp->assertStatus(200);
        $cancelResp->assertJsonFragment(['status' => 'cancelled']);

        $this->assertEquals(100, (float) $product->fresh()->stock_quantity, 'Остаток должен полностью восстановиться');
        $this->assertEquals(0, (float) $item->fresh()->returned_quantity);
        $this->assertEquals(0, (float) $supplier->fresh()->debt_to_supplier, 'Долг должен полностью восстановиться');

        $this->assertDatabaseHas('audit_logs', [
            'action'         => 'supplier_return.cancelled',
            'auditable_type' => SupplierReturn::class,
            'auditable_id'   => $returnId,
        ]);
    }

    // ---------------------------------------------------------------
    // 8. Две закупки одного товара по разным ценам — возврат привязан
    // к правильной закупке (Этап 19 Сценарий 5)
    // ---------------------------------------------------------------

    public function test_return_from_specific_purchase_does_not_affect_other_purchase_of_same_product()
    {
        $staff    = $this->staffWithPermission('supplier_returns.manage');
        $supplier = Supplier::create(['name' => 'ACME']);
        $product  = Product::factory()->create(['stock_quantity' => 0]);

        [, $item1] = $this->purchaseWithItem($supplier, $product, 100, 100); // Purchase #1: 100шт по 100
        [, $item2] = $this->purchaseWithItem($supplier, $product, 100, 120); // Purchase #2: 100шт по 120

        $createResp = $this->createReturn($staff, $supplier, $item1, 20);
        $returnId   = $createResp->json('id');
        $this->actingAs($staff)->postJson("/api/supplier-returns/{$returnId}/confirm")->assertStatus(200);

        $this->assertEquals(20, (float) $item1->fresh()->returned_quantity);
        $this->assertEquals(0, (float) $item2->fresh()->returned_quantity, 'Вторая закупка не должна быть затронута');
        $this->assertDatabaseHas('supplier_return_items', [
            'purchase_item_id' => $item1->id,
            'unit_cost'        => 100, // цена именно из Purchase #1, а не #2
        ]);
        $this->assertEquals(180, (float) $product->fresh()->stock_quantity); // 200 - 20
    }

    // ---------------------------------------------------------------
    // 9. destroy: draft удаляется, confirmed — блокируется
    // ---------------------------------------------------------------

    public function test_destroy_allowed_only_for_draft()
    {
        $staff    = $this->staffWithPermission('supplier_returns.manage');
        $deleter  = $this->staffWithPermission('supplier_returns.delete', 'purchaser2');
        $supplier = Supplier::create(['name' => 'ACME']);
        $product  = Product::factory()->create(['stock_quantity' => 0]);
        [, $item] = $this->purchaseWithItem($supplier, $product, 100, 100);

        $draftResp = $this->createReturn($staff, $supplier, $item, 10);
        $draftId   = $draftResp->json('id');

        $this->actingAs($deleter)->deleteJson("/api/supplier-returns/{$draftId}")->assertStatus(200);
        $this->assertSoftDeleted('supplier_returns', ['id' => $draftId]);

        $confirmedResp = $this->createReturn($staff, $supplier, $item, 10);
        $confirmedId   = $confirmedResp->json('id');
        $this->actingAs($staff)->postJson("/api/supplier-returns/{$confirmedId}/confirm")->assertStatus(200);

        $this->actingAs($deleter)->deleteJson("/api/supplier-returns/{$confirmedId}")->assertStatus(409);
        $this->assertDatabaseHas('supplier_returns', ['id' => $confirmedId, 'deleted_at' => null]);
    }

    // ---------------------------------------------------------------
    // 10. Permission-гейты
    // ---------------------------------------------------------------

    public function test_permission_gates_for_manage_and_delete()
    {
        $noPerm   = User::factory()->create(['role' => 'cashier']);
        $supplier = Supplier::create(['name' => 'ACME']);
        $product  = Product::factory()->create(['stock_quantity' => 0]);
        [, $item] = $this->purchaseWithItem($supplier, $product, 100, 100);

        $this->actingAs($noPerm)->postJson('/api/supplier-returns', [
            'supplier_id' => $supplier->id,
            'items'       => [['purchase_item_id' => $item->id, 'quantity' => 1]],
        ])->assertStatus(403);

        // Роль 'purchaser' по умолчанию имеет и manage, и delete (см. миграцию
        // 2026_08_20_000005) — для чистой проверки "manage без delete" берём
        // отдельную кастомную роль, которой явно выдан только один permission.
        $staff      = $this->staffWithPermission('supplier_returns.manage', 'return_manager_only');
        $createResp = $this->createReturn($staff, $supplier, $item, 10);
        $returnId   = $createResp->json('id');

        $this->actingAs($noPerm)->postJson("/api/supplier-returns/{$returnId}/confirm")->assertStatus(403);
        $this->actingAs($staff)->postJson("/api/supplier-returns/{$returnId}/confirm")->assertStatus(200);
        $this->actingAs($noPerm)->postJson("/api/supplier-returns/{$returnId}/cancel")->assertStatus(403);

        // supplier_returns.manage не даёт права на delete — нужен отдельный supplier_returns.delete
        $draftResp = $this->createReturn($staff, $supplier, $item, 5);
        $this->actingAs($staff)->deleteJson("/api/supplier-returns/{$draftResp->json('id')}")->assertStatus(403);
    }

    // ---------------------------------------------------------------
    // 11. Удаление InventoryAdjustment не откатывает остаток
    // ---------------------------------------------------------------

    public function test_deleting_inventory_adjustment_from_supplier_return_does_not_revert_stock()
    {
        $staff    = $this->staffWithPermission('supplier_returns.manage');
        $adjuster = $this->staffWithPermission('inventory.delete', 'inv_admin');
        $supplier = Supplier::create(['name' => 'ACME']);
        $product  = Product::factory()->create(['stock_quantity' => 0]);
        [, $item] = $this->purchaseWithItem($supplier, $product, 100, 100);

        $createResp = $this->createReturn($staff, $supplier, $item, 20);
        $returnId   = $createResp->json('id');
        $this->actingAs($staff)->postJson("/api/supplier-returns/{$returnId}/confirm")->assertStatus(200);

        $adjustment = InventoryAdjustment::where('reference_type', SupplierReturn::class)
            ->where('reference_id', $returnId)
            ->firstOrFail();

        $this->actingAs($adjuster)->deleteJson("/api/inventory/adjustments/{$adjustment->id}")->assertStatus(200);
        $this->assertSoftDeleted('inventory_adjustments', ['id' => $adjustment->id]);
        $this->assertEquals(80, (float) $product->fresh()->stock_quantity, 'Удаление журнала не откатывает остаток');
    }
}
