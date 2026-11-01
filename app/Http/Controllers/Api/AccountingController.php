<?php
namespace App\Http\Controllers\Api;

use App\Exceptions\PaymentProviderUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\CustomerDebt;
use App\Models\DebtPayment;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Debts\DebtPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountingController extends Controller
{
    public function __construct(private DebtPaymentService $debtPayments)
    {
    }

    public function debts(Request $request)
    {
        $query = CustomerDebt::with(['user', 'order.items.product', 'payments']);

        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->whereIn('status', ['active', 'partial']);
            } elseif ($request->status !== 'all') {
                $query->where('status', $request->status);
            }
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {

                $q->whereHas('user', function ($uq) use ($search) {
                    $uq->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });

                if (is_numeric($search)) {
                    $q->orWhere('order_id', $search)
                        ->orWhere('id', $search);
                } else {
                    $q->orWhere('order_id', 'like', "%{$search}%");
                }
            });
        }

        return $query->latest()->paginate(min(max((int) $request->get('per_page', 15), 1), 200));
    }

    public function payDebt(Request $request, CustomerDebt $debt)
    {
        if (!auth()->user()->hasPermission('debts.view')) {
            return response()->json(['message' => 'Forbidden. Missing permission: debts.view'], 403);
        }

        // Наличными — здесь; безнал — только через QR GoPay (createDebtQr):
        // ручные «MBank/Карта» нельзя было проверить
        $validated = $request->validate([
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string|in:cash',
        ]);

        $debt = $this->debtPayments->payCash($debt, (float) $validated['amount'], auth()->id());

        return response()->json($debt->load('payments', 'user', 'order'));
    }

    // Безнал: QR GoPay на сумму выплаты. В долг записывается только после
    // подтверждения банка (вебхук или проверка статуса)
    public function createDebtQr(Request $request, CustomerDebt $debt)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
        ]);

        try {
            $payment = $this->debtPayments->createQr($debt, (float) $validated['amount']);
        } catch (PaymentProviderUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json($this->presentDebtQr($payment), 201);
    }

    public function debtQrStatus(Request $request, Payment $payment)
    {
        abort_unless($payment->customer_debt_id, 404);

        $payment = $this->debtPayments->qrStatus($payment, $request->boolean('check'));

        return response()->json($this->presentDebtQr($payment));
    }

    public function cancelDebtQr(Payment $payment)
    {
        abort_unless($payment->customer_debt_id, 404);

        try {
            $payment = $this->debtPayments->cancelQr($payment);
        } catch (PaymentProviderUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json($this->presentDebtQr($payment));
    }

    // QR-погашение для окна ожидания: статус, данные QR и долг после оплаты
    private function presentDebtQr(Payment $payment): array
    {
        $details = json_decode((string) $payment->payment_details, true) ?: [];

        return [
            'id'         => $payment->id,
            'status'     => $payment->status,
            'amount'     => (float) $payment->amount,
            'qr_value'   => $details['qr_value'] ?? null,
            'expires_at' => $details['expires_at'] ?? null,
            'debt'       => CustomerDebt::with('payments', 'user', 'order')->find($payment->customer_debt_id),
        ];
    }

    public function deleteDebtPayment(DebtPayment $payment)
    {
        if (!auth()->user()->hasPermission('debts.manage')) {
            return response()->json(['message' => 'Forbidden. Missing permission: debts.manage'], 403);
        }

        return DB::transaction(function () use ($payment) {
            $debt   = CustomerDebt::whereKey($payment->customer_debt_id)->lockForUpdate()->firstOrFail();
            $amount = $payment->amount;

            // По отменённому заказу деньги уже возвращены покупателю — удаление
            // платежа оставило бы возврат без прихода
            if ($debt->status === 'cancelled') {
                return response()->json(['message' => 'Долг отменён вместе с заказом — платёж удалить нельзя.'], 409);
            }
            // Деньги подтвердил банк — удаление разошлось бы с выпиской GoPay
            if ($payment->payment_method === DebtPaymentService::METHOD_QR) {
                return response()->json(['message' => 'Оплату по QR через банк удалить нельзя.'], 409);
            }

            $debt->paid_amount      = round((float) $debt->paid_amount - (float) $amount, 2);
            $debt->remaining_amount = round((float) $debt->total_amount - (float) $debt->paid_amount, 2);

            if ($debt->paid_amount <= 0) {
                $debt->status = 'active';
            } else {
                $debt->status = 'partial';
            }
            $debt->save();

            if ($debt->order_id) {
                $order = $debt->order;
                if ($order->payment_status === 'paid' && $debt->remaining_amount > 0) {
                    $order->update(['payment_status' => 'pending']);
                }
            }

            FinancialTransaction::where('trackable_type', DebtPayment::class)
                ->where('trackable_id', $payment->id)
                ->delete();

            $payment->delete();

            return response()->json([
                'message' => 'Payment deleted and debt reverted',
                'debt'    => $debt->load('payments', 'user', 'order'),
            ]);
        });
    }

    public function deleteDebt(CustomerDebt $debt)
    {
        if (!auth()->user()->hasPermission('debts.manage')) {
            return response()->json(['message' => 'Forbidden. Missing permission: debts.manage'], 403);
        }

        if ($debt->status !== 'paid') {
            return response()->json(['message' => 'Нельзя удалить неоплаченный долг'], 400);
        }

        // Выплаты по долгу — реально полученные деньги: раньше удаление
        // стирало их приход из финансов (выручка пропадала из отчётов), а при
        // отмене заказа возвращалось меньше, чем покупатель внёс
        // (OrderLifecycleService::receivedAmount считает их через долг)
        if ($debt->payments()->exists()) {
            return response()->json([
                'message' => 'По долгу были выплаты — он остаётся в истории для учёта денег. Удалить можно только долг без выплат.',
            ], 409);
        }

        return DB::transaction(function () use ($debt) {
            $debt->delete();

            return response()->json(['message' => 'Debt deleted successfully']);
        });
    }

    public function reports(Request $request)
    {
        $dateFrom = $request->get('date_from');
        $dateTo   = $request->get('date_to');

        $salesQuery    = Order::whereNotIn('status', ['cancelled', 'refunded']);
        $expensesQuery = FinancialTransaction::where('type', 'expense');
        $incomeQuery   = FinancialTransaction::where('type', 'income');

        if ($dateFrom) {
            $salesQuery->whereDate('created_at', '>=', $dateFrom);
            $expensesQuery->whereDate('created_at', '>=', $dateFrom);
            $incomeQuery->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $salesQuery->whereDate('created_at', '<=', $dateTo);
            $expensesQuery->whereDate('created_at', '<=', $dateTo);
            $incomeQuery->whereDate('created_at', '<=', $dateTo);
        }

        if ($request->filled('user_id')) {
            $salesQuery->where(function ($q) use ($request) {
                $q->where('staff_id', $request->user_id)
                    ->orWhere('user_id', $request->user_id);
            });
            $expensesQuery->where('user_id', $request->user_id);
            $incomeQuery->where('user_id', $request->user_id);
        }

        $totalSales       = (float) $salesQuery->sum('total');
        $totalIncome      = (float) $incomeQuery->sum('amount');
        $otherExpenses    = (float) $expensesQuery->where('category', '!=', 'purchase')->sum('amount');
        $purchaseExpenses = (float) FinancialTransaction::where('type', 'expense')->where('category', 'purchase')
            ->when($dateFrom, fn($q) => $q->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo, fn($q) => $q->whereDate('created_at', '<=', $dateTo))
            ->when($request->filled('user_id'), fn($q) => $q->where('user_id', $request->user_id))
            ->sum('amount');

        $cogs = (float) DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereNotIn('orders.status', ['cancelled', 'refunded'])
            ->when($dateFrom, fn($q) => $q->whereDate('orders.created_at', '>=', $dateFrom))
            ->when($dateTo, fn($q) => $q->whereDate('orders.created_at', '<=', $dateTo))
            ->when($request->filled('user_id'), function ($q) use ($request) {
                $q->where(function ($sq) use ($request) {
                    $sq->where('orders.staff_id', $request->user_id)
                        ->orWhere('orders.user_id', $request->user_id);
                });
            })
            ->select(DB::raw('SUM(order_items.quantity * COALESCE(order_items.purchase_price, 0)) as total_cogs'))
            ->value('total_cogs');

        $inventoryMarketValue = (float) Product::sum(DB::raw('stock_quantity * price'));

        $inventoryCostEstimate = (float) Product::sum(DB::raw('stock_quantity * COALESCE(purchase_price, 0)'));

        // Чистая прибыль (Продажи - Себестоимость - Расходы)
        $netProfit = $totalSales - $cogs - $otherExpenses;

        $chartData = [];
        $startDate = now()->subDays(29)->startOfDay();
        $endDate   = now()->endOfDay();

        $incomeByDay = FinancialTransaction::where('type', 'income')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(amount) as total')
            )
            ->groupBy('date')
            ->pluck('total', 'date')
            ->toArray();

        for ($i = 29; $i >= 0; $i--) {
            $date        = now()->subDays($i)->format('Y-m-d');
            $chartData[] = [
                'date'  => now()->subDays($i)->format('d.m'),
                'total' => (float) ($incomeByDay[$date] ?? 0),
            ];
        }

        $recentTransactionsQuery = FinancialTransaction::with('user')->latest();
        if ($dateFrom) {
            $recentTransactionsQuery->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $recentTransactionsQuery->whereDate('created_at', '<=', $dateTo);
        }
        if ($request->filled('user_id')) {
            $recentTransactionsQuery->where('user_id', $request->user_id);
        }

        $avgIncome = count($chartData) > 0 ? array_sum(array_column($chartData, 'total')) / count($chartData) : 0;

        return response()->json([
            'summary'             => [
                'total_revenue'           => round($totalIncome, 2),
                'total_sales'             => round($totalSales, 2),
                'total_income'            => round($totalIncome, 2),
                'total_expenses'          => round($otherExpenses + $purchaseExpenses, 2),
                'total_debts'             => (float) CustomerDebt::whereIn('status', ['active', 'partial'])->sum('remaining_amount'),
                'inventory_value'         => round($inventoryMarketValue, 2),
                'inventory_cost_estimate' => round($inventoryCostEstimate, 2),
                'net_profit_estimate'     => round($netProfit, 2),
                'avg_income_30'           => round($avgIncome, 2),
                'cogs'                    => round($cogs, 2),
            ],
            'chart_data'          => $chartData,
            'recent_transactions' => $recentTransactionsQuery->limit(10)->get(),
        ]);
    }
}
