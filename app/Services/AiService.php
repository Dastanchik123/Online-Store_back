<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiService
{
    protected $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.key');
    }

    /**
     * Распознаёт товар(ы) на фото покупателя через Gemini Vision и возвращает
     * структурированные данные для последующего поиска по каталогу.
     *
     * @return array{items: array} — при ошибке/отсутствии ключа items пустой
     */
    public function recognizeProductPhoto(string $imageBase64, string $mimeType): array
    {
        if (!$this->apiKey) {
            return ['items' => [], 'error' => 'GEMINI_API_KEY не настроен'];
        }

        $prompt = $this->buildRecognitionPrompt();

        try {
            // gemini-3.8-flash иногда отдаёт 503 "high demand" на короткие
            // запросы. Повторов немного: free tier ограничен 20 запросами/день
            // на модель — агрессивный retry на каждое фото клиента может
            // сжечь дневную квоту за пару распознаваний.
            $response = null;
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $response = Http::timeout(30)->post(
                    "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent?key={$this->apiKey}",
                    [
                        'contents' => [
                            [
                                'parts' => [
                                    ['text' => $prompt],
                                    ['inline_data' => [
                                        'mime_type' => $mimeType,
                                        'data'      => $imageBase64,
                                    ]],
                                ],
                            ],
                        ],
                        'generationConfig' => [
                            'responseMimeType' => 'application/json',
                        ],
                    ]
                );

                if ($response->successful() || $response->status() !== 503) {
                    break;
                }

                usleep(500_000);
            }

            if (!$response->successful()) {
                Log::error('Gemini Vision API error: ' . $response->status() . ' ' . $response->body());
                return ['items' => [], 'error' => 'Ошибка при обращении к ИИ: ' . $response->status()];
            }

            $text = $response->json('candidates.0.content.parts.0.text');
            $parsed = json_decode((string) $text, true);

            if (!is_array($parsed) || !isset($parsed['items']) || !is_array($parsed['items'])) {
                Log::error('Gemini Vision: не удалось распарсить JSON-ответ', ['raw' => $text]);
                return ['items' => [], 'error' => 'Не удалось распознать товар на фото'];
            }

            return $parsed;
        } catch (\Exception $e) {
            Log::error('Gemini Vision request failed: ' . $e->getMessage());
            return ['items' => [], 'error' => 'Ошибка связи с сервисом ИИ'];
        }
    }

    private function buildRecognitionPrompt(): string
    {
        $storeName = Setting::where('key', 'site_name')->value('value') ?: config('app.name', 'Магазин');

        $categoriesList = Category::where('is_active', true)
            ->orderBy('name')
            ->pluck('name')
            ->implode(', ');

        // В каталоге нет отдельной таблицы брендов — они лежат как значение
        // атрибута "Бренд" внутри Product.attributes (jsonb), поэтому собираем
        // список фактических брендов прямо из каталога, а не хардкодим его.
        $brandsList = Product::whereNotNull('attributes')
            ->get(['attributes'])
            ->map(fn ($p) => $p->attributes['Бренд'] ?? $p->attributes['Brand'] ?? $p->attributes['бренд'] ?? null)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->implode(', ');

        $attributesSchema = 'Цвет (string), Материал (string), Размер/Длина/Объём/Вес (string — '
            . 'с единицей измерения как на упаковке), Модель/Артикул (string, если виден на корпусе или бирке)';

        $template = <<<'PROMPT'
Ты — система распознавания товаров для интернет-магазина "{{STORE_NAME}}".
Тебе дано фото товара, сделанное покупателем (камера телефона, возможен неидеальный ракурс, фон, освещение).

Твоя задача: определить, что изображено на фото, и вернуть структурированные данные
для поиска этого товара в каталоге магазина.

Доступные категории магазина (используй только эти, выбери наиболее подходящую,
если точного совпадения нет — выбери родительскую категорию):
{{CATEGORIES_LIST}}

Известные бренды в каталоге (если бренд на фото не входит в список — укажи его
отдельно в поле "brand_raw", не выбирай из списка насильно):
{{BRANDS_LIST}}

Атрибуты, которые важны для этой категории товара (подставляются в зависимости
от определённой категории, например для обуви: размер/цвет/материал,
для электроники: модель/объём памяти):
{{CATEGORY_ATTRIBUTES_SCHEMA}}

Правила:
1. Если на фото виден текст (логотип, название модели, штрихкод, артикул) — извлеки
   его точно, посимвольно, в поле "extracted_text". Это приоритетнее визуального
   предположения.
2. Если штрихкод читается — верни его в поле "barcode" в чистом виде (только цифры).
3. Если уверенности в точной модели/артикуле нет — заполни общие поля (category,
   color, material) и опусти точные (model, sku), не угадывай.
4. Верни также 3-5 коротких поисковых фраз на русском языке (поле "search_queries"),
   которые покупатель мог бы использовать, чтобы найти этот товар текстовым поиском
   в магазине — для fallback через полнотекстовый/триграммный поиск.
5. Если на фото несколько товаров — верни массив объектов, один на каждый
   распознанный товар, с полем "bbox_description" (где на фото находится).

Верни ТОЛЬКО валидный JSON без пояснений, в следующем формате:
{
  "items": [
    {
      "category": "string | null — одна из категорий выше",
      "brand": "string | null — бренд из списка выше",
      "brand_raw": "string | null — бренд, если не входит в список",
      "color": "string | null",
      "material": "string | null",
      "attributes": { "<динамический ключ из CATEGORY_ATTRIBUTES_SCHEMA>": "значение" },
      "extracted_text": "string | null",
      "barcode": "string | null",
      "confidence": 0.0,
      "search_queries": ["строка1", "строка2", "..."]
    }
  ]
}
PROMPT;

        return str_replace(
            ['{{STORE_NAME}}', '{{CATEGORIES_LIST}}', '{{BRANDS_LIST}}', '{{CATEGORY_ATTRIBUTES_SCHEMA}}'],
            [$storeName, $categoriesList, $brandsList ?: 'нет данных', $attributesSchema],
            $template
        );
    }

    public function generateDescription($productName, $categoryName = null)
    {
        if (!$this->apiKey) {
            return "Для генерации описания необходимо настроить GEMINI_API_KEY в файле .env";
        }

        try {
            $prompt = "Напиши привлекательное и продающее описание для товара: '{$productName}'" . 
                      ($categoryName ? " в категории '{$categoryName}'" : "") . 
                      ". \n\nТребования к оформлению:\n" .
                      "1. Раздели текст на логические абзацы (минимум 2-3).\n" .
                      "2. Используй список с буллитами (символ * или •) для ключевых преимуществ.\n" .
                      "3. Текст должен быть на русском языке.\n" .
                      "4. Не используй вступления вроде 'Вот описание:'.\n" .
                      "5. Используй двойные переносы строк между разделами для лучшей читаемости.\n" .
                      "6. НЕ ИСПОЛЬЗУЙ Markdown разметку (никаких двойных звездочек ** ). Текст должен быть чистым.";


            $response = Http::post("https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent?key={$this->apiKey}", [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ]
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? "Не удалось получить текст от ИИ.";
                
                // Очищаем текст от символов жирного шрифта Markdown
                return str_replace('**', '', $text);
            }

            Log::error("Gemini API Error Status: " . $response->status());
            Log::error("Gemini API Error Body: " . $response->body());
            
            return "Ошибка при обращении к ИИ: " . $response->status();

        } catch (\Exception $e) {
            Log::error("AI generation failed: " . $e->getMessage());
            return "Ошибка связи с сервисом ИИ.";
        }
    }
}
