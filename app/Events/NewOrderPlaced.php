<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewOrderPlaced implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Order $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.orders')];
    }

    public function broadcastAs(): string
    {
        return 'NewOrderPlaced';
    }

    public function broadcastWith(): array
    {
        return [
            'id'           => $this->order->id,
            'order_number' => $this->order->order_number,
            'total'        => $this->order->total,
            'status'       => $this->order->status,
            'user_name'    => $this->order->user?->name,
            'created_at'   => $this->order->created_at,
        ];
    }
}
