<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    // Те же подписи статусов, что в Online-Store_front/app/pages/order-tracking.vue —
    // при изменении там стоит поправить и здесь.
    private const STATUS_LABELS = [
        'pending'    => 'В обработке',
        'processing' => 'Собирается',
        'shipped'    => 'Отправлен',
        'delivered'  => 'Доставлен',
        'cancelled'  => 'Отменен',
        'refunded'   => 'Возврат оформлен',
    ];

    public function __construct(private Order $order)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $order = $this->order;
        $label = self::STATUS_LABELS[$order->status] ?? $order->status;

        return (new MailMessage)
            ->subject("Заказ #{$order->order_number}: {$label}")
            ->greeting('Обновление по заказу')
            ->line("Статус вашего заказа #{$order->order_number} изменился: {$label}.")
            ->action('Отследить заказ', url('/order-tracking'))
            ->line("Введите номер заказа {$order->order_number} на странице отслеживания, чтобы увидеть детали.");
    }
}
