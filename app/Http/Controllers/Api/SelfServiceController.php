<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SelfServiceException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\SelfServiceOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SelfServiceController extends Controller
{
    public function __construct(private SelfServiceOrderService $orders)
    {
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'items'               => 'required|array|min:1',
            'items.*.product_id'  => 'required|integer',
            'items.*.quantity'    => 'required|numeric|min:0.001',
            'terminal_id'         => 'nullable|string|max:50',
            'client_uuid'         => 'nullable|uuid',
        ]);

        try {
            $order = $this->orders->createOrder(
                $validated['items'],
                $validated['terminal_id'] ?? null,
                $validated['client_uuid'] ?? null
            );
        } catch (SelfServiceException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $payment = $order->payments->last();

        return response()->json([
            'order'   => $this->presentOrder($order),
            'payment' => $payment ? json_decode((string) $payment->payment_details, true) : null,
        ], 201);
    }

    public function paymentStatus(string $orderUuid)
    {
        $order  = $this->findGuestOrder($orderUuid);
        $result = $this->orders->getPaymentStatus($order);
        $result['order'] = $this->presentOrder($order->fresh(['items']));

        return response()->json($result);
    }

    public function cancel(string $orderUuid)
    {
        $order = $this->findGuestOrder($orderUuid);

        try {
            $this->orders->cancelOrder($order);
        } catch (SelfServiceException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'cancelled']);
    }

    public function show(string $orderUuid)
    {
        $order = $this->findGuestOrder($orderUuid);

        return response()->json($this->presentOrder($order->load('items')));
    }

    public function receipt(string $orderUuid)
    {
        $order = $this->findGuestOrder($orderUuid);
        if ($order->payment_status !== 'paid') {
            abort(404);
        }

        // Переиспользуем существующий рендер термочека кассира — тот же
        // шаблон/настройки магазина, self-service ничего не дублирует.
        return app(ReportController::class)->thermalReceiptHtml($order);
    }

    public function payInfo(string $token)
    {
        try {
            return response()->json($this->orders->payInfo($token));
        } catch (SelfServiceException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function confirmPayment(string $token)
    {
        try {
            $order = $this->orders->confirmPayment($token);
        } catch (SelfServiceException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->presentOrder($order));
    }

    // uuid-only lookup (не числовой id) — иначе гость мог бы перебором
    // /self-service/orders/1, /2, ... смотреть чужие заказы
    private function findGuestOrder(string $orderUuid): Order
    {
        if (! Str::isUuid($orderUuid)) {
            abort(404);
        }

        $order = Order::where('uuid', $orderUuid)->where('channel', 'self_service')->first();
        if (! $order) {
            abort(404);
        }

        return $order;
    }

    // Гостю отдаём только то, что нужно экрану self-service — без staff_id,
    // purchase_price, служебных заметок и данных о других заказах
    private function presentOrder(Order $order): array
    {
        return [
            'uuid'           => $order->uuid,
            'order_number'   => $order->order_number,
            'status'         => $order->status,
            'payment_status' => $order->payment_status,
            'total'          => (float) $order->total,
            'currency'       => $order->currency,
            'items'          => $order->items->map(fn ($item) => [
                'product_name' => $item->product_name,
                'sku'          => $item->product_sku,
                'quantity'     => (float) $item->quantity,
                'price'        => (float) $item->price,
                'total'        => (float) $item->total,
            ]),
        ];
    }
}
