<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    
    public function index(Request $request)
    {
        $user = Auth::user();
        $query = Payment::query()->with('order');

        if ($user) {
            $query->whereHas('order', function($q) use ($user) {
                $q->where('user_id', $user->id);
            });
        }

        if ($request->has('order_id')) {
            $query->where('order_id', $request->order_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $perPage = $request->get('per_page', 15);
        $payments = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($payments);
    }

    
    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'payment_method' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'currency' => 'nullable|string|max:3',
            'transaction_id' => 'nullable|string|max:255|unique:payments',
            'payment_details' => 'nullable|string',
        ]);

        $order = Order::findOrFail($validated['order_id']);

        
        $user = Auth::user();
        if ($user && $order->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($order->payment_status === 'paid') {
            return response()->json(['message' => 'Order is already paid'], 400);
        }

        DB::beginTransaction();
        try {
            $payment = Payment::create([
                'order_id' => $order->id,
                'payment_method' => $validated['payment_method'],
                'status' => 'processing',
                'amount' => $validated['amount'],
                'currency' => $validated['currency'] ?? 'USD',
                'transaction_id' => $validated['transaction_id'] ?? null,
                'payment_details' => $validated['payment_details'] ?? null,
            ]);



            $payment->update([
                'status' => 'completed',
                'paid_at' => now(),
            ]);

            $order->syncPaymentStatus();

            DB::commit();

            try {
                event(new \App\Events\OrderStatusUpdated($order));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Broadcast failed (OrderStatusUpdated): ' . $e->getMessage());
            }

            return response()->json($payment->load('order'), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Payment processing failed: ' . $e->getMessage()], 500);
        }
    }

    
    public function show(Payment $payment)
    {
        $user = Auth::user();
        
        if ($user && $payment->order->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $payment->load('order');
        return response()->json($payment);
    }

    
    public function update(Request $request, Payment $payment)
    {
        $user = Auth::user();
        $isStaff = $user && in_array($user->role, ['admin', 'purchaser', 'cashier', 'manager']);
        if ($user && !$isStaff && $payment->order->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'status' => 'sometimes|in:pending,processing,completed,failed,refunded',
            'transaction_id' => 'nullable|string|max:255',
            'payment_details' => 'nullable|string',
        ]);

        DB::transaction(function () use ($validated, $payment) {
            if (($validated['status'] ?? null) === 'completed' && !$payment->paid_at) {
                $validated['paid_at'] = now();
            }

            $payment->update($validated);

            if (isset($validated['status'])) {
                $payment->order->syncPaymentStatus();
            }
        });

        if (isset($validated['status'])) {
            try {
                event(new \App\Events\OrderStatusUpdated($payment->order->fresh()));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Broadcast failed (OrderStatusUpdated): ' . $e->getMessage());
            }
        }

        return response()->json($payment->fresh()->load('order'));
    }
}

