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
        // Без GOPAY_API_KEY/GOPAY_SECRET_KEY на локальной разработке — временный
        // QR, иначе GoPayClient падает в конструкторе и роняет все роуты
        // OrderController/SelfServiceController (список продаж, оформление заказа).
        // Только для APP_ENV=local и testing (тесты не ходят в настоящий GoPay):
        // во временном QR покупатель сам жмёт "Я оплатил", поэтому на проде
        // без ключей лучше явная ошибка.
        $this->app->bind(
            \App\Contracts\PaymentProviderInterface::class,
            fn ($app) => $app->environment(['local', 'testing']) && ! (config('services.gopay.api_key') && config('services.gopay.secret_key'))
                ? $app->make(\App\Services\Payments\TemporaryQrPaymentProvider::class)
                : $app->make(\App\Services\Payments\GoPayPaymentProvider::class)
        );

        // SMS с кодом входа: smspro.nikita.kg, если заданы логин и пароль.
        // Локально и в тестах без них — код в лог; на проде без них — явная
        // ошибка, а не «SMS ушло» без отправки.
        $this->app->singleton(\App\Contracts\SmsSender::class, function ($app) {
            $sms = config('services.nikita_sms');

            if (! empty($sms['login']) && ! empty($sms['password'])) {
                return new \App\Services\Sms\NikitaSmsSender(
                    $sms['login'],
                    $sms['password'],
                    (string) ($sms['sender'] ?? ''),
                    (bool) ($sms['test'] ?? false),
                );
            }

            if ($app->environment(['local', 'testing'])) {
                return new \App\Services\Sms\LogSmsSender();
            }

            throw new \RuntimeException('SMS-шлюз не настроен: задайте NIKITA_SMS_LOGIN и NIKITA_SMS_PASSWORD в .env');
        });
    }

    public function boot()
    {
        // Покупатель, вошедший по SMS, остаётся в системе год, а не 30 дней
        // (sanctum.expiration) — чтобы не вводить код при каждом заказе.
        // Сотрудники по-прежнему перелогиниваются по общему сроку.
        \Laravel\Sanctum\Sanctum::authenticateAccessTokensUsing(
            fn ($token, bool $isValid) => $isValid || (
                $token->name === \App\Services\Auth\PhoneLoginService::TOKEN_NAME
                && $token->created_at->gt(now()->subYear())
                && $token->tokenable?->role === 'user'
            )
        );

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

        // Автогенерация uuid для новых записей (публичные непредсказуемые
        // идентификаторы, например заказы self-service ищутся по uuid)
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

        // Любое изменение каталога/купонов/настроек/баннеров сбрасывает
        // версионный кэш GET-ответов API (ApiCache)
        $bumpCache = fn () => \App\Support\ApiCache::bump();
        foreach ([
            \App\Models\Banner::class,
            \App\Models\Product::class,
            \App\Models\Category::class,
            \App\Models\Coupon::class,
        ] as $modelClass) {
            $modelClass::saved($bumpCache);
            $modelClass::deleted($bumpCache);
        }
        \App\Models\Setting::saved($bumpCache);

        // Новое/изменённое фото товара сразу попадает в поиск по похожим
        // картинкам. Считаем после отправки ответа — админка не ждёт сервис
        // отпечатков (он может просыпаться до минуты).
        \App\Models\Product::saved(function ($product) {
            $search = app(\App\Services\ImageSearch\ImageSearchService::class);
            if (! $search->isEnabled()) {
                return;
            }
            $photoChanged = $product->wasRecentlyCreated
                ? ($product->image || ! empty($product->images))
                : $product->wasChanged(['image', 'images']);
            if (! $photoChanged) {
                return;
            }
            $productId = $product->id;
            dispatch(function () use ($productId) {
                $fresh = \App\Models\Product::find($productId);
                if ($fresh) {
                    app(\App\Services\ImageSearch\ImageSearchService::class)->indexProduct($fresh);
                }
            })->afterResponse();
        });

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
