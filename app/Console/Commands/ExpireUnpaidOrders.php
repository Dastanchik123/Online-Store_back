<?php

namespace App\Console\Commands;

use App\Exceptions\OrderStateException;
use App\Exceptions\PaymentProviderUnavailableException;
use App\Exceptions\SelfServiceException;
use App\Models\Order;
use App\Models\Setting;
use App\Services\Orders\OrderLifecycleService;
use App\Services\SelfServiceOrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Отмена брошенных неоплаченных заказов:
 *  - онлайн-заказ с оплатой по QR держит товар (остаток списан при
 *    оформлении) — через online_order_payment_ttl_minutes без оплаты он
 *    отменяется и товар возвращается на склад;
 *  - заказ кассы самообслуживания, который касса так и не закрыла
 *    (перезагрузка, сбой), — QR гасится у провайдера, заказ отменяется.
 * Заказы с оплатой при получении не трогаем.
 *
 * Запуск: по расписанию (app/Console/Kernel.php) — нужен cron с
 * `php artisan schedule:run` каждую минуту; можно и вручную.
 */
class ExpireUnpaidOrders extends Command
{
    protected $signature = 'orders:expire-unpaid';

    protected $description = 'Отменить неоплаченные QR-заказы, у которых истёк срок оплаты';

    // QR живёт 5 минут и перевыпускается со страницы заказа — держать товар
    // час незачем. Планировщик запускает команду раз в 15 минут, так что
    // фактически заказ отменяется через 20–35 минут.
    public const DEFAULT_ONLINE_TTL_MINUTES = 20;

    // Запас сверх времени жизни QR киоска, чтобы не мешать кассе, которая
    // сама проверяет статус и закрывает заказ
    private const SELF_SERVICE_GRACE_MINUTES = 15;

    // Продажа на кассе, ожидающая QR дольше этого, считается брошенной
    private const POS_PENDING_MINUTES = 30;

    public function handle(OrderLifecycleService $lifecycle, SelfServiceOrderService $selfService): int
    {
        $expiredOnline = 0;
        $expiredKiosk  = 0;

        $ttl = self::onlineTtlMinutes();
        $online = Order::where('channel', Order::CHANNEL_ONLINE)
            ->where('payment_method', 'mbank')
            ->where('status', 'pending')
            ->where('payment_status', 'pending')
            ->where('created_at', '<', now()->subMinutes($ttl))
            ->whereDoesntHave('payments', fn ($q) => $q->where('status', 'completed'))
            ->get();

        foreach ($online as $order) {
            try {
                $lifecycle->cancel($order, null, ['pending'], false);
                $expiredOnline++;
            } catch (OrderStateException|PaymentProviderUnavailableException $e) {
                Log::info("orders:expire-unpaid: заказ #{$order->order_number} пропущен: {$e->getMessage()}");
            }
        }

        $kioskTimeout = max(300, (int) (Setting::where('key', 'self_service_payment_timeout')->value('value') ?: 300));
        $kiosk = Order::where('channel', Order::CHANNEL_SELF_SERVICE)
            ->where('status', 'pending')
            ->where('payment_status', 'pending')
            ->where('created_at', '<', now()->subSeconds($kioskTimeout)->subMinutes(self::SELF_SERVICE_GRACE_MINUTES))
            ->get();

        foreach ($kiosk as $order) {
            try {
                $selfService->cancelOrder($order);
                $expiredKiosk++;
            } catch (SelfServiceException $e) {
                Log::info("orders:expire-unpaid: заказ киоска #{$order->order_number} пропущен: {$e->getMessage()}");
            }
        }

        // Продажа на кассе со смешанной оплатой, которую кассир так и не
        // закрыл (QR не оплатили, касса перезагрузилась)
        $expiredPos = 0;
        $pos = Order::where('channel', Order::CHANNEL_POS)
            ->where('status', 'pending')
            ->where('payment_status', 'pending')
            ->where('created_at', '<', now()->subMinutes(self::POS_PENDING_MINUTES))
            ->get();

        foreach ($pos as $order) {
            try {
                app(\App\Services\Pos\PosSaleService::class)->cancelPending($order);
                $expiredPos++;
            } catch (OrderStateException|PaymentProviderUnavailableException $e) {
                Log::info("orders:expire-unpaid: продажа кассы #{$order->order_number} пропущена: {$e->getMessage()}");
            }
        }

        $this->info("Отменено онлайн-заказов: {$expiredOnline}, заказов киоска: {$expiredKiosk}, продаж кассы: {$expiredPos}");

        return self::SUCCESS;
    }

    public static function onlineTtlMinutes(): int
    {
        $raw = Setting::where('key', 'online_order_payment_ttl_minutes')->value('value');

        return is_numeric($raw) ? max(10, (int) $raw) : self::DEFAULT_ONLINE_TTL_MINUTES;
    }
}
