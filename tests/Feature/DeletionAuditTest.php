<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CustomerDebt;
use App\Models\DebtPayment;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Аудит операций удаления — реальные тесты через HTTP API + проверка состояния БД
 * после каждого сценария (не только HTTP-статус). См. задание аудита.
 */
class DeletionAuditTest extends TestCase
{
    use RefreshDatabase;

    private function staffWithPermission(string $permission, string $role = 'manager'): User
    {
        RolePermission::firstOrCreate(['role' => $role, 'permission' => $permission]);
        return User::factory()->create(['role' => $role]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function order(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'user_id'        => null,
            'status'         => 'pending',
            'payment_status' => 'pending',
            'subtotal'       => 100,
            'total'          => 100,
        ], $attrs));
    }

    // ---------------------------------------------------------------
    // PRODUCT
    // ---------------------------------------------------------------

    public function test_product_without_orders_is_soft_deleted()
    {
        $staff = $this->staffWithPermission('products.delete');
        $product = Product::factory()->create();

        $response = $this->actingAs($staff)->deleteJson("/api/products/{$product->id}");
        $response->assertStatus(200);

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_product_with_order_history_cannot_be_deleted()
    {
        $staff   = $this->staffWithPermission('products.delete');
        $product = Product::factory()->create();
        $order   = $this->order();
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'product_name' => $product->name, 'product_sku' => $product->sku,
            'quantity' => 1, 'price' => 100, 'total' => 100,
        ]);

        $response = $this->actingAs($staff)->deleteJson("/api/products/{$product->id}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'deleted_at' => null]);
    }

    public function test_deleting_product_double_delete_and_missing_id()
    {
        $staff   = $this->staffWithPermission('products.delete');
        $product = Product::factory()->create();

        $this->actingAs($staff)->deleteJson("/api/products/{$product->id}")->assertStatus(200);
        // повторное удаление уже удалённого (soft-deleted) товара — route model binding не находит его
        $this->actingAs($staff)->deleteJson("/api/products/{$product->id}")->assertStatus(404);
        // несуществующий id
        $this->actingAs($staff)->deleteJson('/api/products/999999999')->assertStatus(404);
    }

    public function test_deleting_product_without_permission_is_forbidden()
    {
        $user    = User::factory()->create(['role' => 'user']);
        $product = Product::factory()->create();

        $this->actingAs($user)->deleteJson("/api/products/{$product->id}")->assertStatus(403);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'deleted_at' => null]);
    }

    // ---------------------------------------------------------------
    // CATEGORY — no dependent check, hard delete, DB-level FK is RESTRICT
    // ---------------------------------------------------------------

    public function test_deleting_category_with_products_is_blocked_with_409()
    {
        $staff    = $this->staffWithPermission('categories.delete');
        $category = Category::factory()->create();
        Product::factory()->create(['category_id' => $category->id]);

        $response = $this->actingAs($staff)->deleteJson("/api/categories/{$category->id}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_deleting_category_with_only_soft_deleted_products_is_still_blocked()
    {
        // Soft-deleted товар физически ещё в таблице products и всё ещё держит
        // FK products.category_id — Eloquent-запрос по умолчанию его не видит,
        // но DELETE на уровне БД всё равно упадёт, если не проверять withTrashed().
        $staff    = $this->staffWithPermission('categories.delete');
        $category = Category::factory()->create();
        $product  = Product::factory()->create(['category_id' => $category->id]);
        $product->delete();

        $response = $this->actingAs($staff)->deleteJson("/api/categories/{$category->id}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_deleting_empty_category_succeeds()
    {
        $staff    = $this->staffWithPermission('categories.delete');
        $category = Category::factory()->create();

        $this->actingAs($staff)->deleteJson("/api/categories/{$category->id}")->assertStatus(200);
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_deleting_parent_category_with_children_is_blocked()
    {
        $staff  = $this->staffWithPermission('categories.delete');
        $parent = Category::factory()->create();
        $child  = Category::factory()->create(['parent_id' => $parent->id]);

        $response = $this->actingAs($staff)->deleteJson("/api/categories/{$parent->id}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('categories', ['id' => $parent->id]);
        $this->assertDatabaseHas('categories', ['id' => $child->id]);
    }

    // ---------------------------------------------------------------
    // SUPPLIER
    // ---------------------------------------------------------------

    public function test_supplier_with_purchases_cannot_be_deleted()
    {
        $staff    = $this->staffWithPermission('suppliers.delete');
        $supplier = Supplier::create(['name' => 'ACME']);
        Purchase::create(['supplier_id' => $supplier->id, 'total_amount' => 100, 'paid_amount' => 100]);

        $response = $this->actingAs($staff)->deleteJson("/api/suppliers/{$supplier->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'deleted_at' => null]);
    }

    public function test_supplier_without_purchases_is_soft_deleted()
    {
        $staff    = $this->staffWithPermission('suppliers.delete');
        $supplier = Supplier::create(['name' => 'ACME']);

        $this->actingAs($staff)->deleteJson("/api/suppliers/{$supplier->id}")->assertStatus(200);
        $this->assertSoftDeleted('suppliers', ['id' => $supplier->id]);
    }

    // ---------------------------------------------------------------
    // PURCHASE — deleting must reverse stock + supplier debt + remove FinancialTransaction
    // ---------------------------------------------------------------

    public function test_deleting_purchase_reverses_stock_and_supplier_debt_and_removes_financial_transaction()
    {
        $staff    = $this->staffWithPermission('purchases.delete');
        $supplier = Supplier::create(['name' => 'ACME']);
        $product  = Product::factory()->create(['stock_quantity' => 5]);

        $purchase = Purchase::create([
            'supplier_id' => $supplier->id, 'total_amount' => 800, 'paid_amount' => 300,
        ]);
        PurchaseItem::create([
            'purchase_id' => $purchase->id, 'product_id' => $product->id,
            'quantity' => 10, 'buy_price' => 80, 'total' => 800,
        ]);
        $product->increment('stock_quantity', 10); // симулируем эффект PurchaseController::store
        $supplier->increment('debt_to_supplier', 500); // 800 - 300 неоплачено
        $ft = FinancialTransaction::create([
            'type' => 'expense', 'amount' => 300, 'category' => 'purchase',
            'trackable_type' => Purchase::class, 'trackable_id' => $purchase->id,
        ]);

        $this->assertEquals(15, (float) $product->fresh()->stock_quantity);

        $this->actingAs($staff)->deleteJson("/api/purchases/{$purchase->id}")->assertStatus(200);

        $product->refresh();
        $supplier->refresh();
        $this->assertEquals(5, (float) $product->stock_quantity, 'Остаток должен вернуться к значению до закупки');
        $this->assertEquals(0, (float) $supplier->debt_to_supplier, 'Долг поставщику должен быть аннулирован');
        $this->assertDatabaseMissing('financial_transactions', ['id' => $ft->id, 'deleted_at' => null]);
        $this->assertSoftDeleted('purchases', ['id' => $purchase->id]);

        // Позиции закупки soft-delete'ятся явно вместе с закупкой (согласовано с update()).
        $this->assertSoftDeleted('purchase_items', ['purchase_id' => $purchase->id]);
    }

    public function test_deleting_purchase_is_blocked_if_reversal_would_drive_stock_negative()
    {
        $staff    = $this->staffWithPermission('purchases.delete');
        $supplier = Supplier::create(['name' => 'ACME']);
        $product  = Product::factory()->create(['stock_quantity' => 10]);

        $purchase = Purchase::create(['supplier_id' => $supplier->id, 'total_amount' => 800, 'paid_amount' => 800]);
        PurchaseItem::create([
            'purchase_id' => $purchase->id, 'product_id' => $product->id,
            'quantity' => 10, 'buy_price' => 80, 'total' => 800,
        ]);

        // товар распродан после закупки, остаток сейчас 0
        $product->update(['stock_quantity' => 0]);

        $response = $this->actingAs($staff)->deleteJson("/api/purchases/{$purchase->id}");

        $response->assertStatus(409);
        $this->assertEquals(0, (float) $product->fresh()->stock_quantity, 'Остаток не должен трогаться при заблокированном удалении');
        $this->assertDatabaseHas('purchases', ['id' => $purchase->id, 'deleted_at' => null]);
    }

    // ---------------------------------------------------------------
    // USER — soft delete, staff relation on Order does not use withTrashed()
    // ---------------------------------------------------------------

    public function test_last_admin_cannot_be_deleted()
    {
        $admin = $this->admin();

        $this->actingAs($admin)->deleteJson("/api/users/{$admin->id}")->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'deleted_at' => null]);
    }

    public function test_deleting_cashier_soft_deletes_but_order_staff_reference_survives()
    {
        $admin   = $this->admin();
        $cashier = User::factory()->create(['role' => 'cashier']);
        $order   = $this->order(['staff_id' => $cashier->id, 'status' => 'delivered', 'payment_status' => 'paid']);

        $this->actingAs($admin)->deleteJson("/api/users/{$cashier->id}")->assertStatus(200);
        $this->assertSoftDeleted('users', ['id' => $cashier->id]);

        $this->assertEquals($cashier->id, $order->fresh()->staff_id);

        // Order::staff() использует withTrashed() -> историческая ссылка сохраняется
        $freshOrder = Order::find($order->id);
        $this->assertNotNull($freshOrder->staff, 'Order->staff должен резолвиться даже для soft-deleted кассира');
        $this->assertEquals($cashier->id, $freshOrder->staff->id);
    }

    // ---------------------------------------------------------------
    // ACCOUNTING — debts.view alone must NOT be enough to delete debts/payments
    // (fixed: dedicated debts.manage permission required)
    // ---------------------------------------------------------------

    public function test_debts_view_permission_alone_is_no_longer_enough_to_delete_a_debt_payment()
    {
        $staff = $this->staffWithPermission('debts.view', 'accountant_viewer');

        $debtor = User::factory()->create(['role' => 'user']);
        $debt   = CustomerDebt::create([
            'user_id' => $debtor->id, 'total_amount' => 100, 'paid_amount' => 100, 'remaining_amount' => 0, 'status' => 'paid',
        ]);
        $payment = DebtPayment::create(['customer_debt_id' => $debt->id, 'amount' => 100]);

        $response = $this->actingAs($staff)->deleteJson("/api/accounting/debts/payments/{$payment->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('debt_payments', ['id' => $payment->id, 'deleted_at' => null]);
    }

    public function test_debts_manage_permission_allows_deleting_a_debt_payment()
    {
        $staff = $this->staffWithPermission('debts.manage', 'accountant_manager');

        $debtor = User::factory()->create(['role' => 'user']);
        $debt   = CustomerDebt::create([
            'user_id' => $debtor->id, 'total_amount' => 100, 'paid_amount' => 100, 'remaining_amount' => 0, 'status' => 'paid',
        ]);
        $payment = DebtPayment::create(['customer_debt_id' => $debt->id, 'amount' => 100]);

        $this->actingAs($staff)->deleteJson("/api/accounting/debts/payments/{$payment->id}")->assertStatus(200);
        $this->assertSoftDeleted('debt_payments', ['id' => $payment->id]);
    }

    public function test_debt_payment_deletion_correctly_reverts_debt_and_desyncs_paid_order()
    {
        $staff  = $this->staffWithPermission('debts.manage', 'accountant_manager2');
        $debtor = User::factory()->create(['role' => 'user']);
        $order  = $this->order(['user_id' => $debtor->id, 'payment_status' => 'paid']);
        $debt   = CustomerDebt::create([
            'user_id' => $debtor->id, 'order_id' => $order->id,
            'total_amount' => 100, 'paid_amount' => 100, 'remaining_amount' => 0, 'status' => 'paid',
        ]);
        $payment = DebtPayment::create(['customer_debt_id' => $debt->id, 'amount' => 100]);

        $this->actingAs($staff)->deleteJson("/api/accounting/debts/payments/{$payment->id}")->assertStatus(200);

        $debt->refresh();
        $this->assertEquals(0, (float) $debt->paid_amount);
        $this->assertEquals('active', $debt->status);
        $this->assertEquals('pending', $order->fresh()->payment_status, 'Заказ должен вернуться в pending после отмены единственного платежа по долгу');
    }

    public function test_unpaid_debt_cannot_be_deleted()
    {
        $staff  = $this->staffWithPermission('debts.manage', 'accountant_manager3');
        $debtor = User::factory()->create(['role' => 'user']);
        $debt   = CustomerDebt::create([
            'user_id' => $debtor->id, 'total_amount' => 100, 'paid_amount' => 0, 'remaining_amount' => 100, 'status' => 'active',
        ]);

        $response = $this->actingAs($staff)->deleteJson("/api/accounting/debts/{$debt->id}");
        $response->assertStatus(400);
        $this->assertDatabaseHas('customer_debts', ['id' => $debt->id, 'deleted_at' => null]);
    }

    // ---------------------------------------------------------------
    // FINANCIAL TRANSACTION — unrestricted delete, no reconciliation with trackable
    // ---------------------------------------------------------------

    public function test_deleting_system_linked_financial_transaction_is_blocked()
    {
        $admin    = $this->admin();
        $supplier = Supplier::create(['name' => 'ACME']);
        $purchase = Purchase::create(['supplier_id' => $supplier->id, 'total_amount' => 300, 'paid_amount' => 300]);
        $ft = FinancialTransaction::create([
            'type' => 'expense', 'amount' => 300, 'category' => 'purchase',
            'trackable_type' => Purchase::class, 'trackable_id' => $purchase->id,
        ]);

        $response = $this->actingAs($admin)->deleteJson("/api/finances/{$ft->id}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('financial_transactions', ['id' => $ft->id, 'deleted_at' => null]);
    }

    public function test_deleting_manually_entered_financial_transaction_is_allowed()
    {
        $admin = $this->admin();
        $ft    = FinancialTransaction::create(['type' => 'expense', 'amount' => 50, 'category' => 'other']);

        $this->actingAs($admin)->deleteJson("/api/finances/{$ft->id}")->assertStatus(204);
        $this->assertSoftDeleted('financial_transactions', ['id' => $ft->id]);
    }

    public function test_non_superadmin_cannot_delete_financial_transaction()
    {
        $staff = User::factory()->create(['role' => 'manager']);
        $ft    = FinancialTransaction::create(['type' => 'income', 'amount' => 50, 'category' => 'other']);

        $this->actingAs($staff)->deleteJson("/api/finances/{$ft->id}")->assertStatus(403);
    }

    // ---------------------------------------------------------------
    // ORDER — no destroy route; cancel() reverses stock but not payment/debt
    // ---------------------------------------------------------------

    public function test_no_destroy_route_exists_for_orders()
    {
        $admin = $this->admin();
        $order = $this->order();

        $response = $this->actingAs($admin)->deleteJson("/api/orders/{$order->id}");
        $response->assertStatus(405); // method not allowed / route not defined for DELETE
    }

    public function test_cancelling_paid_order_restores_stock_and_reconciles_payment_status_and_refund()
    {
        $admin   = $this->admin();
        $product = Product::factory()->create(['stock_quantity' => 5]);
        $order   = $this->order(['status' => 'pending', 'payment_status' => 'paid', 'total' => 300]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'product_name' => $product->name, 'product_sku' => $product->sku,
            'quantity' => 3, 'price' => 100, 'total' => 300,
        ]);
        $ft = FinancialTransaction::create([
            'type' => 'income', 'amount' => 300, 'category' => 'sale',
            'trackable_type' => Order::class, 'trackable_id' => $order->id,
        ]);

        $this->actingAs($admin)->postJson("/api/orders/{$order->id}/cancel")->assertStatus(200);

        $order->refresh();
        $this->assertEquals('cancelled', $order->status);
        $this->assertEquals(8, (float) $product->fresh()->stock_quantity, 'Остаток корректно восстанавливается');
        $this->assertEquals('refunded', $order->payment_status, 'Отменённый оплаченный заказ помечается refunded, а не остаётся paid');
        $this->assertDatabaseHas('financial_transactions', ['id' => $ft->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('financial_transactions', [
            'trackable_type' => Order::class, 'trackable_id' => $order->id,
            'category' => 'refund', 'amount' => 300,
        ]);
    }

    public function test_cancelling_unpaid_order_does_not_create_refund_transaction()
    {
        $admin   = $this->admin();
        $product = Product::factory()->create(['stock_quantity' => 5]);
        $order   = $this->order(['status' => 'pending', 'payment_status' => 'pending']);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'product_name' => $product->name, 'product_sku' => $product->sku,
            'quantity' => 2, 'price' => 100, 'total' => 200,
        ]);

        $this->actingAs($admin)->postJson("/api/orders/{$order->id}/cancel")->assertStatus(200);

        $order->refresh();
        $this->assertEquals('pending', $order->payment_status);
        $this->assertDatabaseMissing('financial_transactions', ['trackable_type' => Order::class, 'trackable_id' => $order->id]);
    }

    // ---------------------------------------------------------------
    // PAYMENT — no destroy route; update() can desync status from order.payment_status
    // ---------------------------------------------------------------

    public function test_no_destroy_route_exists_for_payments()
    {
        $admin   = $this->admin();
        $order   = $this->order();
        $payment = \App\Models\Payment::create([
            'order_id' => $order->id, 'payment_method' => 'cash', 'status' => 'completed', 'amount' => 100,
        ]);

        $this->actingAs($admin)->deleteJson("/api/payments/{$payment->id}")->assertStatus(405);
    }

    public function test_payment_update_to_pending_resyncs_order_back_from_paid()
    {
        $admin   = $this->admin();
        $order   = $this->order(['payment_status' => 'pending', 'total' => 100]);
        $payment = \App\Models\Payment::create([
            'order_id' => $order->id, 'payment_method' => 'cash', 'status' => 'pending', 'amount' => 100,
        ]);

        $this->actingAs($admin)->putJson("/api/payments/{$payment->id}", ['status' => 'completed'])->assertStatus(200);
        $this->assertEquals('paid', $order->fresh()->payment_status);

        $this->actingAs($admin)->putJson("/api/payments/{$payment->id}", ['status' => 'pending'])->assertStatus(200);

        $this->assertEquals('pending', $payment->fresh()->status);
        $this->assertEquals('pending', $order->fresh()->payment_status,
            'order.payment_status пересчитывается по агрегату платежей — при откате единственного платежа заказ снова pending');
    }

    public function test_payment_update_recomputes_order_status_across_multiple_payments()
    {
        $admin = $this->admin();
        $order = $this->order(['payment_status' => 'pending', 'total' => 200]);
        $p1 = \App\Models\Payment::create(['order_id' => $order->id, 'payment_method' => 'cash', 'status' => 'completed', 'amount' => 100]);
        $p2 = \App\Models\Payment::create(['order_id' => $order->id, 'payment_method' => 'cash', 'status' => 'pending', 'amount' => 100]);

        // один из двух платежей завершён, но сумма меньше total — заказ ещё не paid
        $this->actingAs($admin)->putJson("/api/payments/{$p1->id}", ['status' => 'completed'])->assertStatus(200);
        $this->assertEquals('pending', $order->fresh()->payment_status);

        $this->actingAs($admin)->putJson("/api/payments/{$p2->id}", ['status' => 'completed'])->assertStatus(200);
        $this->assertEquals('paid', $order->fresh()->payment_status, 'Сумма завершённых платежей покрыла total — заказ paid');

        // откат второго платежа — первый всё ещё completed, но суммы недостаточно
        $this->actingAs($admin)->putJson("/api/payments/{$p2->id}", ['status' => 'refunded'])->assertStatus(200);
        $this->assertEquals('refunded', $order->fresh()->payment_status);
    }

    // ---------------------------------------------------------------
    // ROLE
    // ---------------------------------------------------------------

    public function test_system_role_cannot_be_deleted()
    {
        $admin = $this->admin();
        $role  = Role::where('name', 'cashier')->first() ?? Role::create(['name' => 'cashier', 'label' => 'Кассир', 'is_system' => true]);

        $this->actingAs($admin)->deleteJson("/api/roles/{$role->id}")->assertStatus(403);
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_role_assigned_to_users_cannot_be_deleted()
    {
        $admin = $this->admin();
        $role  = Role::create(['name' => 'custom_role', 'label' => 'Custom', 'is_system' => false]);
        User::factory()->create(['role' => 'custom_role']);

        $this->actingAs($admin)->deleteJson("/api/roles/{$role->id}")->assertStatus(409);
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_unused_custom_role_is_hard_deleted()
    {
        $admin = $this->admin();
        $role  = Role::create(['name' => 'temp_role', 'label' => 'Temp', 'is_system' => false]);
        RolePermission::create(['role' => 'temp_role', 'permission' => 'products.view']);

        $this->actingAs($admin)->deleteJson("/api/roles/{$role->id}")->assertStatus(200);
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
        $this->assertDatabaseMissing('role_permissions', ['role' => 'temp_role']);
    }

    // ---------------------------------------------------------------
    // INVENTORY ADJUSTMENT — deleting the log row does not undo the stock change
    // ---------------------------------------------------------------

    public function test_deleting_inventory_adjustment_does_not_revert_stock_quantity()
    {
        $staff   = $this->staffWithPermission('inventory.delete');
        $product = Product::factory()->create(['stock_quantity' => 50]);

        $adjustment = \App\Models\InventoryAdjustment::create([
            'product_id' => $product->id, 'old_quantity' => 10, 'new_quantity' => 50,
            'difference' => 40, 'reason' => 'stocktake', 'user_id' => $staff->id,
        ]);

        $this->actingAs($staff)->deleteJson("/api/inventory/adjustments/{$adjustment->id}")->assertStatus(200);

        $this->assertSoftDeleted('inventory_adjustments', ['id' => $adjustment->id]);
        $this->assertEquals(50, (float) $product->fresh()->stock_quantity,
            'Удаление записи корректировки — это стирание журнала, а не откат остатка');
    }
}
