<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerDebt;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function dashboard(Request $request)
    {
        $period    = $request->get('period', 'month');
        $startDate = $this->getStartDate($period, $request);
        $endDate   = $request->has('date_to') ? Carbon::parse($request->date_to)->endOfDay() : now()->endOfDay();

        return response()->json([
            'summary'             => $this->getSummaryData($startDate, $endDate),
            'inventory'           => $this->getInventoryData(),
            'clients'             => $this->getClientData(),
            'alerts'              => $this->getAlerts(),
            'chart_data'          => $this->getChartData($startDate, $endDate),
            'recent_transactions' => FinancialTransaction::with('user')->latest()->limit(5)->get(),
        ]);
    }

    private function getStartDate($period, Request $request)
    {
        if ($request->has('date_from')) {
            return Carbon::parse($request->date_from)->startOfDay();
        }

        switch ($period) {
            case 'today':return now()->startOfDay();
            case 'week':return now()->subDays(7)->startOfDay();
            case 'year':return now()->subYear()->startOfDay();
            default: return now()->subMonth()->startOfDay();
        }
    }

    private function getSummaryData($start, $end)
    {
        
        
        $salesQuery = Order::where(function ($q) {
            $q->where('payment_status', 'paid')
                ->orWhereNotIn('status', ['pending', 'cancelled', 'failed']);
        })->whereBetween('created_at', [$start, $end]);

        $totalSales = $salesQuery->sum('total');

        
        $cogs = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where(function ($q) {
                $q->where('orders.payment_status', 'paid')
                    ->orWhereNotIn('orders.status', ['pending', 'cancelled', 'failed']);
            })
            ->whereBetween('orders.created_at', [$start, $end])
            ->sum(DB::raw('order_items.purchase_price * (order_items.quantity - order_items.refunded_quantity)'));

        
        $grossProfit = $totalSales - $cogs;

        
        
        $refunds = FinancialTransaction::where('category', 'refund')
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');

        $expenses = FinancialTransaction::where('type', 'expense')
            ->where('category', '!=', 'refund')
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');

        
        
        $otherIncome = FinancialTransaction::where('type', 'income')
            ->whereNotIn('category', [
                'sale',
                'debt_payment',
                'Продажа товаров (POS - Наличными)',
                'Продажа товаров (POS - Перевод)',
            ])
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');

        $netProfit = $grossProfit - $expenses - $refunds + $otherIncome;

        $expenseBreakdown = FinancialTransaction::where('type', 'expense')
            ->whereBetween('created_at', [$start, $end])
            ->select('category', DB::raw('SUM(amount) as total'))
            ->groupBy('category')
            ->get();

        
        
        

        
        $ftIncomeCash = FinancialTransaction::where('type', 'income')->where('payment_method', 'cash')->sum('amount');
        $ftIncomeBank = FinancialTransaction::where('type', 'income')->where('payment_method', '!=', 'cash')->sum('amount');

        
        $ftExpenseCash = FinancialTransaction::where('type', 'expense')->where('payment_method', 'cash')->sum('amount');
        $ftExpenseBank = FinancialTransaction::where('type', 'expense')->where('payment_method', '!=', 'cash')->sum('amount');

        $totalCash = $ftIncomeCash - $ftExpenseCash;
        $totalBank = $ftIncomeBank - $ftExpenseBank;

        
        $paidRevenue = Order::where('payment_status', 'paid')
            ->whereBetween('created_at', [$start, $end])
            ->sum('total');

        return [
            'total_revenue'     => (float) $totalSales,
            'sales_revenue'     => (float) $totalSales,
            'paid_revenue'      => (float) $paidRevenue,
            'gross_profit'      => (float) $grossProfit,
            'net_profit'        => (float) $netProfit,
            'cogs'              => (float) $cogs,
            'expenses'          => (float) ($expenses + $refunds),
            'other_income'      => (float) $otherIncome,
            'expense_breakdown' => $expenseBreakdown,
            'cash_balance'      => (float) $totalCash,
            'bank_balance'      => (float) $totalBank,
        ];
    }

    private function getInventoryData()
    {
        $totalValue      = Product::sum(DB::raw('stock_quantity * price'));
        $totalCost       = Product::sum(DB::raw('stock_quantity * COALESCE(purchase_price, 0)'));
        $lowStockCount   = Product::where('stock_quantity', '<=', 5)->count();
        $outOfStockCount = Product::where('stock_quantity', '<=', 0)->count();

        return [
            'total_market_value' => (float) $totalValue,
            'total_cost_value'   => (float) $totalCost,
            'low_stock_count'    => $lowStockCount,
            'out_of_stock_count' => $outOfStockCount,
        ];
    }

    private function getClientData()
    {
        $totalUsers  = User::count();
        $activeUsers = Order::whereNotNull('user_id')->distinct()->count('user_id');
        $debtsTotal  = CustomerDebt::whereIn('status', ['active', 'partial'])->sum('remaining_amount');

        return [
            'total_count'          => $totalUsers,
            'active_shopper_count' => $activeUsers,
            'total_debts'          => (float) $debtsTotal,
        ];
    }

    private function getAlerts()
    {
        $lowStock = Product::where('stock_quantity', '>', 0)
            ->where('stock_quantity', '<', 3)
            ->get();

        $alerts = [];
        foreach ($lowStock as $p) {
            $alerts[] = [
                'type'     => 'low_stock',
                'priority' => 'high',
                'message'  => "Товар {$p->name} заканчивается (остаток: {$p->stock_quantity})",
                'data' => ['product_id' => $p->id],
            ];
        }

        return $alerts;
    }

    private function getChartData($start, $end)
    {
        $chartData = [];
        $days      = $start->diffInDays($end) + 1;

        
        $salesByDay = Order::where(function ($q) {
            $q->where('payment_status', 'paid')
                ->orWhereNotIn('status', ['pending', 'cancelled', 'failed']);
        })
            ->whereBetween('created_at', [$start, $end])
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(total) as total'))
            ->groupBy('date')
            ->pluck('total', 'date');

        for ($i = 0; $i < $days; $i++) {
            $d           = $start->copy()->addDays($i);
            $dateStr     = $d->format('Y-m-d');
            $chartData[] = [
                'date'  => $d->format('d.m'),
                'total' => (float) ($salesByDay[$dateStr] ?? 0),
            ];
        }

        return $chartData;
    }
}
