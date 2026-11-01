<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinancialTransaction;
use App\Models\Payment;
use App\Models\SystemAlert;
use App\Support\Alerts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Алерты для персонала (право alerts.manage): ситуации, требующие ручного
 * действия — возврат лишней QR-оплаты наличными, минус по остатку,
 * расхождение суммы оплаты.
 */
class AlertController extends Controller
{
    public function index(Request $request)
    {
        $query = SystemAlert::query()->latest();

        if ($request->get('status', 'open') === 'open') {
            $query->where('is_read', false);
        }

        $perPage = min(max((int) $request->get('per_page', 30), 1), 100);

        return response()->json(array_merge(
            $query->paginate($perPage)->toArray(),
            ['open_count' => SystemAlert::where('is_read', false)->count()]
        ));
    }

    public function count()
    {
        return response()->json(['open_count' => SystemAlert::where('is_read', false)->count()]);
    }

    public function resolve(Request $request, SystemAlert $alert)
    {
        $request->validate(['comment' => 'nullable|string|max:500']);

        $alert->update([
            'is_read' => true,
            'data'    => array_merge((array) $alert->data, [
                'resolved_by'      => $request->user()->id,
                'resolved_by_name' => $request->user()->name,
                'resolved_at'      => now()->toIso8601String(),
                'comment'          => $request->comment,
            ]),
        ]);

        return response()->json($alert);
    }

    /**
     * Лишняя QR-оплата возвращена покупателю наличными из кассы: расход в
     * бухгалтерии (привязан к платежу, как и приход по нему), платёж — refunded,
     * алерт закрыт.
     */
    public function refundCash(Request $request, SystemAlert $alert)
    {
        if ($alert->type !== Alerts::PAYMENT_NEEDS_REFUND) {
            return response()->json(['message' => 'Это уведомление не про возврат оплаты.'], 422);
        }

        $request->validate(['comment' => 'nullable|string|max:500']);

        return DB::transaction(function () use ($request, $alert) {
            $alert   = SystemAlert::whereKey($alert->id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($alert->data['payment_id'] ?? null)->lockForUpdate()->first();

            if ($alert->is_read || ! $payment || $payment->status === 'refunded') {
                return response()->json(['message' => 'Возврат по этому уведомлению уже оформлен.'], 409);
            }

            FinancialTransaction::create([
                'user_id'        => $request->user()->id,
                'type'           => 'expense',
                'amount'         => $payment->amount,
                'category'       => 'refund',
                'trackable_type' => Payment::class,
                'trackable_id'   => $payment->id,
                'description'    => 'Возврат лишней QR-оплаты наличными, заказ #' . ($alert->data['order_number'] ?? $payment->order_id),
                'payment_method' => 'cash',
            ]);

            $payment->update(['status' => 'refunded']);

            $alert->update([
                'is_read' => true,
                'data'    => array_merge((array) $alert->data, [
                    'resolved_by'      => $request->user()->id,
                    'resolved_by_name' => $request->user()->name,
                    'resolved_at'      => now()->toIso8601String(),
                    'refunded_cash'    => (string) $payment->amount,
                    'comment'          => $request->comment,
                ]),
            ]);

            return response()->json($alert->fresh());
        });
    }
}
