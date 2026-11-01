<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImageEmbedding;
use App\Services\ImageSearch\ImageSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Поиск товаров, похожих на фото покупателя. Сервис отпечатков подменён:
// по содержимому «картинки» отдаёт заранее заданный короткий вектор.
class ImageSearchTest extends TestCase
{
    use RefreshDatabase;

    // Содержимое файла → вектор, который «вернула бы» модель
    private array $vectors = [
        'drill-photo'  => [1.0, 0.0, 0.0],
        'hammer-photo' => [0.0, 1.0, 0.0],
        'paint-photo'  => [0.0, 0.0, 1.0],
        // Снимок покупателя: почти дрель, немного похоже на молоток
        'customer-drill' => [0.9, 0.43, 0.0],
    ];

    // Вектор для настоящей картинки, которую шлёт покупатель в эндпоинт
    private ?array $customerVector = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Cache::flush();
    }

    private function enableEmbedder(): void
    {
        config([
            'services.embedder.url'            => 'http://embedder.test',
            'services.embedder.secret'         => 'test-secret',
            'services.embedder.model'          => 'test-model',
            'services.embedder.min_similarity' => 0.6,
            'services.gemini.key'              => null,
        ]);

        Http::fake([
            'embedder.test/*' => function (HttpRequest $request) {
                $vector = $this->vectors[$request->body()] ?? $this->customerVector;
                return $vector
                    ? Http::response(['model' => 'test-model', 'dim' => 3, 'vector' => $vector])
                    : Http::response(['error' => 'Не удалось прочитать картинку'], 422);
            },
            '*' => Http::response('unexpected', 500),
        ]);
    }

    private function productWithPhoto(string $name, string $photo, array $attrs = []): Product
    {
        $path = "products/{$photo}.webp";
        Storage::disk('public')->put($path, $photo);

        return Product::factory()->create(array_merge([
            'name'           => $name,
            'image'          => $path,
            'is_active'      => true,
            'purchase_price' => 100,
        ], $attrs));
    }

    public function test_index_command_stores_one_vector_per_photo()
    {
        $this->enableEmbedder();
        $drill = $this->productWithPhoto('Дрель', 'drill-photo', ['images' => ['products/hammer-photo.webp']]);
        Storage::disk('public')->put('products/hammer-photo.webp', 'hammer-photo');
        Product::factory()->create(['image' => null, 'images' => null]);

        $this->artisan('products:index-images')->assertExitCode(0);

        $this->assertSame(2, ProductImageEmbedding::where('product_id', $drill->id)->count());
        $this->assertEquals(
            [1.0, 0.0, 0.0],
            ProductImageEmbedding::where('image_path', 'products/drill-photo.webp')->value('embedding')
        );
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('X-Embedder-Secret', 'test-secret'));
    }

    public function test_reindex_skips_existing_and_drops_removed_photos()
    {
        $this->enableEmbedder();
        Storage::disk('public')->put('products/hammer-photo.webp', 'hammer-photo');
        $product = $this->productWithPhoto('Набор', 'drill-photo', ['images' => ['products/hammer-photo.webp']]);
        $search = app(ImageSearchService::class);

        $this->assertSame(2, $search->indexProduct($product));
        $this->assertSame(0, $search->indexProduct($product), 'повторно не пересчитываем');

        $product->update(['images' => []]);
        $search->indexProduct($product->fresh());

        $this->assertSame(
            ['products/drill-photo.webp'],
            ProductImageEmbedding::where('product_id', $product->id)->pluck('image_path')->all()
        );
    }

    public function test_finds_most_similar_products_in_order()
    {
        $this->enableEmbedder();
        $drill  = $this->productWithPhoto('Дрель', 'drill-photo');
        $hammer = $this->productWithPhoto('Молоток', 'hammer-photo');
        $this->productWithPhoto('Краска', 'paint-photo');
        $this->artisan('products:index-images');

        $vector = app(ImageSearchService::class)->embed('customer-drill');
        $scores = app(ImageSearchService::class)->findSimilar($vector);

        // Дрель 0.9 — да; молоток 0.43 и краска 0 — ниже порога 0.6
        $this->assertSame([$drill->id], array_keys($scores));
        $this->assertEqualsWithDelta(0.9, $scores[$drill->id], 0.0001);
        $this->assertArrayNotHasKey($hammer->id, $scores);
    }

    public function test_photo_search_endpoint_returns_similar_products_without_cost_price()
    {
        $this->enableEmbedder();
        $drill = $this->productWithPhoto('Дрель', 'drill-photo');
        $this->artisan('products:index-images');

        $this->customerVector = $this->vectors['customer-drill'];

        $response = $this->post('/api/products/recognize-photo', [
            'image' => UploadedFile::fake()->image('photo.jpg', 10, 10),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('similar_products.0.id', $drill->id)
            ->assertJsonPath('similar_products.0.similarity', 0.9)
            ->assertJsonPath('error', null);
        $this->assertArrayNotHasKey('purchase_price', $response->json('similar_products.0'));
    }

    public function test_inactive_products_are_not_suggested()
    {
        $this->enableEmbedder();
        $this->productWithPhoto('Дрель снята с продажи', 'drill-photo', ['is_active' => false]);
        $this->artisan('products:index-images');

        $result = app(ImageSearchService::class)->searchByPhoto('customer-drill');

        $this->assertCount(0, $result);
    }

    public function test_friendly_message_when_nothing_similar()
    {
        $this->enableEmbedder();
        $this->productWithPhoto('Краска', 'paint-photo');
        $this->artisan('products:index-images');

        $this->customerVector = [1.0, 0.0, 0.0];

        $this->post('/api/products/recognize-photo', [
            'image' => UploadedFile::fake()->image('photo.jpg', 10, 10),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonCount(0, 'similar_products')
            ->assertJsonPath('error', 'Похожих товаров не нашли. Сфотографируйте товар крупнее, на однотонном фоне, или найдите его по названию.');
    }

    public function test_service_down_and_no_gemini_shows_unavailable_without_technical_details()
    {
        config(['services.embedder.url' => 'http://embedder.test', 'services.gemini.key' => null]);
        Http::fake(['embedder.test/*' => Http::response('Bad Gateway', 502)]);

        $this->post('/api/products/recognize-photo', [
            'image' => UploadedFile::fake()->image('photo.jpg', 10, 10),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('error', 'Поиск по фото временно недоступен. Попробуйте найти товар по названию.');
    }

    public function test_retries_while_sleeping_service_wakes_up()
    {
        config([
            'services.embedder.url'    => 'http://embedder.test',
            'services.embedder.secret' => 'test-secret',
        ]);
        Http::fakeSequence('embedder.test/*')
            ->push('Bad Gateway', 502)
            ->push(['vector' => [1.0, 0.0, 0.0]]);

        $this->assertSame([1.0, 0.0, 0.0], app(ImageSearchService::class)->embed('photo'));
        Http::assertSentCount(2);
    }

    public function test_search_disabled_without_embedder_url()
    {
        config(['services.embedder.url' => null, 'services.gemini.key' => null]);
        Http::fake();

        $this->post('/api/products/recognize-photo', [
            'image' => UploadedFile::fake()->image('photo.jpg', 10, 10),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('error', 'Поиск по фото временно недоступен. Попробуйте найти товар по названию.');

        Http::assertNothingSent();
    }

    public function test_gemini_is_not_called_when_similar_products_found()
    {
        $this->enableEmbedder();
        config(['services.gemini.key' => 'gemini-test-key']);
        $this->productWithPhoto('Дрель', 'drill-photo');
        $this->artisan('products:index-images');

        $this->customerVector = $this->vectors['customer-drill'];

        $this->post('/api/products/recognize-photo', [
            'image' => UploadedFile::fake()->image('photo.jpg', 10, 10),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonCount(1, 'similar_products');

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'generativelanguage'));
    }

    public function test_nightly_backfill_runs_only_when_search_is_enabled()
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'products:index-images'));

        $this->assertNotNull($event);
        $this->assertSame('30 4 * * *', $event->expression);

        config(['services.embedder.url' => null]);
        $this->assertFalse($event->filtersPass($this->app));

        config(['services.embedder.url' => 'http://embedder.test']);
        $this->assertTrue($event->filtersPass($this->app));
    }

    public function test_backfill_does_not_wake_service_when_nothing_missing()
    {
        $this->enableEmbedder();
        $this->productWithPhoto('Дрель', 'drill-photo');
        $this->artisan('products:index-images');

        Http::fake(['*' => Http::response('не должны были позвать', 500)]);
        $this->artisan('products:index-images')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_saving_new_photo_indexes_it_after_response()
    {
        $this->enableEmbedder();
        $product = Product::factory()->create(['image' => null, 'images' => null]);
        Storage::disk('public')->put('products/drill-photo.webp', 'drill-photo');

        $product->update(['image' => 'products/drill-photo.webp']);
        $this->assertSame(0, ProductImageEmbedding::count(), 'до отправки ответа не считаем');

        $this->app->terminate();

        $this->assertSame(1, ProductImageEmbedding::where('product_id', $product->id)->count());
    }
}
