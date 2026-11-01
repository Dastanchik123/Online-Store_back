<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ImageSearch\ImageSearchService;
use Illuminate\Console\Command;

// Заполняет индекс поиска по похожим картинкам для уже загруженных фото.
// Новые фото индексируются сами при сохранении товара (AppServiceProvider),
// эта команда нужна один раз после включения поиска и при смене модели.
class IndexProductImages extends Command
{
    protected $signature = 'products:index-images
                            {--force : Пересчитать и уже проиндексированные фото}';

    protected $description = 'Посчитать отпечатки фото товаров для поиска похожих по фото';

    public function handle(ImageSearchService $search)
    {
        if (! $search->isEnabled()) {
            $this->error('EMBEDDER_URL не задан — сервис отпечатков картинок не подключён.');
            return 1;
        }

        $query = Product::query()
            ->where(function ($q) {
                $q->whereNotNull('image')->orWhereNotNull('images');
            });

        $total = (clone $query)->count();
        $this->info("Товаров с фото: {$total}");

        $indexed = 0;
        $bar = $this->output->createProgressBar($total);
        $query->orderBy('id')->chunkById(50, function ($products) use ($search, $bar, &$indexed) {
            foreach ($products as $product) {
                $indexed += $search->indexProduct($product, (bool) $this->option('force'));
                $bar->advance();
            }
        });
        $bar->finish();
        $this->newLine();

        $this->info("Новых отпечатков: {$indexed}. Всего в индексе фото: {$search->indexedCount()}");
        return 0;
    }
}
