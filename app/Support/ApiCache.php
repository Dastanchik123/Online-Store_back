<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Версионный кэш GET-ответов API.
 *
 * Ключ включает глобальную версию каталога: любое изменение
 * товаров/категорий/купонов/настроек/баннеров инкрементирует версию (bump),
 * и все старые ключи мгновенно перестают использоваться — не нужно
 * перечислять и чистить их поштучно (file-драйвер не умеет теги).
 */
class ApiCache
{
    private const VERSION_KEY = 'api_cache_version';

    /** Запомнить/отдать закэшированный ответ (массив для response()->json). */
    public static function remember(string $scope, string $querySignature, int $ttlSeconds, \Closure $build): mixed
    {
        $version = (int) Cache::get(self::VERSION_KEY, 1);
        $key = "resp:{$scope}:v{$version}:" . md5($querySignature);

        return Cache::remember($key, $ttlSeconds, $build);
    }

    /** Сбросить весь API-кэш (вызывается наблюдателями моделей). */
    public static function bump(): void
    {
        try {
            $version = (int) Cache::get(self::VERSION_KEY, 1);
            Cache::forever(self::VERSION_KEY, $version + 1);
        } catch (\Throwable $e) {
            // кэш недоступен — не мешаем основной операции
        }
    }
}
