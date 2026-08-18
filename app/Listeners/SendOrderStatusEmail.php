<?php

namespace App\Listeners;

use App\Events\OrderStatusUpdated;
use App\Notifications\OrderStatusChangedNotification;

class SendOrderStatusEmail
{
    public function handle(OrderStatusUpdated $event): void
    {
        // Событие шлётся при любом сохранении заказа (в т.ч. правка notes,
        // изменение payment_status без смены status — см. OrderController,
        // PaymentController, SyncController) — письмо про "статус изменился"
        // нужно только когда status реально поменялся.
        if (! $event->order->wasChanged('status')) {
            return;
        }

        $user = $event->order->user;

        if ($user && $user->email) {
            $user->notify(new OrderStatusChangedNotification($event->order));
        }
    }
}
