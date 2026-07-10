<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Сигнал POS-терминалам: «данные изменились, заберите обновления через /sync/pull».
 * Публичный канал — payload не содержит данных, только область изменения.
 */
class PosSyncUpdated implements ShouldBroadcast
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
