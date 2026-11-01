<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Str;

// Электронная копия чека по QR с бумажного чека (/receipt/{uuid} на сайте).
// Роут публичный, поэтому ищем только по uuid (номера P-/S- идут подряд и
// перебираются) и отдаём лишь то, что напечатано на чеке: без покупателя,
// адреса, кассира и закупочных цен.
class ReceiptController extends Controller
{
    public function show(string $uuid)
    {
        if (! Str::isUuid($uuid)) {
            abort(404);
        }

        $order = Order::with('items.product')->where('uuid', $uuid)->first();
        if (! $order) {
            abort(404);
        }

        $settings = Setting::whereIn('key', [
            'site_name', 'site_inn', 'contact_address', 'contact_phone',
            'receipt_title', 'receipt_phone', 'receipt_footer',
        ])->pluck('value', 'key');

        // Скидка чека распределена по позициям пропорционально — возврат
        // считается так же, как в OrderController::returnItems
        $ratio    = (float) $order->subtotal > 0 ? (float) $order->total / (float) $order->subtotal : 1.0;
        $refunded = 0.0;

        $items = $order->items->map(function ($item) use ($ratio, &$refunded) {
            $refundedQty = (float) $item->refunded_quantity;
            $refunded   += $refundedQty * (float) $item->price * $ratio;

            return [
                'name'              => $item->product_name,
                'quantity'          => (float) $item->quantity,
                'unit'              => $item->is_package
                    ? ($item->product?->package_unit ?? $item->product?->unit ?? 'шт')
                    : ($item->unit ?? $item->product?->unit ?? 'шт'),
                'price'             => (float) $item->price,
                'total'             => (float) $item->total,
                'refunded_quantity' => $refundedQty,
            ];
        })->values();

        return response()->json([
            'shop' => [
                'name'    => $settings['receipt_title'] ?? $settings['site_name'] ?? null,
                'inn'     => $settings['site_inn'] ?? null,
                'address' => $settings['contact_address'] ?? null,
                'phone'   => $settings['receipt_phone'] ?? $settings['contact_phone'] ?? null,
                'footer'  => $settings['receipt_footer'] ?? null,
            ],
            'order_number'   => $order->order_number,
            'channel'        => $order->channel,
            'created_at'     => $order->created_at,
            'status'         => $order->status,
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'currency'       => $order->currency,
            'items'          => $items,
            'subtotal'       => (float) $order->subtotal,
            'discount'       => (float) $order->discount,
            'total'          => (float) $order->total,
            'refunded_total' => round($refunded, 2),
        ]);
    }
}
