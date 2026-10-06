<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Services\AiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class ProductController extends Controller
{
    // Себестоимость (purchase_price/package_purchase_price) видна только
    // сотрудникам с доступом к товарам — этот роут публичный (без
    // auth:sanctum в middleware роута), поэтому проверяем токен вручную
    // через гвард, не полагаясь на request()->user().
    private function canSeeCostPrice(Request $request): bool
    {
        $user = $request->user('sanctum');
        return $user && ($user->role === 'admin' || $user->hasPermission('products.view') || $user->hasPermission('products.edit'));
    }

    public function index(Request $request)
    {
        $canSeeCost = $this->canSeeCostPrice($request);

        // Кэш ответа: повторные запросы с теми же параметрами отдаются из кэша мгновенно;
        // любое изменение каталога сбрасывает кэш через ApiCache::bump() (см. AppServiceProvider).
        // Ключ кэша учитывает видимость себестоимости — иначе кэш, прогретый
        // публичным гостем, мог бы отдать сотруднику ответ без purchase_price,
        // и наоборот, кэш от сотрудника — утечь себестоимость гостю.
        $payload = \App\Support\ApiCache::remember(
            'products',
            ($canSeeCost ? 'staff:' : 'public:') . ($request->getQueryString() ?? ''),
            300,
            fn () => $this->buildIndexPayload($request, $canSeeCost)
        );

        return response()->json($payload);
    }

    private function buildIndexPayload(Request $request, bool $canSeeCost = false): array
    {
        $hiddenCostFields = ['purchase_price', 'package_purchase_price'];
        $query = Product::query()->with('category');

        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('in_stock')) {
            $query->where('in_stock', $request->boolean('in_stock'));
        }

        if ($request->has('is_hot')) {
            $query->where('is_hot', $request->boolean('is_hot'));
        }

        if ($request->has('hot_group')) {
            $query->where('hot_group', $request->hot_group);
        }

        $isSearching = false;

        if ($request->filled('search') && $request->boolean('search_strict')) {
            // Строгий поиск (админ-список): только вхождение подстроки в
            // название или артикул без учёта регистра — никаких «похожих»
            // товаров по триграммам и поиска по описанию
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('sku', 'ilike', "%{$search}%");
            });
        } elseif ($request->filled('search')) {
            $search      = trim($request->search);
            $isSearching = true;

            $hasDirectMatch = (clone $query)->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('sku', 'ilike', "%{$search}%")
                    ->orWhere('description', 'ilike', "%{$search}%");
            })->exists();

            $relevance = 'GREATEST(similarity(name, ?), word_similarity(?, name))';

            if ($hasDirectMatch) {
                // Точные/подстрочные совпадения — им отдаём приоритет
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'ilike', "%{$search}%")
                        ->orWhere('sku', 'ilike', "%{$search}%")
                        ->orWhere('description', 'ilike', "%{$search}%");
                })->orderByRaw("{$relevance} desc", [$search, $search]);
            } else {
                // Прямых совпадений нет (например, опечатка) — тихо подставляем
                // ближайшие по написанию товары без отдельного "возможно, вы имели в виду".
                // Триграммы используем для отбора кандидатов, а финальный порядок —
                // по расстоянию Левенштейна до ближайшего слова в названии: оно
                // точнее отражает "похожесть по опечатке", чем чистое сходство триграмм.
                $wordDistance = '(SELECT MIN(levenshtein(lower(w), lower(?))) FROM unnest(string_to_array(name, \' \')) AS w)';

                $query->whereRaw("{$relevance} >= 0.25", [$search, $search])
                    ->orderByRaw("{$wordDistance} asc", [$search]);
            }
        }

        if ($request->has('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }

        if ($request->has('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        $allowedSortColumns = ['name', 'price', 'created_at', 'sales_count', 'stock_quantity'];
        $orderBy            = in_array($request->get('sort_by'), $allowedSortColumns)
            ? $request->get('sort_by')
            : 'name';
        $orderDir = strtolower((string) $request->get('sort_order')) === 'desc' ? 'desc' : 'asc';

        if ($request->boolean('is_hot')) {
            $orderBy  = 'hot_order';
            $orderDir = 'asc';
        }

        $perPage = $request->get('per_page', 15);

        // fields=list — минимальный набор для табличных списков (админ-каталог):
        // только колонки, видимые в таблице, категория лишь id+name, без appends
        $light = $request->get('fields') === 'list';
        if ($light) {
            $query->select([
                'id', 'uuid', 'category_id', 'name', 'sku',
                'price', 'sale_price', 'purchase_price', 'stock_quantity', 'unit', 'is_active',
                'package_unit', 'package_size', 'package_price', 'package_purchase_price',
            ])->with('category:id,name');
        }

        if ($perPage == -1) {
            if (! $light) {
                // Полный каталог: отдаём только нужные списку колонки —
                // description и прочие длинные поля раздували ответ до мегабайт
                $query->select([
                    'id', 'uuid', 'category_id', 'name', 'slug', 'sku',
                    'price', 'sale_price', 'purchase_price', 'stock_quantity', 'unit',
                    'package_unit', 'package_size', 'package_price', 'package_purchase_price',
                    'is_active', 'in_stock', 'is_hot', 'hot_order', 'hot_group',
                    'sales_count', 'image', 'images', 'created_at', 'updated_at',
                ]);
            }

            $products = $query->orderBy($orderBy, $orderDir)->get();

            if ($light) {
                $products->makeHidden(['image_url', 'images_urls']);
            }
            if (! $canSeeCost) {
                $products->makeHidden($hiddenCostFields);
            }

            return [
                'data'         => $products->toArray(),
                'total'        => $products->count(),
                'current_page' => 1,
                'last_page'    => 1,
                'per_page'     => $products->count(),
            ];
        }

        $products = $query->orderBy($orderBy, $orderDir)->paginate($perPage);

        if ($light) {
            $products->getCollection()->makeHidden(['image_url', 'images_urls']);
        }
        if (! $canSeeCost) {
            $products->getCollection()->makeHidden($hiddenCostFields);
        }

        return $products->toArray();
    }
    public function store(StoreProductRequest $request)
    {
        $validated = $request->validated();

        if (empty($validated['slug'])) {
            $baseSlug = Str::slug($validated['name']);
            $slug     = $baseSlug;
            $counter  = 1;
            while (Product::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }
            $validated['slug'] = $slug;
        }

        if ($request->hasFile('image')) {
            $file     = $request->file('image');
            $filename = 'image_' . time() . '_' . Str::random(6) . '.webp';
            $path     = 'products/' . $filename;

            $image = Image::make($file)->encode('webp', 90);
            Storage::disk('public')->put($path, (string) $image);

            $validated['image'] = $path;
        }

        if ($request->has('gallery_order')) {
            $galleryPaths = [];
            $newFiles = $request->file('gallery_files', []);
            foreach ($request->input('gallery_order') as $item) {
                if (str_starts_with($item, 'existing:')) {
                    $galleryPaths[] = substr($item, 9);
                } elseif (str_starts_with($item, 'new:')) {
                    $idx = (int) substr($item, 4);
                    if (isset($newFiles[$idx])) {
                        $file = $newFiles[$idx];
                        $filename = 'image_' . time() . '_' . Str::random(6) . '_' . $idx . '.webp';
                        $path = 'products/gallery/' . $filename;
                        $image = Image::make($file)->encode('webp', 90);
                        Storage::disk('public')->put($path, (string) $image);
                        $galleryPaths[] = $path;
                    }
                }
            }
            $validated['images'] = $galleryPaths;
        } elseif ($request->hasFile('images')) {
            $galleryPaths = [];
            foreach ($request->file('images') as $key => $file) {
                $filename = 'image_' . time() . '_' . Str::random(6) . '_' . ($key + 1) . '.webp';
                $path     = 'products/gallery/' . $filename;
                $image = Image::make($file)->encode('webp', 90);
                Storage::disk('public')->put($path, (string) $image);
                $galleryPaths[] = $path;
            }
            $validated['images'] = $galleryPaths;
        }

        $product = Product::create($validated);
        return response()->json($product->load('category'), 201);
    }

    public function show(Request $request, Product $product)
    {
        $product->increment('views_count');
        $product->load('category', 'reviews.user');
        if (! $this->canSeeCostPrice($request)) {
            $product->makeHidden(['purchase_price', 'package_purchase_price']);
        }
        return response()->json($product);
    }

    public function update(UpdateProductRequest $request, Product $product)
    {
        $validated = $request->validated();

        if (isset($validated['name']) && empty($validated['slug'])) {
            $baseSlug = Str::slug($validated['name']);
            $slug     = $baseSlug;
            $counter  = 1;
            while (Product::where('slug', $slug)->where('id', '!=', $product->id)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }
            $validated['slug'] = $slug;
        }

        if ($request->hasFile('image')) {
            $file     = $request->file('image');
            $filename = 'image_' . time() . '_' . Str::random(6) . '.webp';
            $path     = 'products/' . $filename;

            $image = Image::make($file)->encode('webp', 90);
            Storage::disk('public')->put($path, (string) $image);

            $validated['image'] = $path;
        }

        if ($request->has('gallery_order')) {
            $galleryPaths = [];
            $newFiles = $request->file('gallery_files', []);
            foreach ($request->input('gallery_order') as $item) {
                if (str_starts_with($item, 'existing:')) {
                    $galleryPaths[] = substr($item, 9);
                } elseif (str_starts_with($item, 'new:')) {
                    $idx = (int) substr($item, 4);
                    if (isset($newFiles[$idx])) {
                        $file = $newFiles[$idx];
                        $filename = 'image_' . time() . '_' . Str::random(6) . '_' . $idx . '.webp';
                        $path = 'products/gallery/' . $filename;
                        $image = Image::make($file)->encode('webp', 90);
                        Storage::disk('public')->put($path, (string) $image);
                        $galleryPaths[] = $path;
                    }
                }
            }
            $validated['images'] = $galleryPaths;
        } elseif ($request->has('clear_gallery') && $request->boolean('clear_gallery')) {
            $validated['images'] = null;
        } elseif ($request->hasFile('images')) {
            $galleryPaths = [];
            foreach ($request->file('images') as $key => $file) {
                $filename = 'image_' . time() . '_' . Str::random(6) . '_' . ($key + 1) . '.webp';
                $path     = 'products/gallery/' . $filename;
                $image = Image::make($file)->encode('webp', 90);
                Storage::disk('public')->put($path, (string) $image);
                $galleryPaths[] = $path;
            }
            $validated['images'] = $galleryPaths;
        }

        $product->update($validated);

        return response()->json($product->load('category'));
    }

    public function destroy(Product $product)
    {
        try {

            if ($product->orderItems()->exists()) {
                return response()->json([
                    'message' => 'Нельзя удалить товар, по которому уже были заказы. Рекомендуется архивировать товар (сделать неактивным).',
                ], 409);
            }

            $product->cartItems()->delete();
            $product->reviews()->delete();

            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }
            if (! empty($product->images)) {
                foreach ($product->images as $path) {
                    Storage::disk('public')->delete($path);
                }
            }

            $product->delete();
            return response()->json(['message' => 'Товар успешно удален'], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка при удалении товара',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function generateSku(Request $request)
    {
        $prefix     = '2';
        $bodyLength = 11;

        $dbMax = Product::where('sku', 'like', $prefix.'%')
            ->pluck('sku')
            ->filter(fn ($sku) => preg_match('/^2\d{12}$/', $sku))
            ->map(fn ($sku) => (int) substr($sku, 1, $bodyLength))
            ->max() ?? 0;

        // "current" — SKU, уже показанный в форме (ещё не сохранён). Без него
        // повторный клик на "Сгенерировать" до сохранения товара всегда
        // возвращал бы одно и то же значение — счётчик двигает вперёд только
        // сохранение товара в БД, а не сам факт генерации.
        $current     = (string) $request->query('current', '');
        $currentBody = 0;
        if (preg_match('/^2(\d{11})\d$/', $current, $m)) {
            $currentBody = (int) $m[1];
        }

        $next = max($dbMax, $currentBody) + 1;

        do {
            $body = str_pad($next, $bodyLength, '0', STR_PAD_LEFT);
            $sku  = $prefix.$body.\App\Support\Ean13::checkDigit($prefix.$body);
            $exists = Product::where('sku', $sku)->exists();
            $next++;
        } while ($exists);

        return response()->json(['sku' => $sku]);
    }

    public function generateWeightedBarcode(Request $request, Product $product, \App\Services\WeightedBarcodeService $weightedBarcodeService)
    {
        $request->validate([
            'weight_kg' => 'required|numeric|min:0.001',
        ]);

        if (! $product->is_weighted) {
            return response()->json(['message' => "Товар '{$product->name}' не отмечен как весовой"], 422);
        }

        $weightKg = (float) $request->weight_kg;

        if ($product->min_weight !== null && $weightKg < (float) $product->min_weight) {
            return response()->json(['message' => "Вес меньше допустимого минимума ({$product->min_weight} {$product->unit})"], 422);
        }
        if ($product->max_weight !== null && $weightKg > (float) $product->max_weight) {
            return response()->json(['message' => "Вес превышает допустимый максимум ({$product->max_weight} {$product->unit})"], 422);
        }

        try {
            $code = $weightedBarcodeService->generate($product, $weightKg);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['code' => $code, 'weight_kg' => $weightKg]);
    }

    public function aiDescription(Request $request, AiService $aiService)
    {
        $request->validate([
            'name' => 'required|string',
            'category_id' => 'nullable|exists:categories,id'
        ]);

        $categoryName = null;
        if ($request->category_id) {
            $categoryName = Category::find($request->category_id)->name;
        }

        $description = $aiService->generateDescription($request->name, $categoryName);

        return response()->json(['description' => $description]);
    }

    public function recognizeByPhoto(Request $request, AiService $aiService)
    {
        $request->validate([
            'image' => 'required|image|max:8192',
        ]);

        $file = $request->file('image');
        $imageBase64 = base64_encode(file_get_contents($file->getRealPath()));
        $mimeType = $file->getMimeType();

        $result = $aiService->recognizeProductPhoto($imageBase64, $mimeType);

        $items = array_map(function ($item) {
            $item['matched_products'] = $this->matchProductsForRecognizedItem($item);
            return $item;
        }, $result['items'] ?? []);

        return response()->json([
            'items' => $items,
            'error' => $result['error'] ?? null,
        ]);
    }

    // Три уровня точности сверху вниз: штрихкод/sku — точное совпадение,
    // распознанный с упаковки текст — подстрочное, иначе сгенерированные ИИ
    // поисковые фразы прогоняются через триграммное сходство (та же логика,
    // что в buildIndexPayload для обычного текстового поиска).
    private function matchProductsForRecognizedItem(array $item, int $limit = 8)
    {
        $base = Product::query()->where('is_active', true);

        if (!empty($item['barcode'])) {
            $bySku = (clone $base)->where('sku', $item['barcode'])
                ->with('category:id,name')->limit($limit)->get();
            if ($bySku->isNotEmpty()) {
                return $bySku->values();
            }
        }

        if (!empty($item['extracted_text'])) {
            $text = trim($item['extracted_text']);
            $byText = (clone $base)->where(function ($q) use ($text) {
                $q->where('name', 'ilike', "%{$text}%")->orWhere('sku', 'ilike', "%{$text}%");
            })->with('category:id,name')->limit($limit)->get();
            if ($byText->isNotEmpty()) {
                return $byText->values();
            }
        }

        $queries = array_filter((array) ($item['search_queries'] ?? []));
        if (empty($queries)) {
            return collect();
        }

        $relevance = 'GREATEST(similarity(name, ?), word_similarity(?, name))';
        $ids = collect();
        foreach ($queries as $search) {
            $ids = $ids->merge(
                (clone $base)
                    ->whereRaw("{$relevance} >= 0.2", [$search, $search])
                    ->orderByRaw("{$relevance} desc", [$search, $search])
                    ->limit($limit)
                    ->pluck('id')
            );
        }

        $uniqueIds = $ids->unique()->take($limit)->values();
        if ($uniqueIds->isEmpty()) {
            return collect();
        }

        $products = Product::whereIn('id', $uniqueIds)->with('category:id,name')->get();
        return $uniqueIds->map(fn ($id) => $products->firstWhere('id', $id))->filter()->values();
    }
}

