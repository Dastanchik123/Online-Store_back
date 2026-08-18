<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderPlacedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private Order $order)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $order = $this->order->loadMissing('items');

        $mail = (new MailMessage)
            ->subject("Заказ #{$order->order_number} принят")
            ->greeting('Спасибо за заказ!')
            ->line("Ваш заказ #{$order->order_number} принят и находится в обработке.");

        foreach ($order->items as $item) {
            $mail->line("— {$item->product_name} × {$item->quantity} = {$item->total} ₸");
        }

        return $mail
            ->line("Итого: {$order->total} ₸")
            ->action('Отследить заказ', url('/order-tracking'))
            ->line("Введите номер заказа {$order->order_number} на странице отслеживания. Мы также сообщим вам, когда статус заказа изменится.");
    }
}
