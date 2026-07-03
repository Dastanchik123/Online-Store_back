<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class GenerateCatalog extends Command
{
    protected $signature = 'catalog:generate {--limit=1000} {--sleep=0}';
    protected $description = 'Generate realistic categories and products with matching Pexels photos';

    private string $pexelsKey;
    private int $created = 0;
    private int $limit;

    public function handle(): int
    {
        $this->pexelsKey = (string) env('PEXELS_API_KEY');
        if (empty($this->pexelsKey)) {
            $this->error('PEXELS_API_KEY is not set (add it to .env or fly secrets).');
            return 1;
        }

        $this->limit = (int) $this->option('limit');
        $sleep = (int) $this->option('sleep');

        foreach ($this->catalogDefinition() as $catDef) {
            if ($this->created >= $this->limit) {
                break;
            }

            $category = Category::firstOrCreate(
                ['slug' => Str::slug($catDef['name'])],
                ['name' => $catDef['name'], 'is_active' => true, 'sort_order' => 0]
            );

            $this->info("=== Категория: {$category->name} ===");

            foreach ($catDef['types'] as $type) {
                if ($this->created >= $this->limit) {
                    break;
                }

                $wanted = min($type['count'], $this->limit - $this->created);
                $photos = $this->fetchPhotos($type['keyword'], max($wanted, 5));

                if (empty($photos)) {
                    $this->warn("  Нет фото для '{$type['keyword']}', пропуск");
                    continue;
                }

                $variants = $this->generateVariants($type, $photos, $wanted);

                foreach ($variants as $variant) {
                    if ($this->created >= $this->limit) {
                        break;
                    }

                    $imagePath = $this->downloadAndStore($variant['photo_url']);
                    if (!$imagePath) {
                        $this->warn("  Не удалось скачать фото для {$variant['name']}, пропуск");
                        continue;
                    }

                    Product::create([
                        'name'              => $variant['name'],
                        'slug'              => $this->uniqueProductSlug($variant['name']),
                        'description'       => $variant['description'],
                        'short_description' => Str::limit($variant['description'], 150),
                        'sku'               => $this->uniqueSku(),
                        'purchase_price'    => $variant['purchase_price'],
                        'price'             => $variant['price'],
                        'stock_quantity'    => $variant['stock'],
                        'in_stock'          => true,
                        'is_active'         => true,
                        'image'             => $imagePath,
                        'images'            => [],
                        'category_id'       => $category->id,
                        'weight'            => $variant['weight'],
                        'dimensions'        => $variant['dimensions'],
                        'attributes'        => $variant['attributes'],
                    ]);

                    $this->created++;
                    $this->line("  [{$this->created}] {$variant['name']} — {$variant['price']} сом");

                    if ($sleep > 0) {
                        usleep($sleep * 1000);
                    }
                }
            }
        }

        $this->info("Готово. Создано товаров: {$this->created}");
        return 0;
    }

    private function fetchPhotos(string $keyword, int $need): array
    {
        $perPage = min(max($need, 15), 80);
        $response = Http::withHeaders(['Authorization' => $this->pexelsKey])
            ->timeout(20)
            ->get('https://api.pexels.com/v1/search', [
                'query'    => $keyword,
                'per_page' => $perPage,
                'orientation' => 'square',
            ]);

        if (!$response->ok()) {
            return [];
        }

        $photos = $response->json('photos') ?? [];

        return array_map(fn($p) => $p['src']['medium'] ?? $p['src']['original'], $photos);
    }

    private function downloadAndStore(string $url): ?string
    {
        try {
            $response = Http::timeout(20)->get($url);
            if (!$response->ok()) {
                return null;
            }

            $filename = 'image_' . time() . '_' . Str::random(6) . '.webp';
            $path     = 'products/' . $filename;

            $image = Image::make($response->body())->fit(800, 800)->encode('webp', 85);
            Storage::disk('public')->put($path, (string) $image);

            return $path;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function uniqueSku(): string
    {
        do {
            $sku = (string) random_int(1000000000, 9999999999);
        } while (Product::where('sku', $sku)->exists());

        return $sku;
    }

    private function uniqueProductSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 1;
        while (Product::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    /**
     * Build N realistic name/spec variants for a product type, cycling through
     * available photos so every variant gets a matching, non-repeating image
     * where possible.
     */
    private function generateVariants(array $type, array $photos, int $count): array
    {
        $variants = [];
        $brands = $type['brands'] ?? ['ProBuild', 'MasterTool', 'StrongGrip', 'TechMaster', 'Kurulush', 'FixPro', 'IronWork', 'BuildMax'];
        $colors = $type['colors'] ?? ['красный', 'синий', 'жёлтый', 'чёрный', 'серый', 'зелёный'];

        for ($i = 0; $i < $count; $i++) {
            $brand = $brands[$i % count($brands)];
            $spec  = $type['specs'][$i % count($type['specs'])];
            $name  = str_replace(['{brand}', '{spec}'], [$brand, $spec], $type['name_template']);

            $material = $type['materials'][$i % count($type['materials'])] ?? 'металл';
            $color    = $colors[$i % count($colors)];

            $description = str_replace(
                ['{name}', '{brand}', '{spec}', '{material}', '{color}'],
                [$name, $brand, $spec, $material, $color],
                $type['description_template']
            );

            $priceMin = $type['price_range'][0];
            $priceMax = $type['price_range'][1];
            $price    = random_int($priceMin, $priceMax);

            $attributes = [
                'Бренд'        => $brand,
                'Материал'     => ucfirst($material),
                'Цвет'         => ucfirst($color),
                $type['spec_label'] => $spec,
            ];

            $variants[] = [
                'name'           => $name,
                'description'    => $description,
                'photo_url'      => $photos[$i % count($photos)],
                'price'          => $price,
                'purchase_price' => round($price * 0.6),
                'stock'          => random_int(5, 200),
                'weight'         => $type['weight_range'] ? random_int($type['weight_range'][0], $type['weight_range'][1]) / 1000 : null,
                'dimensions'     => $type['dimensions'] ?? null,
                'attributes'     => $attributes,
            ];
        }

        return $variants;
    }

    private function catalogDefinition(): array
    {
        return [
            [
                'name' => 'Ручной инструмент',
                'types' => [
                    [
                        'keyword' => 'hammer tool', 'count' => 29,
                        'name_template' => 'Молоток слесарный {brand} {spec}',
                        'description_template' => '{name} — надёжный молоток из {material} для бытовых и профессиональных работ. Эргономичная рукоятка снижает нагрузку на руку при длительном использовании.',
                        'specs' => ['300г', '400г', '500г', '600г', '800г', '1000г'],
                        'spec_label' => 'Вес головки',
                        'materials' => ['кованой стали', 'закалённой стали', 'углеродистой стали'],
                        'price_range' => [250, 900], 'weight_range' => [300, 1000], 'dimensions' => '30x10x5 cm',
                    ],
                    [
                        'keyword' => 'screwdriver set', 'count' => 29,
                        'name_template' => 'Отвёртка {spec} {brand}',
                        'description_template' => '{name} с магнитным наконечником и прорезиненной рукояткой из {material}. Подходит для точных монтажных работ.',
                        'specs' => ['крестовая PH1', 'крестовая PH2', 'плоская 4мм', 'плоская 6мм', 'шестигранная набор', 'аккумуляторная'],
                        'spec_label' => 'Тип',
                        'materials' => ['хром-ванадиевой стали', 'ударопрочного пластика'],
                        'price_range' => [120, 1500], 'weight_range' => [50, 400], 'dimensions' => '20x3x3 cm',
                    ],
                    [
                        'keyword' => 'wrench tool', 'count' => 29,
                        'name_template' => 'Гаечный ключ {spec} {brand}',
                        'description_template' => '{name}, изготовлен из {material}. Устойчив к коррозии, подходит для регулярной эксплуатации в мастерской и дома.',
                        'specs' => ['8-10мм', '10-12мм', '12-14мм', '14-17мм', 'разводной 250мм', 'разводной 300мм'],
                        'spec_label' => 'Размер',
                        'materials' => ['хромованадиевой стали', 'нержавеющей стали'],
                        'price_range' => [150, 1200], 'weight_range' => [100, 600], 'dimensions' => '25x5x2 cm',
                    ],
                    [
                        'keyword' => 'pliers tool', 'count' => 24,
                        'name_template' => 'Плоскогубцы {spec} {brand}',
                        'description_template' => '{name} с изолированными рукоятками из {material}. Удобный хват и точный зажим для монтажных и электротехнических работ.',
                        'specs' => ['160мм', '180мм', '200мм', 'длинногубцы 160мм'],
                        'spec_label' => 'Длина',
                        'materials' => ['двухкомпонентного пластика', 'прорезиненного материала'],
                        'price_range' => [180, 700], 'weight_range' => [150, 350], 'dimensions' => '20x6x2 cm',
                    ],
                    [
                        'keyword' => 'hand saw', 'count' => 24,
                        'name_template' => 'Ножовка по дереву {spec} {brand}',
                        'description_template' => '{name}, полотно из {material}, зубья с трёхсторонней заточкой для быстрого и чистого реза.',
                        'specs' => ['350мм', '400мм', '450мм', '500мм'],
                        'spec_label' => 'Длина полотна',
                        'materials' => ['закалённой углеродистой стали'],
                        'price_range' => [300, 900], 'weight_range' => [200, 500], 'dimensions' => '45x12x3 cm',
                    ],
                    [
                        'keyword' => 'measuring tape', 'count' => 24,
                        'name_template' => 'Рулетка измерительная {spec} {brand}',
                        'description_template' => '{name} с корпусом из {material} и автостопом ленты. Чёткая двухсторонняя разметка.',
                        'specs' => ['3м', '5м', '7.5м', '10м'],
                        'spec_label' => 'Длина ленты',
                        'materials' => ['ударопрочного ABS-пластика'],
                        'price_range' => [150, 600], 'weight_range' => [100, 400], 'dimensions' => '8x8x4 cm',
                    ],
                    [
                        'keyword' => 'level tool construction', 'count' => 19,
                        'name_template' => 'Уровень строительный {spec} {brand}',
                        'description_template' => '{name}, корпус из {material}, три ампулы для точного контроля горизонтали и вертикали.',
                        'specs' => ['300мм', '400мм', '600мм', '1000мм'],
                        'spec_label' => 'Длина',
                        'materials' => ['алюминиевого профиля'],
                        'price_range' => [250, 1100], 'weight_range' => [200, 900], 'dimensions' => '60x5x3 cm',
                    ],
                    [
                        'keyword' => 'utility knife cutter', 'count' => 19,
                        'name_template' => 'Нож канцелярский {spec} {brand}',
                        'description_template' => '{name} с выдвижным лезвием из {material} и фиксатором положения.',
                        'specs' => ['9мм', '18мм', '25мм'],
                        'spec_label' => 'Ширина лезвия',
                        'materials' => ['углеродистой стали', 'нержавеющей стали'],
                        'price_range' => [80, 350], 'weight_range' => [50, 150], 'dimensions' => '16x3x1 cm',
                    ],
                ],
            ],
            [
                'name' => 'Электроинструмент',
                'types' => [
                    [
                        'keyword' => 'power drill', 'count' => 34,
                        'name_template' => 'Дрель ударная {brand} {spec}',
                        'description_template' => '{name} мощностью {spec} с реверсом и регулировкой скорости. Корпус из {material} для долгого срока службы.',
                        'specs' => ['550Вт', '650Вт', '750Вт', '850Вт'],
                        'spec_label' => 'Мощность',
                        'materials' => ['ударопрочного пластика', 'прорезиненного пластика'],
                        'price_range' => [2500, 9000], 'weight_range' => [1500, 2800], 'dimensions' => '35x25x10 cm',
                    ],
                    [
                        'keyword' => 'angle grinder', 'count' => 29,
                        'name_template' => 'Болгарка (УШМ) {spec} {brand}',
                        'description_template' => '{name}, защитный кожух из {material}, система защиты от перегрузки двигателя.',
                        'specs' => ['125мм 750Вт', '125мм 900Вт', '150мм 1200Вт', '180мм 1500Вт'],
                        'spec_label' => 'Диаметр диска',
                        'materials' => ['алюминиевого сплава'],
                        'price_range' => [2800, 12000], 'weight_range' => [1800, 3500], 'dimensions' => '30x12x10 cm',
                    ],
                    [
                        'keyword' => 'circular saw', 'count' => 24,
                        'name_template' => 'Дисковая пила {spec} {brand}',
                        'description_template' => '{name} с лазерной разметкой реза и мощным двигателем. Корпус из {material}.',
                        'specs' => ['1200Вт 190мм', '1400Вт 210мм', '1600Вт 235мм'],
                        'spec_label' => 'Мощность/диаметр',
                        'materials' => ['ударопрочного пластика'],
                        'price_range' => [3500, 14000], 'weight_range' => [3000, 5500], 'dimensions' => '35x30x25 cm',
                    ],
                    [
                        'keyword' => 'jigsaw tool', 'count' => 24,
                        'name_template' => 'Электролобзик {spec} {brand}',
                        'description_template' => '{name} с маятниковым ходом пилки и плавной регулировкой скорости реза.',
                        'specs' => ['550Вт', '650Вт', '750Вт'],
                        'spec_label' => 'Мощность',
                        'materials' => ['ударопрочного пластика'],
                        'price_range' => [2200, 7500], 'weight_range' => [1800, 2600], 'dimensions' => '30x20x10 cm',
                    ],
                    [
                        'keyword' => 'screwdriver drill cordless', 'count' => 29,
                        'name_template' => 'Шуруповёрт аккумуляторный {spec} {brand}',
                        'description_template' => '{name} с литий-ионным аккумулятором и набором бит в комплекте. Два скоростных режима.',
                        'specs' => ['12В 2Ач', '18В 2Ач', '18В 4Ач', '20В 4Ач'],
                        'spec_label' => 'Напряжение/ёмкость',
                        'materials' => ['ударопрочного пластика'],
                        'price_range' => [3000, 11000], 'weight_range' => [1200, 2200], 'dimensions' => '25x20x8 cm',
                    ],
                    [
                        'keyword' => 'welding machine', 'count' => 19,
                        'name_template' => 'Сварочный аппарат инверторный {spec} {brand}',
                        'description_template' => '{name}, защита от перепадов напряжения, компактный корпус из {material}.',
                        'specs' => ['160А', '200А', '250А'],
                        'spec_label' => 'Ток сварки',
                        'materials' => ['металла с порошковым покрытием'],
                        'price_range' => [5500, 18000], 'weight_range' => [4000, 8000], 'dimensions' => '35x20x25 cm',
                    ],
                ],
            ],
            [
                'name' => 'Сантехника',
                'types' => [
                    [
                        'keyword' => 'pvc pipe plumbing', 'count' => 34,
                        'name_template' => 'Труба полипропиленовая {spec}',
                        'description_template' => '{name}, устойчива к высокому давлению и температуре, подходит для систем отопления и водоснабжения.',
                        'specs' => ['16мм', '20мм', '25мм', '32мм', '40мм', '50мм'],
                        'spec_label' => 'Диаметр',
                        'materials' => ['армированного полипропилена'],
                        'price_range' => [60, 450], 'weight_range' => [100, 900], 'dimensions' => '200x5x5 cm',
                        'brands' => ['Kurulush'],
                    ],
                    [
                        'keyword' => 'water faucet tap', 'count' => 29,
                        'name_template' => 'Смеситель для {spec} {brand}',
                        'description_template' => '{name}, корпус из {material}, керамический картридж обеспечивает плавную регулировку воды.',
                        'specs' => ['кухни', 'ванной', 'умывальника', 'душевой кабины'],
                        'spec_label' => 'Назначение',
                        'materials' => ['хромированной латуни'],
                        'price_range' => [900, 5500], 'weight_range' => [800, 2200], 'dimensions' => '25x15x30 cm',
                    ],
                    [
                        'keyword' => 'toilet bathroom', 'count' => 19,
                        'name_template' => 'Унитаз {spec} {brand}',
                        'description_template' => '{name} из санфаянса с системой антивсплеск, комплектуется сиденьем с микролифтом.',
                        'specs' => ['компакт напольный', 'подвесной', 'угловой'],
                        'spec_label' => 'Тип установки',
                        'materials' => ['санфаянса'],
                        'price_range' => [4500, 15000], 'weight_range' => [20000, 35000], 'dimensions' => '65x35x75 cm',
                    ],
                    [
                        'keyword' => 'shower head', 'count' => 19,
                        'name_template' => 'Душевая лейка {spec} {brand}',
                        'description_template' => '{name}, изготовлена из {material}, несколько режимов струи, антизасоряющиеся форсунки.',
                        'specs' => ['круглая 15см', 'круглая 20см', 'квадратная 25см', 'тропический дождь 30см'],
                        'spec_label' => 'Форма/размер',
                        'materials' => ['хромированного ABS-пластика'],
                        'price_range' => [400, 2800], 'weight_range' => [200, 700], 'dimensions' => '25x25x10 cm',
                    ],
                    [
                        'keyword' => 'pipe wrench plumbing tool', 'count' => 19,
                        'name_template' => 'Трубный ключ {spec} {brand}',
                        'description_template' => '{name} из {material}, для монтажа и демонтажа резьбовых труб и фитингов.',
                        'specs' => ['250мм', '350мм', '450мм'],
                        'spec_label' => 'Длина',
                        'materials' => ['кованой стали'],
                        'price_range' => [350, 1400], 'weight_range' => [500, 1500], 'dimensions' => '35x10x5 cm',
                    ],
                    [
                        'keyword' => 'water filter home', 'count' => 19,
                        'name_template' => 'Фильтр для воды {spec} {brand}',
                        'description_template' => '{name}, многоступенчатая система очистки, картридж из {material}.',
                        'specs' => ['проточный 3-ступенчатый', 'магистральный', 'под мойку'],
                        'spec_label' => 'Тип',
                        'materials' => ['активированного угля и полипропилена'],
                        'price_range' => [900, 4500], 'weight_range' => [1000, 3000], 'dimensions' => '40x15x15 cm',
                    ],
                ],
            ],
            [
                'name' => 'Электрика',
                'types' => [
                    [
                        'keyword' => 'electrical cable wire', 'count' => 34,
                        'name_template' => 'Кабель ВВГ {spec} {brand}',
                        'description_template' => '{name}, медные жилы, изоляция из {material}. Для стационарной прокладки в жилых и коммерческих помещениях.',
                        'specs' => ['2x1.5мм', '2x2.5мм', '3x1.5мм', '3x2.5мм', '3x4мм'],
                        'spec_label' => 'Сечение',
                        'materials' => ['ПВХ-пластиката'],
                        'price_range' => [30, 250], 'weight_range' => [50, 500], 'dimensions' => 'бухта 100м',
                        'brands' => ['Kurulush'],
                    ],
                    [
                        'keyword' => 'electrical socket outlet', 'count' => 24,
                        'name_template' => 'Розетка {spec} {brand}',
                        'description_template' => '{name} с заземлением, корпус из {material}, защитные шторки от детей.',
                        'specs' => ['одинарная', 'двойная', 'с крышкой IP44', 'накладная'],
                        'spec_label' => 'Тип',
                        'materials' => ['самозатухающего пластика'],
                        'price_range' => [120, 650], 'weight_range' => [50, 200], 'dimensions' => '8x8x5 cm',
                    ],
                    [
                        'keyword' => 'light switch electrical', 'count' => 19,
                        'name_template' => 'Выключатель {spec} {brand}',
                        'description_template' => '{name}, корпус из {material}, рассчитан на ток до 10А.',
                        'specs' => ['одноклавишный', 'двухклавишный', 'проходной'],
                        'spec_label' => 'Тип',
                        'materials' => ['ударопрочного пластика'],
                        'price_range' => [90, 450], 'weight_range' => [40, 150], 'dimensions' => '8x8x4 cm',
                    ],
                    [
                        'keyword' => 'led light bulb', 'count' => 24,
                        'name_template' => 'Лампа LED {spec} {brand}',
                        'description_template' => '{name}, цоколь E27, корпус из {material}, срок службы до 25000 часов.',
                        'specs' => ['7Вт', '9Вт', '12Вт', '15Вт'],
                        'spec_label' => 'Мощность',
                        'materials' => ['алюминия и поликарбоната'],
                        'price_range' => [60, 350], 'weight_range' => [30, 100], 'dimensions' => '6x6x11 cm',
                    ],
                    [
                        'keyword' => 'circuit breaker panel', 'count' => 19,
                        'name_template' => 'Автоматический выключатель {spec} {brand}',
                        'description_template' => '{name} для защиты цепи от перегрузки и короткого замыкания. Корпус из {material}.',
                        'specs' => ['16А', '25А', '32А', '40А'],
                        'spec_label' => 'Номинал',
                        'materials' => ['самозатухающего пластика'],
                        'price_range' => [180, 700], 'weight_range' => [80, 250], 'dimensions' => '4x9x7 cm',
                    ],
                    [
                        'keyword' => 'extension cord power strip', 'count' => 19,
                        'name_template' => 'Удлинитель сетевой {spec} {brand}',
                        'description_template' => '{name} с защитой от перегрузки, провод в изоляции из {material}.',
                        'specs' => ['на 3 розетки 3м', 'на 5 розеток 5м', 'на 6 розеток 10м'],
                        'spec_label' => 'Комплектация',
                        'materials' => ['ПВХ-пластиката'],
                        'price_range' => [250, 900], 'weight_range' => [300, 900], 'dimensions' => '20x10x5 cm',
                    ],
                ],
            ],
            [
                'name' => 'Крепёж',
                'types' => [
                    [
                        'keyword' => 'screws bolts hardware', 'count' => 34,
                        'name_template' => 'Саморезы {spec} {brand}',
                        'description_template' => '{name} из {material}, острый наконечник для быстрого монтажа без предварительного сверления.',
                        'specs' => ['3.5x25мм (уп. 200шт)', '4x40мм (уп. 150шт)', '4x60мм (уп. 100шт)', '5x80мм (уп. 50шт)'],
                        'spec_label' => 'Размер/упаковка',
                        'materials' => ['оцинкованной стали', 'закалённой стали'],
                        'price_range' => [80, 400], 'weight_range' => [200, 1000], 'dimensions' => 'уп.',
                        'brands' => ['Kurulush'],
                    ],
                    [
                        'keyword' => 'bolts nuts steel', 'count' => 29,
                        'name_template' => 'Болты с гайками {spec} {brand}',
                        'description_template' => '{name}, изготовлены из {material}, комплект с шайбами.',
                        'specs' => ['М8x40 (10шт)', 'М10x60 (10шт)', 'М12x80 (5шт)'],
                        'spec_label' => 'Размер',
                        'materials' => ['углеродистой стали'],
                        'price_range' => [120, 550], 'weight_range' => [200, 800], 'dimensions' => 'уп.',
                    ],
                    [
                        'keyword' => 'wall anchor plug', 'count' => 24,
                        'name_template' => 'Дюбель-гвоздь {spec} {brand}',
                        'description_template' => '{name} для крепления в бетон, кирпич и пеноблок. Корпус из {material}.',
                        'specs' => ['6x40мм (100шт)', '8x60мм (50шт)', '10x80мм (25шт)'],
                        'spec_label' => 'Размер/упаковка',
                        'materials' => ['ударопрочного нейлона'],
                        'price_range' => [90, 400], 'weight_range' => [150, 600], 'dimensions' => 'уп.',
                    ],
                    [
                        'keyword' => 'nails construction', 'count' => 19,
                        'name_template' => 'Гвозди строительные {spec} {brand}',
                        'description_template' => '{name}, изготовлены из {material}, для общестроительных и отделочных работ.',
                        'specs' => ['50мм (1кг)', '70мм (1кг)', '100мм (1кг)'],
                        'spec_label' => 'Размер',
                        'materials' => ['углеродистой стали'],
                        'price_range' => [100, 300], 'weight_range' => [1000, 1000], 'dimensions' => 'уп. 1кг',
                    ],
                ],
            ],
            [
                'name' => 'Малярные материалы',
                'types' => [
                    [
                        'keyword' => 'paint bucket wall', 'count' => 34,
                        'name_template' => 'Краска {spec} {brand}',
                        'description_template' => '{name} на водной основе, легко наносится, без резкого запаха. Расход экономичный.',
                        'specs' => ['фасадная 5л белая', 'фасадная 10л белая', 'интерьерная 5л', 'интерьерная 10л', 'для дерева 3л'],
                        'spec_label' => 'Тип/объём',
                        'materials' => ['акриловой основы'],
                        'price_range' => [450, 2500], 'weight_range' => [3000, 12000], 'dimensions' => 'банка',
                        'brands' => ['Kurulush'],
                    ],
                    [
                        'keyword' => 'paint roller brush', 'count' => 24,
                        'name_template' => 'Валик малярный {spec} {brand}',
                        'description_template' => '{name} с ворсом из {material}, подходит для водоэмульсионных и масляных красок.',
                        'specs' => ['180мм велюровый', '230мм велюровый', '100мм поролоновый'],
                        'spec_label' => 'Размер/тип',
                        'materials' => ['полиакрила', 'поролона'],
                        'price_range' => [70, 350], 'weight_range' => [80, 250], 'dimensions' => '25x8x8 cm',
                    ],
                    [
                        'keyword' => 'paint brush tool', 'count' => 19,
                        'name_template' => 'Кисть малярная {spec} {brand}',
                        'description_template' => '{name} с натуральной щетиной, деревянная ручка. Равномерно распределяет краску.',
                        'specs' => ['25мм', '50мм', '75мм', '100мм'],
                        'spec_label' => 'Ширина',
                        'materials' => ['смешанного ворса'],
                        'price_range' => [40, 200], 'weight_range' => [30, 120], 'dimensions' => '20x5x2 cm',
                    ],
                    [
                        'keyword' => 'wallpaper roll interior', 'count' => 19,
                        'name_template' => 'Обои {spec} {brand}',
                        'description_template' => '{name}, изготовлены из {material}, плотная текстура скрывает мелкие неровности стен.',
                        'specs' => ['флизелиновые однотонные', 'виниловые под покраску', 'бумажные с рисунком'],
                        'spec_label' => 'Тип',
                        'materials' => ['флизелина', 'винила'],
                        'price_range' => [600, 2200], 'weight_range' => [2000, 4000], 'dimensions' => 'рулон 10.05x1.06м',
                    ],
                ],
            ],
            [
                'name' => 'Строительные материалы',
                'types' => [
                    [
                        'keyword' => 'cement bag construction', 'count' => 24,
                        'name_template' => 'Цемент {spec} {brand}',
                        'description_template' => '{name}, марка прочности для общестроительных работ, фасовка в прочный мешок из {material}.',
                        'specs' => ['М400 25кг', 'М400 50кг', 'М500 50кг'],
                        'spec_label' => 'Марка/фасовка',
                        'materials' => ['крафт-бумаги'],
                        'price_range' => [250, 700], 'weight_range' => [25000, 50000], 'dimensions' => 'мешок',
                        'brands' => ['Kurulush'],
                    ],
                    [
                        'keyword' => 'ceramic tile floor', 'count' => 29,
                        'name_template' => 'Плитка керамическая {spec} {brand}',
                        'description_template' => '{name}, устойчива к истиранию и влаге, поверхность из {material}.',
                        'specs' => ['напольная 30x30см', 'настенная 25x40см', 'напольная 60x60см'],
                        'spec_label' => 'Тип/размер',
                        'materials' => ['глазурованной керамики'],
                        'price_range' => [400, 1800], 'weight_range' => [15000, 20000], 'dimensions' => 'уп. 1м²',
                    ],
                    [
                        'keyword' => 'plaster wall construction', 'count' => 19,
                        'name_template' => 'Штукатурка {spec} {brand}',
                        'description_template' => '{name} на основе {material}, для выравнивания стен и потолков внутри помещений.',
                        'specs' => ['гипсовая 25кг', 'цементная 25кг', 'фасадная 25кг'],
                        'spec_label' => 'Тип',
                        'materials' => ['гипсового вяжущего', 'цементного вяжущего'],
                        'price_range' => [280, 650], 'weight_range' => [25000, 25000], 'dimensions' => 'мешок',
                    ],
                    [
                        'keyword' => 'wood board lumber', 'count' => 19,
                        'name_template' => 'Доска обрезная {spec} {brand}',
                        'description_template' => '{name} из {material}, естественная влажность, подходит для строительных и отделочных работ.',
                        'specs' => ['25x100x3000мм', '40x150x6000мм', '50x200x6000мм'],
                        'spec_label' => 'Размер',
                        'materials' => ['хвойной древесины'],
                        'price_range' => [350, 1800], 'weight_range' => [5000, 20000], 'dimensions' => 'шт.',
                    ],
                ],
            ],
            [
                'name' => 'Измерительные приборы',
                'types' => [
                    [
                        'keyword' => 'laser level measuring', 'count' => 19,
                        'name_template' => 'Лазерный уровень {spec} {brand}',
                        'description_template' => '{name}, проецирует до {spec}, корпус из {material}, влаго- и пылезащищённый.',
                        'specs' => ['2 линий', '3 линий', '5 линий'],
                        'spec_label' => 'Количество линий',
                        'materials' => ['ударопрочного пластика'],
                        'price_range' => [1800, 7500], 'weight_range' => [400, 900], 'dimensions' => '12x10x8 cm',
                    ],
                    [
                        'keyword' => 'digital multimeter', 'count' => 19,
                        'name_template' => 'Мультиметр цифровой {spec} {brand}',
                        'description_template' => '{name} для измерения напряжения, тока и сопротивления. Корпус из {material}.',
                        'specs' => ['базовый', 'с автовыбором диапазона', 'профессиональный'],
                        'spec_label' => 'Класс',
                        'materials' => ['ударопрочного ABS-пластика'],
                        'price_range' => [600, 3500], 'weight_range' => [200, 450], 'dimensions' => '15x8x4 cm',
                    ],
                    [
                        'keyword' => 'thermometer weather', 'count' => 14,
                        'name_template' => 'Термометр {spec} {brand}',
                        'description_template' => '{name}, корпус из {material}, точность измерения ±1°C.',
                        'specs' => ['уличный', 'комнатный', 'инфракрасный бесконтактный'],
                        'spec_label' => 'Тип',
                        'materials' => ['пластика с УФ-защитой'],
                        'price_range' => [150, 2200], 'weight_range' => [50, 300], 'dimensions' => '10x5x2 cm',
                    ],
                ],
            ],
            [
                'name' => 'Средства защиты',
                'types' => [
                    [
                        'keyword' => 'safety gloves work', 'count' => 24,
                        'name_template' => 'Перчатки рабочие {spec} {brand}',
                        'description_template' => '{name} из {material}, защищают руки от порезов и истирания при работе с инструментом.',
                        'specs' => ['х/б с ПВХ-точкой', 'кожаные комбинированные', 'антипорезные'],
                        'spec_label' => 'Тип',
                        'materials' => ['хлопка с латексным покрытием', 'спилковой кожи'],
                        'price_range' => [60, 400], 'weight_range' => [50, 150], 'dimensions' => 'пара',
                    ],
                    [
                        'keyword' => 'safety goggles glasses', 'count' => 19,
                        'name_template' => 'Очки защитные {spec} {brand}',
                        'description_template' => '{name} с покрытием против запотевания, линзы из {material}.',
                        'specs' => ['прозрачные', 'затемнённые', 'с боковой защитой'],
                        'spec_label' => 'Тип линз',
                        'materials' => ['поликарбоната'],
                        'price_range' => [90, 500], 'weight_range' => [40, 120], 'dimensions' => '16x6x5 cm',
                    ],
                    [
                        'keyword' => 'safety helmet hard hat', 'count' => 19,
                        'name_template' => 'Каска защитная строительная {spec} {brand}',
                        'description_template' => '{name}, корпус из {material}, регулируемый внутренний обхват и амортизирующая система.',
                        'specs' => ['белая', 'жёлтая', 'оранжевая', 'красная'],
                        'spec_label' => 'Цвет',
                        'materials' => ['высокопрочного полиэтилена'],
                        'price_range' => [300, 900], 'weight_range' => [350, 500], 'dimensions' => '30x25x20 cm',
                    ],
                    [
                        'keyword' => 'respirator mask dust', 'count' => 19,
                        'name_template' => 'Респиратор защитный {spec} {brand}',
                        'description_template' => '{name} для защиты от пыли и мелких частиц, изготовлен из {material}.',
                        'specs' => ['FFP1', 'FFP2', 'FFP3'],
                        'spec_label' => 'Класс защиты',
                        'materials' => ['нетканого фильтрующего материала'],
                        'price_range' => [40, 250], 'weight_range' => [20, 60], 'dimensions' => 'шт.',
                    ],
                ],
            ],
            [
                'name' => 'Садовый инвентарь',
                'types' => [
                    [
                        'keyword' => 'garden shovel spade', 'count' => 24,
                        'name_template' => 'Лопата {spec} {brand}',
                        'description_template' => '{name} с черенком из {material}, усиленный штык для тяжёлого грунта.',
                        'specs' => ['штыковая', 'совковая', 'для снега'],
                        'spec_label' => 'Тип',
                        'materials' => ['закалённой стали', 'дерева'],
                        'price_range' => [350, 1200], 'weight_range' => [1000, 2000], 'dimensions' => '120x20x5 cm',
                    ],
                    [
                        'keyword' => 'garden rake tool', 'count' => 19,
                        'name_template' => 'Грабли садовые {spec} {brand}',
                        'description_template' => '{name}, изготовлены из {material}, удобны для уборки листвы и рыхления почвы.',
                        'specs' => ['веерные', 'прямые 14 зубьев', 'прямые 22 зуба'],
                        'spec_label' => 'Тип',
                        'materials' => ['оцинкованной стали'],
                        'price_range' => [250, 800], 'weight_range' => [500, 1200], 'dimensions' => '150x30x5 cm',
                    ],
                    [
                        'keyword' => 'garden hose water', 'count' => 19,
                        'name_template' => 'Шланг поливочный {spec} {brand}',
                        'description_template' => '{name} из {material}, устойчив к перегибам и УФ-излучению.',
                        'specs' => ['1/2" 15м', '1/2" 25м', '3/4" 30м'],
                        'spec_label' => 'Диаметр/длина',
                        'materials' => ['трёхслойного ПВХ'],
                        'price_range' => [400, 1800], 'weight_range' => [1500, 4000], 'dimensions' => 'бухта',
                    ],
                    [
                        'keyword' => 'wheelbarrow garden', 'count' => 14,
                        'name_template' => 'Тачка садовая {spec} {brand}',
                        'description_template' => '{name}, кузов из {material}, пневматическое колесо для лёгкого перемещения по участку.',
                        'specs' => ['на 1 колесо 80л', 'на 2 колеса 100л'],
                        'spec_label' => 'Конструкция/объём',
                        'materials' => ['оцинкованной стали'],
                        'price_range' => [2500, 6500], 'weight_range' => [10000, 18000], 'dimensions' => '140x60x60 cm',
                    ],
                ],
            ],
        ];
    }
}
