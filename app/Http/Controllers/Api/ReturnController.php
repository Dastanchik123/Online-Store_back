<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinancialTransaction;
use App\Models\Order;
use Illuminate\Http\Request;

class ReturnController extends Controller
{
    
    public function index(Request $request)
    {
        $query = FinancialTransaction::where('category', 'refund')
            ->with(['user', 'trackable.items.product'])
            ->latest();

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhereHasMorph('trackable', [Order::class], function ($oq) use ($search) {
                        $oq->where('order_number', 'like', "%{$search}%")
                            ->orWhere('id', 'like', "%{$search}%");
                    });
            });
        }

        return $query->paginate($request->get('per_page', 15));
    }

    
    public function summary(Request $request)
    {
        $query = FinancialTransaction::where('category', 'refund');

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhereHasMorph('trackable', [Order::class], function ($oq) use ($search) {
                        $oq->where('order_number', 'like', "%{$search}%")
                            ->orWhere('id', 'like', "%{$search}%");
                    });
            });
        }

        $totalAmount = $query->sum('amount');
        $count       = $query->count();

        return response()->json([
            'total_refunded' => (float) $totalAmount,
            'returns_count'  => $count,
        ]);
    }
}
