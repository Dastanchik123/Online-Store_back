<?php
namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{

    public function register()
    {
        // Реальный платёжный шлюз (GoPay/ELQR) вместо временного QR — см.
        // App\Services\Payments\GoPayPaymentProvider. SelfServiceOrderService
        // /контроллер/фронт ничего не знают о смене провайдера.
        $this->app->bind(
            \App\Contracts\PaymentProviderInterface::class,
            \App\Services\Payments\GoPayPaymentProvider::class
        );
    }

    public function boot()
    {
        try {
            if (Schema::hasTable('settings')) {
                View::composer('pdf.*', function ($view) {
                    $settings = Setting::all()->pluck('value', 'key');
                    $view->with('settings', $settings);
                });
            }
        } catch (\Throwable $e) {
            // Database not reachable yet (e.g. during build-time artisan commands) - skip.
        }

        // Автогенерация uuid для новых записей — без него запись
        // не попадает в офлайн-синхронизацию POS-терминалов
        $uuidModels = [
            \App\Models\User::class,
            \App\Models\Category::class,
            \App\Models\Product::class,
            \App\Models\Order::class,
            \App\Models\OrderItem::class,
            \App\Models\FinancialTransaction::class,
        ];
        foreach ($uuidModels as $modelClass) {
            $modelClass::creating(function ($model) {
                if (empty($model->uuid)) {
                    $model->uuid = (string) \Illuminate\Support\Str::uuid();
                }
            });
        }

        // POS-синхронизация: любое изменение каталога/купонов/настроек
        // шлёт сигнал терминалам через websocket (канал pos-sync)
        // + сбрасывает версионный кэш GET-ответов API (ApiCache)
        $notifyPos = function (string $scope) {
            return function () use ($scope) {
                \App\Support\ApiCache::bump();
                try {
                    event(new \App\Events\PosSyncUpdated($scope));
                } catch (\Throwable $e) {
                    // Soketi недоступен — не роняем основную операцию
                }
            };
        };

        // Баннеры в POS не участвуют, но их кэш тоже нужно сбрасывать
        \App\Models\Banner::saved(fn () => \App\Support\ApiCache::bump());
        \App\Models\Banner::deleted(fn () => \App\Support\ApiCache::bump());

        \App\Models\Product::saved($notifyPos('catalog'));
        \App\Models\Product::deleted($notifyPos('catalog'));
        \App\Models\Category::saved($notifyPos('catalog'));
        \App\Models\Category::deleted($notifyPos('catalog'));
        \App\Models\Coupon::saved($notifyPos('coupons'));
        \App\Models\Coupon::deleted($notifyPos('coupons'));
        \App\Models\Setting::saved($notifyPos('settings'));

        // Аудит-лог: кто и когда поменял цену/остаток товара или системную
        // настройку. Хук на модели, а не в контроллере — ловит вообще все
        // пути изменения (продажа, приход, ручное редактирование, tinker),
        // а не только один конкретный endpoint.
        $auditProductFields = ['price', 'sale_price', 'purchase_price', 'stock_quantity'];
        \App\Models\Product::updated(function ($product) use ($auditProductFields) {
            $changes = array_intersect_key($product->getChanges(), array_flip($auditProductFields));
            if (empty($changes)) {
                return;
            }
            $old = [];
            foreach (array_keys($changes) as $field) {
                $old[$field] = $product->getOriginal($field);
            }
            \App\Models\AuditLog::record('product.updated', $product, $old, $changes);
        });

        \App\Models\Setting::saved(function ($setting) {
            if ($setting->wasRecentlyCreated) {
                \App\Models\AuditLog::record('setting.created', $setting, null, ['key' => $setting->key, 'value' => $setting->value]);
                return;
            }
            if (! $setting->wasChanged('value')) {
                return;
            }
            \App\Models\AuditLog::record(
                'setting.updated',
                $setting,
                ['key' => $setting->key, 'value' => $setting->getOriginal('value')],
                ['key' => $setting->key, 'value' => $setting->value]
            );
        });
    }
}
