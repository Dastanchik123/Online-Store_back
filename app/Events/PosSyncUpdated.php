<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Сигнал POS-терминалам: «данные изменились, заберите обновления через /sync/pull».
 * Публичный канал — payload не содержит данных, только область изменения.
 *
 * ShouldBroadcastNow — см. комментарий в NewOrderPlaced: без этого рассылка
 * шла через очередь (queue.default=database без воркера) и не доходила.
 */
class PosSyncUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $scope;

    public function __construct(string $scope = 'catalog')
    {
        $this->scope = $scope;
    }

    public function broadcastOn(): array
    {
        return [new Channel('pos-sync')];
    }

    public function broadcastAs(): string
    {
        return 'updated';
    }

    public function broadcastWith(): array
    {
        return ['scope' => $this->scope, 'at' => now()->toIso8601String()];
    }
}
