<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Support\Ean13;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Демо-каталог универсального магазина: категории с подкатегориями и
 * товары с ценами, себестоимостью, скидками, EAN-13, характеристиками,
 * описанием и тремя фото (главное + 2 в галерею), нарисованными локально
 * через GD — без скачивания из интернета.
 *
 *   php artisan db:seed --class=DemoProductsSeeder
 *   DEMO_PRODUCTS=200 php artisan db:seed --class=DemoProductsSeeder
 *
 * Все демо-товары узнаются по slug с "-demo-" и фото в products/demo/:
 * удалить их можно, не задев настоящие товары.
 */
class DemoProductsSeeder extends Seeder
{
    private const IMAGE_SIZE = 800;
    private const IMAGE_DIR  = 'products/demo';

    // Подкатегория => группа горячих товаров. Касса берёт до 50 горячих,
    // поэтому 5 групп по 9 товаров
    private const HOT_GROUPS = [
        'Фрукты и овощи'         => 'Фрукты и овощи',
        'Напитки'                => 'Напитки',
        'Бакалея'                => 'Бакалея',
        'Аксессуары для техники' => 'Аксессуары',
        'Канцтовары'             => 'Канцтовары',
    ];

    private string $font;
    private string $fontBold;
    private int $skuCounter;

    public function run()
    {
        $count = (int) (env('DEMO_PRODUCTS') ?: 500);

        $this->font     = $this->findFont(['arial.ttf', 'DejaVuSans.ttf']);
        $this->fontBold = $this->findFont(['arialbd.ttf', 'DejaVuSans-Bold.ttf']);
        $this->skuCounter = $this->lastSkuBody();

        Storage::disk('public')->makeDirectory(self::IMAGE_DIR . '/gallery');

        $catalog = $this->catalog();
        $subcats = [];
        foreach ($catalog as $parentName => $parent) {
            $parentCategory = $this->category($parentName, null, $parent['description']);
            foreach ($parent['children'] as $childName => $child) {
                $subcats[] = $child + [
                    'category' => $this->category($childName, $parentCategory->id, $child['description'] ?? null),
                    'color'    => $parent['color'],
                    'parent'   => $parentName,
                ];
            }
        }

        // Распределяем товары по подкатегориям равномерно, по кругу
        $used = [];
        for ($i = 0; $i < $count; $i++) {
            $sub = $subcats[$i % count($subcats)];
            $this->createProduct($sub, $i, $used);

            if (($i + 1) % 50 === 0) {
                $this->command?->info('Создано товаров: ' . ($i + 1) . " из {$count}");
            }
        }

        $this->markHotProducts();
    }

    /**
     * Горячие товары — кнопки быстрой продажи на кассе и в self-service.
     * Касса показывает только товары с hot_group, поэтому каждому даём
     * группу: самые продаваемые демо-товары в наличии из ходовых
     * подкатегорий. Настоящие горячие товары (не демо) не трогаем.
     */
    public function markHotProducts(int $perGroup = 9): void
    {
        $demo = fn () => Product::where('slug', 'like', '%-demo-%');

        $demo()->where('is_hot', true)->update(['is_hot' => false, 'hot_order' => 0, 'hot_group' => null]);
        $order = (int) Product::where('is_hot', true)->max('hot_order');

        foreach (self::HOT_GROUPS as $subcategory => $group) {
            $category = Category::where('slug', Str::slug($subcategory))->first();
            if (! $category) {
                continue;
            }

            $products = $demo()->where('category_id', $category->id)->where('in_stock', true)
                ->orderByDesc('sales_count')->limit($perGroup)->get();
            foreach ($products as $product) {
                $product->update(['is_hot' => true, 'hot_order' => ++$order, 'hot_group' => $group]);
            }
        }
    }

    private function createProduct(array $sub, int $index, array &$used): void
    {
        // Уникальная комбинация тип+бренд+вариант внутри прогона
        $attempt = 0;
        do {
            $type    = $sub['types'][array_rand($sub['types'])];
            $brand   = $sub['brands'][array_rand($sub['brands'])];
            $variant = $sub['variants'][array_rand($sub['variants'])];
            $name    = trim("{$type[0]} {$brand} {$variant}");
        } while (isset($used[$name]) && ++$attempt < 30);
        $used[$name] = true;

        [$minPrice, $maxPrice] = [$type[1], $type[2]];
        $price    = $this->roundPrice(random_int($minPrice, $maxPrice));
        $purchase = $this->roundPrice($price * random_int(55, 75) / 100);
        $salePrice = random_int(1, 100) <= 20
            ? max($this->roundPrice($price * random_int(80, 92) / 100), $purchase + 1)
            : null;

        $weighted = ! empty($sub['weighted']);
        $unit     = $weighted ? 'кг' : ($sub['unit'] ?? 'шт');
        $attributes = ['Бренд' => $brand] + ($sub['attrs'])($type[0], $variant);

        $sku = $this->nextSku();

        $data = [
            'name'              => $name,
            'slug'              => Str::slug($name) . '-demo-' . substr($sku, -6),
            'sku'               => $sku,
            'short_description' => $this->shortDescription($type[0], $brand, $sub),
            'description'       => $this->description($type[0], $brand, $variant, $attributes, $sub),
            'purchase_price'    => $purchase,
            'price'             => $price,
            'sale_price'        => $salePrice,
            // ~8% штучных товаров — нет в наличии, чтобы было что проверить в каталоге
            'stock_quantity'    => $weighted
                ? random_int(20, 150) + random_int(0, 9) / 10
                : (random_int(1, 100) <= 8 ? 0 : random_int(3, 120)),
            'is_active'         => true,
            'category_id'       => $sub['category']->id,
            'unit'              => $unit,
            'weight'            => $weighted ? null : round(random_int(5, ($sub['max_weight'] ?? 3) * 100) / 100, 2),
            'dimensions'        => $weighted ? null : $this->dimensions($sub),
            'attributes'        => $attributes,
            'views_count'       => random_int(0, 2500),
            'sales_count'       => random_int(0, 300),
            'is_weighted'       => $weighted,
            'min_weight'        => $weighted ? 0.1 : null,
            'max_weight'        => $weighted ? 15 : null,
        ];
        $data['in_stock'] = $data['stock_quantity'] > 0;

        // Упаковка (коробка/блок) для части товаров, где это уместно
        if (! empty($sub['package']) && random_int(1, 100) <= 60) {
            [$packUnit, $packSize] = $sub['package'];
            $data['package_unit']           = $packUnit;
            $data['package_size']           = $packSize;
            $data['package_price']          = $this->roundPrice($price * $packSize * 0.93);
            $data['package_purchase_price'] = $this->roundPrice($purchase * $packSize);
        }

        $data['image']  = $this->drawImage($sub, $type[0], $brand, $variant, $salePrice ?? $price, $unit, 'main', $attributes);
        $data['images'] = [
            $this->drawImage($sub, $type[0], $brand, $variant, $salePrice ?? $price, $unit, 'side', $attributes),
            $this->drawImage($sub, $type[0], $brand, $variant, $salePrice ?? $price, $unit, 'specs', $attributes),
        ];

        Product::create($data);
    }

    // ---------------------------------------------------------------- данные

    private function category(string $name, ?int $parentId, ?string $description): Category
    {
        return Category::firstOrCreate(
            ['slug' => Str::slug($name)],
            ['name' => $name, 'parent_id' => $parentId, 'description' => $description, 'is_active' => true]
        );
    }

    private function shortDescription(string $type, string $brand, array $sub): string
    {
        $phrases = [
            "{$type} {$brand} — надёжный выбор на каждый день.",
            "Популярная модель {$brand} в категории «{$sub['category']->name}».",
            "{$type} от {$brand}: качество, проверенное покупателями.",
            "Отличное соотношение цены и качества от {$brand}.",
        ];

        return $phrases[array_rand($phrases)];
    }

    private function description(string $type, string $brand, string $variant, array $attributes, array $sub): string
    {
        $intro = [
            "{$type} {$brand}" . ($variant ? " ({$variant})" : '') . " — товар из раздела «{$sub['parent']}», который покупатели выбирают за надёжность и продуманные детали.",
            "Представляем {$type} от {$brand}. Модель" . ($variant ? " в исполнении «{$variant}»" : '') . " подойдёт как для повседневного использования, так и в подарок.",
            "{$brand} давно известен качеством, и {$type}" . ($variant ? " ({$variant})" : '') . " — не исключение.",
        ];
        $middle = [
            'Производитель уделил внимание материалам и сборке: товар прослужит долго и не потеряет внешний вид.',
            'Продуманная конструкция и аккуратное исполнение делают его удобным с первого дня.',
            'Перед отправкой каждая единица проходит проверку на складе магазина.',
            'Простой уход и практичность — то, за что этот товар ценят покупатели.',
        ];
        $outro = [
            'Гарантия магазина — 14 дней на обмен и возврат при сохранении товарного вида.',
            'Доставка по городу — в день заказа, самовывоз из магазина бесплатно.',
            'Есть вопросы? Консультанты магазина помогут с выбором.',
        ];

        $specs = collect($attributes)
            ->map(fn ($value, $key) => "• {$key}: {$value}")
            ->implode("\n");

        return $intro[array_rand($intro)] . ' ' . $middle[array_rand($middle)]
            . "\n\nОсновные характеристики:\n" . $specs
            . "\n\n" . $outro[array_rand($outro)];
    }

    private function dimensions(array $sub): string
    {
        [$a, $b, $c] = $sub['size'] ?? [30, 20, 10];

        return random_int((int) ceil($a / 2), $a) . ' × ' . random_int((int) ceil($b / 2), $b) . ' × ' . random_int(max(1, (int) ceil($c / 2)), $c) . ' см';
    }

    private function roundPrice(float $price): int
    {
        if ($price < 100) {
            return (int) max(5, round($price / 5) * 5);
        }
        if ($price < 2000) {
            return (int) round($price / 10) * 10;
        }

        return (int) round($price / 100) * 100;
    }

    private function lastSkuBody(): int
    {
        return Product::withTrashed()->where('sku', 'like', '2%')
            ->pluck('sku')
            ->filter(fn ($sku) => preg_match('/^2\d{12}$/', $sku))
            ->map(fn ($sku) => (int) substr($sku, 1, 11))
            ->max() ?? 0;
    }

    private function nextSku(): string
    {
        do {
            $this->skuCounter++;
            $body = '2' . str_pad((string) $this->skuCounter, 11, '0', STR_PAD_LEFT);
            $sku  = $body . Ean13::checkDigit($body);
        } while (Product::withTrashed()->where('sku', $sku)->exists());

        return $sku;
    }

    // ------------------------------------------------------------------ фото

    private function findFont(array $names): string
    {
        $dirs = ['C:/Windows/Fonts', '/usr/share/fonts/truetype/dejavu', '/usr/share/fonts/TTF'];
        foreach ($dirs as $dir) {
            foreach ($names as $name) {
                if (is_file("{$dir}/{$name}")) {
                    return "{$dir}/{$name}";
                }
            }
        }

        throw new \RuntimeException('Не найден TTF-шрифт с кириллицей (Arial или DejaVu Sans)');
    }

    /**
     * Рисует карточку товара: градиент цвета категории, «упаковка» с
     * брендом/названием, ценник. $view: main — главное фото, side — второй
     * ракурс, specs — характеристики.
     */
    private function drawImage(array $sub, string $type, string $brand, string $variant, float $price, string $unit, string $view, array $attributes): string
    {
        $s   = self::IMAGE_SIZE;
        $img = imagecreatetruecolor($s, $s);
        imagealphablending($img, true);
        imageantialias($img, true);

        [$r, $g, $b] = sscanf($sub['color'], '#%02x%02x%02x');
        // Каждому товару — свой оттенок, чтобы карточки в каталоге не сливались
        $shift = random_int(-25, 25);
        [$r, $g, $b] = [$this->clamp($r + $shift), $this->clamp($g + $shift / 2), $this->clamp($b - $shift)];

        $light = [$this->mix($r, 255, 0.78), $this->mix($g, 255, 0.78), $this->mix($b, 255, 0.78)];
        $dark  = [$this->mix($r, 0, 0.35), $this->mix($g, 0, 0.35), $this->mix($b, 0, 0.35)];

        // Фон — вертикальный градиент
        for ($y = 0; $y < $s; $y++) {
            $t = $y / $s;
            imageline($img, 0, $y, $s, $y, imagecolorallocate(
                $img,
                (int) $this->mix($light[0], $r, $t * 0.55),
                (int) $this->mix($light[1], $g, $t * 0.55),
                (int) $this->mix($light[2], $b, $t * 0.55)
            ));
        }

        // Декоративные круги
        for ($k = 0; $k < 6; $k++) {
            $alpha = random_int(95, 115);
            $c = imagecolorallocatealpha($img, 255, 255, 255, $alpha);
            $d = random_int(120, 380);
            imagefilledellipse($img, random_int(0, $s), random_int(0, $s), $d, $d, $c);
        }

        $white  = imagecolorallocate($img, 255, 255, 255);
        $ink    = imagecolorallocate($img, 34, 38, 46);
        $muted  = imagecolorallocate($img, 110, 116, 128);
        $accent = imagecolorallocate($img, (int) $dark[0], (int) $dark[1], (int) $dark[2]);
        $shadow = imagecolorallocatealpha($img, 0, 0, 0, 105);

        if ($view === 'specs') {
            $this->roundedRect($img, 70, 80, 730, 720, 36, $shadow, 10);
            $this->roundedRect($img, 60, 70, 720, 710, 36, $white);
            $this->text($img, 26, $accent, 110, 145, 'Характеристики', true);
            imagefilledrectangle($img, 110, 165, 260, 170, $accent);

            $y = 225;
            foreach (array_slice($attributes, 0, 8, true) as $key => $value) {
                $this->text($img, 17, $muted, 110, $y, (string) $key);
                $this->text($img, 19, $ink, 110, $y + 32, Str::limit((string) $value, 40), true);
                $y += 62;
            }
        } else {
            // «Упаковка» товара; на втором ракурсе — повёрнута и смещена
            $isSide = $view === 'side';
            [$x1, $y1, $x2, $y2] = $isSide ? [190, 120, 640, 640] : [150, 110, 650, 610];

            $this->roundedRect($img, $x1 + 14, $y1 + 18, $x2 + 14, $y2 + 18, 40, $shadow, 12);
            $this->roundedRect($img, $x1, $y1, $x2, $y2, 40, $white);
            $this->roundedRect($img, $x1, $y1, $x2, $y1 + 120, 40, $accent);
            imagefilledrectangle($img, $x1, $y1 + 80, $x2, $y1 + 120, $accent);

            $this->text($img, 30, $white, $x1 + 40, $y1 + 78, Str::upper($brand), true);

            // Иконка категории — крупная первая буква типа в круге
            $cx = (int) (($x1 + $x2) / 2);
            imagefilledellipse($img, $cx, $y1 + 245, 170, 170, imagecolorallocate($img, (int) $light[0], (int) $light[1], (int) $light[2]));
            $letter = mb_strtoupper(mb_substr($type, 0, 1));
            $box = imagettfbbox(84, 0, $this->fontBold, $letter);
            imagettftext($img, 84, 0, $cx - (int) (($box[2] - $box[0]) / 2), $y1 + 245 + 42, $accent, $this->fontBold, $letter);

            $lines = $this->wrap($type, 30, $x2 - $x1 - 80, true);
            $y = $y1 + 395;
            foreach (array_slice($lines, 0, 2) as $line) {
                $this->centered($img, 30, $ink, $cx, $y, $line, true);
                $y += 42;
            }
            if ($variant !== '') {
                $this->centered($img, 20, $muted, $cx, $y + 8, Str::limit($variant, 34));
            }

            if ($isSide) {
                $this->text($img, 18, $white, 40, 760, 'Вид сбоку');
            }
        }

        // Ценник
        if ($view !== 'specs') {
            $priceText = number_format($price, 0, '', ' ') . ' сом' . ($unit !== 'шт' ? " / {$unit}" : '');
            $box = imagettfbbox(26, 0, $this->fontBold, $priceText);
            $w = $box[2] - $box[0] + 56;
            $this->roundedRect($img, $s - $w - 40, $s - 120, $s - 40, $s - 50, 35, $ink);
            imagettftext($img, 26, 0, $s - $w - 12, $s - 72, $white, $this->fontBold, $priceText);
        }

        $dir  = self::IMAGE_DIR . ($view === 'main' ? '' : '/gallery');
        $path = $dir . '/image_' . Str::random(12) . '_' . $view . '.webp';

        ob_start();
        imagewebp($img, null, 82);
        Storage::disk('public')->put($path, ob_get_clean());
        imagedestroy($img);

        return $path;
    }

    private function roundedRect($img, int $x1, int $y1, int $x2, int $y2, int $r, int $color, int $blur = 0): void
    {
        // Мягкая тень — несколько расширяющихся полупрозрачных слоёв
        for ($i = $blur; $i >= 0; $i -= 4) {
            imagefilledrectangle($img, $x1 + $r, $y1 - $i, $x2 - $r, $y2 + $i, $color);
            imagefilledrectangle($img, $x1 - $i, $y1 + $r, $x2 + $i, $y2 - $r, $color);
            foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
                imagefilledellipse($img, $cx, $cy, ($r + $i) * 2, ($r + $i) * 2, $color);
            }
            if ($blur === 0) {
                break;
            }
        }
    }

    private function text($img, int $size, int $color, int $x, int $y, string $text, bool $bold = false): void
    {
        imagettftext($img, $size, 0, $x, $y, $color, $bold ? $this->fontBold : $this->font, $text);
    }

    private function centered($img, int $size, int $color, int $cx, int $y, string $text, bool $bold = false): void
    {
        $font = $bold ? $this->fontBold : $this->font;
        $box  = imagettfbbox($size, 0, $font, $text);
        imagettftext($img, $size, 0, $cx - (int) (($box[2] - $box[0]) / 2), $y, $color, $font, $text);
    }

    private function wrap(string $text, int $size, int $maxWidth, bool $bold): array
    {
        $font  = $bold ? $this->fontBold : $this->font;
        $lines = [];
        $line  = '';
        foreach (explode(' ', $text) as $word) {
            $try = trim("{$line} {$word}");
            $box = imagettfbbox($size, 0, $font, $try);
            if ($line !== '' && $box[2] - $box[0] > $maxWidth) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }

        return array_merge($lines, [$line]);
    }

    private function mix(float $from, float $to, float $t): float
    {
        return $from + ($to - $from) * $t;
    }

    private function clamp(float $v): int
    {
        return (int) max(0, min(255, $v));
    }

    // --------------------------------------------------------------- каталог

    /**
     * Категория => [color, description, children]. Подкатегория:
     * types [[название, мин. цена, макс. цена]], brands, variants,
     * attrs(type, variant) => характеристики, size [д, ш, в] см,
     * max_weight кг, опционально unit, package [ед., кол-во], weighted.
     */
    private function catalog(): array
    {
        $pick = fn (array $a) => $a[array_rand($a)];
        $colors = ['Чёрный', 'Белый', 'Серый', 'Синий', 'Красный', 'Зелёный', 'Бежевый', 'Розовый', 'Графит', 'Серебристый'];

        return [
            'Электроника' => [
                'color' => '#3b6fd8',
                'description' => 'Смартфоны, ноутбуки, аудиотехника и аксессуары.',
                'children' => [
                    'Смартфоны' => [
                        'types'    => [['Смартфон', 9000, 95000], ['Кнопочный телефон', 1200, 4500]],
                        'brands'   => ['Samsung', 'Xiaomi', 'Apple', 'Realme', 'Honor', 'Tecno', 'Infinix', 'Poco'],
                        'variants' => ['4/64 ГБ', '6/128 ГБ', '8/256 ГБ', '12/256 ГБ', '8/128 ГБ'],
                        'attrs'    => fn ($t, $v) => [
                            'Память' => $v, 'Диагональ экрана' => $pick(['6,1"', '6,5"', '6,7"', '6,8"']),
                            'Аккумулятор' => $pick(['4500', '5000', '5500', '6000']) . ' мА·ч',
                            'Камера' => $pick(['50', '64', '108', '200']) . ' Мп', 'Цвет' => $pick($colors),
                            'Гарантия' => '12 месяцев',
                        ],
                        'size' => [17, 8, 1], 'max_weight' => 1,
                    ],
                    'Ноутбуки и планшеты' => [
                        'types'    => [['Ноутбук', 32000, 160000], ['Планшет', 9000, 70000], ['Электронная книга', 7000, 18000]],
                        'brands'   => ['Lenovo', 'ASUS', 'HP', 'Acer', 'Huawei', 'Apple', 'Xiaomi'],
                        'variants' => ['8/256 ГБ', '16/512 ГБ', '16/1024 ГБ', '4/64 ГБ', '8/128 ГБ'],
                        'attrs'    => fn ($t, $v) => [
                            'Память' => $v, 'Процессор' => $pick(['Intel Core i5', 'Intel Core i7', 'AMD Ryzen 5', 'AMD Ryzen 7', 'Apple M3', 'MediaTek Helio']),
                            'Экран' => $pick(['10,1"', '11"', '14"', '15,6"', '16"']), 'Цвет' => $pick($colors),
                            'Гарантия' => '12 месяцев',
                        ],
                        'size' => [38, 26, 3], 'max_weight' => 3,
                    ],
                    'Аудиотехника' => [
                        'types'    => [['Беспроводные наушники', 900, 25000], ['Наушники накладные', 1200, 18000], ['Портативная колонка', 1500, 22000], ['Умная колонка', 3500, 15000]],
                        'brands'   => ['JBL', 'Sony', 'Marshall', 'Xiaomi', 'Huawei', 'Edifier', 'Sennheiser'],
                        'variants' => ['Чёрные', 'Белые', 'Синие', 'Pro', 'Lite', 'Max'],
                        'attrs'    => fn ($t, $v) => [
                            'Подключение' => $pick(['Bluetooth 5.3', 'Bluetooth 5.0', 'Bluetooth + AUX']),
                            'Время работы' => random_int(6, 40) . ' ч', 'Шумоподавление' => $pick(['Есть', 'Нет']),
                            'Влагозащита' => $pick(['IPX4', 'IPX5', 'IP67', 'Нет']),
                        ],
                        'size' => [20, 15, 8], 'max_weight' => 2,
                    ],
                    'Аксессуары для техники' => [
                        'types'    => [['Зарядное устройство', 350, 3500], ['Кабель USB-C', 150, 1200], ['Чехол для смартфона', 200, 1800], ['Повербанк', 900, 5500], ['Защитное стекло', 150, 900], ['Карта памяти', 400, 3500]],
                        'brands'   => ['Baseus', 'Anker', 'Ugreen', 'Hoco', 'Xiaomi', 'Samsung'],
                        'variants' => ['1 м', '2 м', '20 Вт', '65 Вт', '10000 мА·ч', '20000 мА·ч', '64 ГБ', '128 ГБ', 'Чёрный', 'Прозрачный'],
                        'attrs'    => fn ($t, $v) => [
                            'Параметр' => $v, 'Совместимость' => $pick(['Универсальный', 'iPhone', 'Android', 'Samsung Galaxy']),
                            'Цвет' => $pick($colors),
                        ],
                        'size' => [15, 10, 3], 'max_weight' => 1,
                    ],
                ],
            ],
            'Бытовая техника' => [
                'color' => '#0f9d8a',
                'description' => 'Техника для кухни и дома.',
                'children' => [
                    'Техника для кухни' => [
                        'types'    => [['Электрочайник', 1200, 6500], ['Блендер', 1800, 9000], ['Мультиварка', 3500, 14000], ['Микроволновая печь', 5500, 18000], ['Кофеварка', 4000, 35000], ['Тостер', 1500, 5000]],
                        'brands'   => ['Philips', 'Tefal', 'Bosch', 'Redmond', 'Polaris', 'Scarlett', 'Vitek'],
                        'variants' => ['1,7 л', '2 л', '5 л', '20 л', '800 Вт', '1500 Вт', 'Белый', 'Стальной'],
                        'attrs'    => fn ($t, $v) => [
                            'Мощность' => random_int(5, 25) * 100 . ' Вт', 'Объём/параметр' => $v,
                            'Материал корпуса' => $pick(['Пластик', 'Нержавеющая сталь', 'Стекло']),
                            'Гарантия' => $pick(['12 месяцев', '24 месяца']),
                        ],
                        'size' => [40, 30, 30], 'max_weight' => 8,
                    ],
                    'Техника для дома' => [
                        'types'    => [['Пылесос', 4500, 45000], ['Утюг', 1500, 8000], ['Обогреватель', 2500, 12000], ['Вентилятор', 1500, 7000], ['Увлажнитель воздуха', 2000, 9000]],
                        'brands'   => ['Samsung', 'Philips', 'Xiaomi', 'Dyson', 'Tefal', 'Polaris', 'Ballu'],
                        'variants' => ['Белый', 'Серый', 'Pro', 'Compact', '2000 Вт', 'с паром'],
                        'attrs'    => fn ($t, $v) => [
                            'Мощность' => random_int(5, 25) * 100 . ' Вт', 'Управление' => $pick(['Механическое', 'Сенсорное', 'Пульт ДУ', 'Через приложение']),
                            'Уровень шума' => random_int(35, 75) . ' дБ', 'Гарантия' => '12 месяцев',
                        ],
                        'size' => [50, 35, 35], 'max_weight' => 10,
                    ],
                ],
            ],
            'Одежда' => [
                'color' => '#c2477a',
                'description' => 'Мужская, женская и детская одежда.',
                'children' => [
                    'Мужская одежда' => [
                        'types'    => [['Футболка мужская', 450, 2200], ['Джинсы мужские', 1500, 5500], ['Куртка мужская', 3500, 15000], ['Рубашка мужская', 900, 3500], ['Худи мужское', 1500, 4500]],
                        'brands'   => ['LC Waikiki', 'Defacto', 'Colin\'s', 'Levi\'s', 'Adidas', 'Nike', 'Zara'],
                        'variants' => ['S', 'M', 'L', 'XL', 'XXL'],
                        'attrs'    => fn ($t, $v) => [
                            'Размер' => $v, 'Цвет' => $pick($colors), 'Состав' => $pick(['100% хлопок', '95% хлопок, 5% эластан', '80% хлопок, 20% полиэстер', '100% полиэстер']),
                            'Сезон' => $pick(['Всесезон', 'Лето', 'Демисезон', 'Зима']), 'Страна' => $pick(['Турция', 'Кыргызстан', 'Китай', 'Узбекистан']),
                        ],
                        'size' => [35, 25, 5], 'max_weight' => 2,
                    ],
                    'Женская одежда' => [
                        'types'    => [['Платье', 1200, 6500], ['Блузка', 800, 3500], ['Юбка', 900, 3500], ['Кардиган', 1500, 5000], ['Пальто женское', 5000, 18000]],
                        'brands'   => ['Zara', 'Mango', 'LC Waikiki', 'Koton', 'Defacto', 'Bershka'],
                        'variants' => ['XS', 'S', 'M', 'L', 'XL'],
                        'attrs'    => fn ($t, $v) => [
                            'Размер' => $v, 'Цвет' => $pick($colors), 'Состав' => $pick(['100% вискоза', '100% хлопок', '70% шерсть, 30% полиэстер', '100% полиэстер']),
                            'Сезон' => $pick(['Всесезон', 'Лето', 'Демисезон', 'Зима']), 'Страна' => $pick(['Турция', 'Кыргызстан', 'Китай']),
                        ],
                        'size' => [35, 25, 5], 'max_weight' => 2,
                    ],
                    'Детская одежда' => [
                        'types'    => [['Комбинезон детский', 900, 4500], ['Футболка детская', 300, 1200], ['Пижама детская', 600, 1800], ['Куртка детская', 2000, 7000]],
                        'brands'   => ['Kiabi', 'LC Waikiki', 'Carter\'s', 'Acoola', 'Defacto'],
                        'variants' => ['86 см', '98 см', '110 см', '122 см', '134 см'],
                        'attrs'    => fn ($t, $v) => [
                            'Рост' => $v, 'Цвет' => $pick($colors), 'Состав' => $pick(['100% хлопок', '95% хлопок, 5% эластан']),
                            'Пол' => $pick(['Мальчик', 'Девочка', 'Унисекс']),
                        ],
                        'size' => [30, 20, 5], 'max_weight' => 1,
                    ],
                ],
            ],
            'Обувь' => [
                'color' => '#8a5a3c',
                'description' => 'Кроссовки, ботинки, туфли и сандалии.',
                'children' => [
                    'Обувь для всей семьи' => [
                        'types'    => [['Кроссовки', 1800, 14000], ['Ботинки', 3000, 12000], ['Туфли', 2000, 9000], ['Сандалии', 900, 4000], ['Домашние тапочки', 300, 1200]],
                        'brands'   => ['Nike', 'Adidas', 'Puma', 'New Balance', 'Ecco', 'Skechers', 'Reebok'],
                        'variants' => ['36', '37', '38', '39', '40', '41', '42', '43', '44'],
                        'attrs'    => fn ($t, $v) => [
                            'Размер' => $v, 'Цвет' => $pick($colors), 'Материал верха' => $pick(['Натуральная кожа', 'Текстиль', 'Экокожа', 'Замша']),
                            'Подошва' => $pick(['Резина', 'ЭВА', 'Полиуретан']), 'Сезон' => $pick(['Лето', 'Демисезон', 'Зима', 'Всесезон']),
                        ],
                        'size' => [33, 22, 13], 'max_weight' => 2,
                    ],
                ],
            ],
            'Дом и сад' => [
                'color' => '#5f8f2f',
                'description' => 'Посуда, текстиль, инструменты и всё для дома.',
                'children' => [
                    'Посуда' => [
                        'types'    => [['Сковорода', 900, 6000], ['Кастрюля', 900, 5500], ['Набор тарелок', 1200, 6000], ['Кружка', 150, 900], ['Набор ножей', 1200, 9000], ['Термос', 700, 3500]],
                        'brands'   => ['Tefal', 'Fissman', 'Luminarc', 'Rondell', 'Bergner', 'Kukmara'],
                        'variants' => ['24 см', '26 см', '28 см', '3 л', '5 л', '6 предметов', '18 предметов', '350 мл', '1 л'],
                        'attrs'    => fn ($t, $v) => [
                            'Размер/объём' => $v, 'Материал' => $pick(['Алюминий', 'Нержавеющая сталь', 'Керамика', 'Стекло', 'Чугун']),
                            'Антипригарное покрытие' => $pick(['Есть', 'Нет']), 'Посудомоечная машина' => $pick(['Можно', 'Нельзя']),
                        ],
                        'size' => [35, 30, 15], 'max_weight' => 4, 'package' => ['коробка', 6],
                    ],
                    'Текстиль для дома' => [
                        'types'    => [['Комплект постельного белья', 1500, 7000], ['Полотенце', 250, 1500], ['Плед', 900, 4500], ['Подушка', 600, 3000], ['Одеяло', 1500, 6000]],
                        'brands'   => ['Tac', 'Ecosse', 'Cleanelly', 'Arya', 'Togas'],
                        'variants' => ['1,5-спальный', '2-спальный', 'Евро', '50×70', '70×140', '150×200'],
                        'attrs'    => fn ($t, $v) => [
                            'Размер' => $v, 'Материал' => $pick(['Сатин', 'Бязь', 'Махра', 'Микрофибра', 'Хлопок']),
                            'Цвет' => $pick($colors), 'Страна' => $pick(['Турция', 'Россия', 'Узбекистан']),
                        ],
                        'size' => [45, 35, 12], 'max_weight' => 3,
                    ],
                    'Инструменты' => [
                        'types'    => [['Дрель-шуруповёрт', 2500, 16000], ['Набор отвёрток', 400, 2500], ['Молоток', 300, 1500], ['Рулетка', 200, 900], ['Болгарка', 3000, 12000], ['Набор ключей', 1200, 7000]],
                        'brands'   => ['Bosch', 'Makita', 'DeWalt', 'Интерскол', 'Stanley', 'Зубр'],
                        'variants' => ['12 В', '18 В', '5 м', '8 м', '24 предмета', '82 предмета', 'Профи'],
                        'attrs'    => fn ($t, $v) => [
                            'Параметр' => $v, 'Питание' => $pick(['Аккумулятор', 'Сеть 220 В', 'Без питания']),
                            'Комплектация' => $pick(['Кейс', 'Коробка', 'Без кейса']), 'Гарантия' => $pick(['6 месяцев', '12 месяцев', '24 месяца']),
                        ],
                        'size' => [40, 30, 12], 'max_weight' => 4,
                    ],
                ],
            ],
            'Спорт и отдых' => [
                'color' => '#e07b1a',
                'description' => 'Фитнес, туризм и активный отдых.',
                'children' => [
                    'Фитнес' => [
                        'types'    => [['Гантели', 600, 6000], ['Коврик для йоги', 500, 2500], ['Скакалка', 150, 900], ['Фитнес-резинки', 300, 1500], ['Мяч футбольный', 700, 4500]],
                        'brands'   => ['Torres', 'Demix', 'Adidas', 'Nike', 'Starfit', 'Larsen'],
                        'variants' => ['2 кг', '5 кг', '10 кг', '6 мм', '10 мм', 'Размер 5', 'Набор 5 шт'],
                        'attrs'    => fn ($t, $v) => [
                            'Параметр' => $v, 'Материал' => $pick(['TPE', 'Неопрен', 'Резина', 'Чугун', 'ПВХ']), 'Цвет' => $pick($colors),
                        ],
                        'size' => [60, 20, 15], 'max_weight' => 10,
                    ],
                    'Туризм' => [
                        'types'    => [['Палатка', 3500, 25000], ['Спальный мешок', 1800, 9000], ['Рюкзак туристический', 1800, 12000], ['Термокружка', 500, 2000], ['Фонарик налобный', 400, 2500]],
                        'brands'   => ['Tramp', 'Outventure', 'Nova Tour', 'Quechua', 'Jack Wolfskin'],
                        'variants' => ['2-местная', '3-местная', '4-местная', '40 л', '60 л', '-5 °C', '+5 °C'],
                        'attrs'    => fn ($t, $v) => [
                            'Параметр' => $v, 'Материал' => $pick(['Полиэстер 190T', 'Нейлон', 'Оксфорд 600D']),
                            'Водостойкость' => random_int(15, 50) * 100 . ' мм', 'Цвет' => $pick($colors),
                        ],
                        'size' => [60, 25, 25], 'max_weight' => 6,
                    ],
                ],
            ],
            'Красота и здоровье' => [
                'color' => '#a35bd1',
                'description' => 'Уход за лицом, телом и волосами.',
                'children' => [
                    'Уход за кожей' => [
                        'types'    => [['Крем для лица', 350, 4500], ['Сыворотка для лица', 600, 4000], ['Гель для умывания', 300, 1800], ['Солнцезащитный крем', 500, 2500], ['Маска тканевая', 60, 250]],
                        'brands'   => ['Nivea', 'La Roche-Posay', 'Garnier', 'CeraVe', 'Librederm', 'Mizon'],
                        'variants' => ['30 мл', '50 мл', '150 мл', '200 мл', 'SPF 50', 'Для сухой кожи'],
                        'attrs'    => fn ($t, $v) => [
                            'Объём' => $v, 'Тип кожи' => $pick(['Все типы', 'Сухая', 'Жирная', 'Комбинированная', 'Чувствительная']),
                            'Страна' => $pick(['Франция', 'Корея', 'Германия', 'Россия']), 'Срок годности' => '24 месяца',
                        ],
                        'size' => [12, 6, 6], 'max_weight' => 1, 'package' => ['блок', 10],
                    ],
                    'Уход за волосами' => [
                        'types'    => [['Шампунь', 250, 1800], ['Бальзам для волос', 250, 1500], ['Маска для волос', 400, 2000], ['Фен', 1500, 9000], ['Масло для волос', 400, 2200]],
                        'brands'   => ['L\'Oréal', 'Pantene', 'Head & Shoulders', 'Schwarzkopf', 'Philips', 'Kerastase'],
                        'variants' => ['250 мл', '400 мл', '600 мл', 'Для окрашенных', 'Против перхоти', '2000 Вт'],
                        'attrs'    => fn ($t, $v) => [
                            'Объём/параметр' => $v, 'Тип волос' => $pick(['Все типы', 'Сухие', 'Жирные', 'Окрашенные']),
                            'Страна' => $pick(['Франция', 'Германия', 'Россия', 'Польша']),
                        ],
                        'size' => [20, 8, 6], 'max_weight' => 1, 'package' => ['блок', 12],
                    ],
                ],
            ],
            'Продукты' => [
                'color' => '#d4a017',
                'description' => 'Бакалея, напитки, фрукты и овощи.',
                'children' => [
                    'Бакалея' => [
                        'types'    => [['Рис', 90, 450], ['Гречка', 80, 250], ['Макароны', 50, 250], ['Мука', 60, 400], ['Сахар', 70, 350], ['Масло подсолнечное', 150, 450], ['Чай чёрный', 120, 900]],
                        'brands'   => ['Кара-Балта', 'Макфа', 'Barilla', 'Ахмад', 'Altyn Dan', 'Мистраль'],
                        'variants' => ['400 г', '500 г', '900 г', '1 кг', '2 кг', '1 л', '100 пакетиков'],
                        'attrs'    => fn ($t, $v) => [
                            'Вес/объём' => $v, 'Срок годности' => random_int(6, 24) . ' месяцев',
                            'Условия хранения' => 'В сухом прохладном месте', 'Страна' => $pick(['Кыргызстан', 'Казахстан', 'Россия', 'Италия']),
                        ],
                        'size' => [25, 15, 8], 'max_weight' => 2, 'package' => ['коробка', 12],
                    ],
                    'Напитки' => [
                        'types'    => [['Вода минеральная', 30, 120], ['Сок', 90, 280], ['Газированный напиток', 50, 180], ['Кофе молотый', 350, 1800], ['Энергетик', 90, 200]],
                        'brands'   => ['Арашан', 'Coca-Cola', 'Pepsi', 'Да-Да', 'Jacobs', 'Lavazza', 'Adrenaline'],
                        'variants' => ['0,33 л', '0,5 л', '1 л', '1,5 л', '2 л', '250 г'],
                        'attrs'    => fn ($t, $v) => [
                            'Объём/вес' => $v, 'Тара' => $pick(['ПЭТ', 'Стекло', 'Жестяная банка', 'Тетрапак', 'Мягкая упаковка']),
                            'Срок годности' => random_int(6, 18) . ' месяцев',
                        ],
                        'size' => [25, 8, 8], 'max_weight' => 2, 'package' => ['упаковка', 6],
                    ],
                    'Фрукты и овощи' => [
                        'types'    => [['Яблоки', 80, 220], ['Бананы', 120, 200], ['Помидоры', 60, 250], ['Огурцы', 50, 200], ['Картофель', 25, 60], ['Апельсины', 120, 250], ['Виноград', 150, 400]],
                        'brands'   => ['Иссык-Куль', 'Чуй', 'Ош', 'Фермерские', 'Импорт'],
                        'variants' => ['', 'Отборные', 'Сезонные', 'Премиум'],
                        'attrs'    => fn ($t, $v) => [
                            'Происхождение' => $pick(['Кыргызстан', 'Узбекистан', 'Эквадор', 'Турция', 'Казахстан']),
                            'Хранение' => 'При +2…+8 °C', 'Продаётся' => 'На развес',
                        ],
                        'weighted' => true,
                    ],
                ],
            ],
            'Детские товары' => [
                'color' => '#e8505b',
                'description' => 'Игрушки и товары для малышей.',
                'children' => [
                    'Игрушки' => [
                        'types'    => [['Конструктор', 600, 9000], ['Мягкая игрушка', 300, 2500], ['Кукла', 500, 4500], ['Машинка на радиоуправлении', 1200, 7000], ['Настольная игра', 500, 3500], ['Пазл', 250, 1500]],
                        'brands'   => ['LEGO', 'Hasbro', 'Mattel', 'Мир пазлов', 'Funko', 'Hot Wheels'],
                        'variants' => ['3+', '6+', '8+', '500 деталей', '1000 деталей', '30 см', '50 см'],
                        'attrs'    => fn ($t, $v) => [
                            'Возраст/параметр' => $v, 'Материал' => $pick(['Пластик', 'Плюш', 'Картон', 'Дерево']),
                            'Пол' => $pick(['Мальчик', 'Девочка', 'Унисекс']), 'Страна' => $pick(['Китай', 'Дания', 'Россия']),
                        ],
                        'size' => [40, 30, 10], 'max_weight' => 2,
                    ],
                ],
            ],
            'Канцелярия' => [
                'color' => '#4a5a8c',
                'description' => 'Канцтовары для школы и офиса.',
                'children' => [
                    'Канцтовары' => [
                        'types'    => [['Тетрадь', 25, 180], ['Ручка шариковая', 15, 250], ['Набор карандашей', 80, 900], ['Бумага офисная', 450, 900], ['Рюкзак школьный', 1500, 6500], ['Маркер', 40, 250]],
                        'brands'   => ['Erich Krause', 'Brauberg', 'Faber-Castell', 'BIC', 'Svetocopy', 'Hatber'],
                        'variants' => ['12 листов', '48 листов', '96 листов', 'Синяя', '12 цветов', '24 цвета', 'А4 500 л.'],
                        'attrs'    => fn ($t, $v) => [
                            'Параметр' => $v, 'Формат' => $pick(['A4', 'A5', '—']), 'Цвет' => $pick($colors),
                        ],
                        'size' => [30, 21, 3], 'max_weight' => 3, 'package' => ['пачка', 10],
                    ],
                ],
            ],
            'Зоотовары' => [
                'color' => '#2f8fb0',
                'description' => 'Корма и аксессуары для питомцев.',
                'children' => [
                    'Товары для животных' => [
                        'types'    => [['Корм для кошек', 120, 4500], ['Корм для собак', 150, 6000], ['Наполнитель для лотка', 300, 1500], ['Игрушка для кошек', 100, 800], ['Лежанка', 900, 4500], ['Поводок', 300, 1800]],
                        'brands'   => ['Whiskas', 'Royal Canin', 'Pedigree', 'Purina', 'Felix', 'Trixie'],
                        'variants' => ['85 г', '400 г', '2 кг', '10 кг', '5 л', 'S', 'M', 'L'],
                        'attrs'    => fn ($t, $v) => [
                            'Вес/размер' => $v, 'Возраст животного' => $pick(['Котёнок/щенок', 'Взрослый', 'Пожилой', 'Любой']),
                            'Страна' => $pick(['Россия', 'Франция', 'Германия']),
                        ],
                        'size' => [40, 30, 12], 'max_weight' => 10, 'package' => ['коробка', 24],
                    ],
                ],
            ],
        ];
    }
}
