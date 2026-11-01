<?php

namespace App\Services\ImageSearch;

use App\Models\Product;
use App\Models\ProductImageEmbedding;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

// Поиск товаров, похожих на фото покупателя, без платного ИИ.
// Сервис image-embedder превращает картинку в вектор (модель CLIP); векторы
// фото товаров лежат в product_image_embeddings, а сравнение — скалярное
// произведение нормированных векторов — делается здесь, в PHP: на тысячи
// фото это миллисекунды, и не нужен pgvector.
class ImageSearchService
{
    private const INDEX_CACHE_KEY = 'image-search:index';

    public function isEnabled(): bool
    {
        return (bool) config('services.embedder.url');
    }

    /**
     * Вектор картинки или null, если сервис недоступен / не смог прочитать фото.
     */
    public function embed(string $binary): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $url = rtrim(config('services.embedder.url'), '/') . '/embed';

        try {
            $response = Http::withHeaders(['X-Embedder-Secret' => (string) config('services.embedder.secret')])
                ->timeout(config('services.embedder.timeout', 60))
                // Машина сервиса засыпает без запросов. Пока она просыпается
                // и грузит модель (~20 с), запрос может оборваться на
                // подключении или получить от прокси Fly 502/503/504 — повторяем
                ->retry(3, 3000, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException
                    || ($e instanceof \Illuminate\Http\Client\RequestException
                        && in_array($e->response->status(), [502, 503, 504], true)))
                ->withBody($binary, 'application/octet-stream')
                ->post($url);
        } catch (\Throwable $e) {
            Log::warning('image-embedder недоступен: ' . $e->getMessage());
            return null;
        }

        $vector = $response->json('vector');
        if (! $response->successful() || ! is_array($vector) || empty($vector)) {
            Log::warning('image-embedder вернул ошибку: ' . $response->status() . ' ' . mb_substr($response->body(), 0, 300));
            return null;
        }

        return array_map('floatval', $vector);
    }

    public function model(): string
    {
        return (string) config('services.embedder.model');
    }

    /**
     * Пересчитывает отпечатки всех фото товара: новые фото добавляет,
     * удалённые из товара — убирает. Возвращает число проиндексированных фото.
     */
    public function indexProduct(Product $product, bool $force = false): int
    {
        $paths = array_values(array_unique(array_filter(array_merge(
            [$product->image],
            (array) ($product->images ?? [])
        ))));

        $removed = ProductImageEmbedding::where('product_id', $product->id)
            ->when($paths, fn ($q) => $q->whereNotIn('image_path', $paths))
            ->delete();

        $indexed = 0;
        foreach ($paths as $path) {
            $existing = ProductImageEmbedding::where('product_id', $product->id)
                ->where('image_path', $path)
                ->first();
            if ($existing && ! $force && $existing->model === $this->model()) {
                continue;
            }

            if (! Storage::disk('public')->exists($path)) {
                Log::info("Фото товара #{$product->id} не найдено на диске: {$path}");
                continue;
            }

            $vector = $this->embed(Storage::disk('public')->get($path));
            if (! $vector) {
                continue;
            }

            ProductImageEmbedding::updateOrCreate(
                ['product_id' => $product->id, 'image_path' => $path],
                ['model' => $this->model(), 'embedding' => $vector]
            );
            $indexed++;
        }

        if ($indexed > 0 || $removed > 0) {
            $this->forgetIndex();
        }

        return $indexed;
    }

    /**
     * Товары, похожие на фото, лучшие первыми: [product_id => сходство 0..1].
     */
    public function findSimilar(array $vector, int $limit = 8): array
    {
        $best = [];
        foreach ($this->index() as [$productId, $candidate]) {
            $score = 0.0;
            foreach ($candidate as $i => $value) {
                $score += $value * ($vector[$i] ?? 0);
            }
            // У товара может быть несколько фото — берём самое похожее
            if (! isset($best[$productId]) || $score > $best[$productId]) {
                $best[$productId] = $score;
            }
        }

        $min = (float) config('services.embedder.min_similarity', 0.6);
        $best = array_filter($best, fn ($score) => $score >= $min);
        arsort($best);

        return array_map(fn ($s) => round($s, 4), array_slice($best, 0, $limit, true));
    }

    /**
     * Активные товары, похожие на фото, в порядке сходства (поле similarity).
     */
    public function searchByPhoto(string $binary, int $limit = 8)
    {
        $vector = $this->embed($binary);
        if (! $vector) {
            return null;
        }

        // Берём с запасом: часть похожих может оказаться снятой с продажи
        $scores = $this->findSimilar($vector, $limit * 3);
        if (! $scores) {
            return collect();
        }

        $products = Product::whereIn('id', array_keys($scores))
            ->where('is_active', true)
            ->with('category:id,name')
            ->get()
            ->keyBy('id');

        return collect($scores)
            ->filter(fn ($score, $id) => $products->has($id))
            ->take($limit)
            ->map(fn ($score, $id) => $products[$id]->setAttribute('similarity', $score))
            ->values();
    }

    public function indexedCount(): int
    {
        return count($this->index());
    }

    public function forgetIndex(): void
    {
        Cache::forget(self::INDEX_CACHE_KEY);
    }

    /**
     * Все векторы текущей модели: [[product_id, vector], ...]. Кэшируется,
     * чтобы не тянуть их из базы на каждый поиск.
     */
    private function index(): array
    {
        return Cache::rememberForever(self::INDEX_CACHE_KEY, function () {
            return ProductImageEmbedding::where('model', $this->model())
                ->get(['product_id', 'embedding'])
                ->map(fn ($row) => [$row->product_id, $row->embedding])
                ->all();
        });
    }
}
