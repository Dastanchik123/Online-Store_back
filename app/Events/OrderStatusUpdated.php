<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Order $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function broadcastOn(): array
    {
        return [
            // Покупатель на странице отслеживания заказа
            new Channel('order.' . $this->order->order_number),
            // Админка/касса/закупщик — живое обновление списка заказов
            new PrivateChannel('admin.orders'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'OrderStatusUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'id'             => $this->order->id,
            'order_number'   => $this->order->order_number,
            'status'         => $this->order->status,
            'payment_status' => $this->order->payment_status,
            'shipped_at'     => $this->order->shipped_at,
            'delivered_at'   => $this->order->delivered_at,
        ];
    }
}
