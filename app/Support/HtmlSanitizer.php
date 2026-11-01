<?php

namespace App\Support;

/**
 * Очистка HTML статей блога: фронт выводит их через v-html, поэтому
 * <script>, on*-атрибуты и javascript:-ссылки от сотрудника с правом
 * blog.manage выполнились бы у любого читателя, включая админа (и прочитали
 * бы его токен из localStorage). HTMLPurifier — зависимость phpspreadsheet.
 */
class HtmlSanitizer
{
    private static ?\HTMLPurifier $purifier = null;

    public static function clean(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        return self::purifier()->purify($html);
    }

    private static function purifier(): \HTMLPurifier
    {
        if (self::$purifier === null) {
            $config = \HTMLPurifier_Config::createDefault();
            $config->set('HTML.Allowed', implode(',', [
                'p', 'br', 'hr', 'b', 'strong', 'i', 'em', 'u', 's', 'span', 'div',
                'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'code',
                'ul', 'ol', 'li', 'a[href|title|target]', 'img[src|alt|title|width|height]',
                'table', 'thead', 'tbody', 'tr', 'th', 'td',
            ]));
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
            $config->set('Attr.AllowedFrameTargets', ['_blank']);
            $config->set('HTML.TargetNoopener', true);
            // Кэш определений — во framework-кэше (папка может отсутствовать
            // на свежем контейнере — тогда без кэша, это лишь медленнее)
            $cacheDir = storage_path('framework/cache/htmlpurifier');
            if (is_dir($cacheDir) || @mkdir($cacheDir, 0775, true)) {
                $config->set('Cache.SerializerPath', $cacheDir);
            } else {
                $config->set('Cache.DefinitionImpl', null);
            }
            self::$purifier = new \HTMLPurifier($config);
        }

        return self::$purifier;
    }
}
