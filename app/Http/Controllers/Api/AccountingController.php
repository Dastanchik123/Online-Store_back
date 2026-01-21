<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerDebt;
use App\Models\DebtPayment;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountingController extends Controller
{

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

        return $query->latest()->paginate($request->get('per_page', 15));
    }

    public function payDebt(Request $request, CustomerDebt $debt)
    {
        $validated = $request->validate([
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($validated, $debt) {
            $payment = DebtPayment::create([
                'customer_debt_id' => $debt->id,
                'amount'           => $validated['amount'],
                'payment_method'   => $validated['payment_method'],
            ]);

            $debt->increment('paid_amount', $validated['amount']);
            $debt->remaining_amount = $debt->total_amount - $debt->paid_amount;

            if ($debt->remaining_amount <= 0) {
                $debt->status           = 'paid';
                $debt->remaining_amount = 0;

                if ($debt->order_id) {
                    $debt->order()->update(['payment_status' => 'paid']);
                }
            } else {
                $debt->status = 'partial';
            }
            $debt->save();

            FinancialTransaction::create([
                'user_id'        => auth()->id(),
                'type'           => 'income',
                'amount'         => $validated['amount'],
                'category'       => 'debt_payment',
                'trackable_type' => DebtPayment::class,
                'trackable_id'   => $payment->id,
                'description'    => "Оплата долга от пользователя #{$debt->user_id}",
                'payment_method' => $validated['payment_method'] ?? 'cash',
            ]);

            return response()->json($debt->load('payments', 'user', 'order'));
        });
    }

    public function deleteDebtPayment(DebtPayment $payment)
    {
        return DB::transaction(function () use ($payment) {
            $debt   = $payment->debt;
            $amount = $payment->amount;

            $debt->decrement('paid_amount', $amount);
            $debt->remaining_amount = $debt->total_amount - $debt->paid_amount;

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
        if ($debt->status !== 'paid') {
            return response()->json(['message' => 'Нельзя удалить неоплаченный долг'], 400);
        }

        return DB::transaction(function () use ($debt) {

            foreach ($debt->payments as $payment) {
                FinancialTransaction::where('trackable_type', DebtPayment::class)
                    ->where('trackable_id', $payment->id)
                    ->delete();
                $payment->delete();
            }

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
