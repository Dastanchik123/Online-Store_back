<?php

namespace App\Listeners;

use App\Events\NewOrderPlaced;
use App\Notifications\OrderPlacedNotification;

class SendOrderPlacedEmail
{
    public function handle(NewOrderPlaced $event): void
    {
        $user = $event->order->user;

        // POS-продажи и заказы гостей не привязаны к пользователю с почтой —
        // письмо просто некому отправлять.
        if ($user && $user->email) {
            $user->notify(new OrderPlacedNotification($event->order));
        }
    }
}
