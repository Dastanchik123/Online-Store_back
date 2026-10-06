<?php
namespace App\Console\Commands;

use App\Support\ApiCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportLegacyProducts extends Command
{
    protected $signature = 'products:import-legacy {file : Path to legacy_import.json} {--dry-run : Only report counts, make no changes}';
    protected $description = 'Wipes all products/categories and replaces them with data from a legacy JSON export';

    public function handle()
    {
        $path = $this->argument('file');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");
            return 1;
        }

        $data = json_decode(file_get_contents($path), true);
        $categories = $data['categories'] ?? [];
        $products = $data['products'] ?? [];

        $this->info('Categories to create: ' . count($categories));
        $this->info('Products to import: ' . count($products));

        if ($this->option('dry-run')) {
            $this->info('Dry run, no changes made.');
            return 0;
        }

        DB::transaction(function () use ($categories, $products) {
            $this->info('Clearing dependent tables...');
            DB::table('supplier_return_items')->delete();
            DB::table('purchase_items')->delete();
            DB::table('order_items')->delete();
            DB::table('inventory_adjustments')->delete();

            $this->info('Deleting all products...');
            DB::table('products')->delete();

            $this->info('Deleting all categories...');
            DB::table('categories')->delete();

            DB::statement('ALTER SEQUENCE products_id_seq RESTART WITH 1');
            DB::statement('ALTER SEQUENCE categories_id_seq RESTART WITH 1');

            $this->info('Inserting new categories...');
            $now = now();
            $categorySlugToId = [];
            foreach ($categories as $cat) {
                $id = DB::table('categories')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'name' => $cat['name'],
                    'slug' => $cat['slug'],
                    'is_active' => true,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $categorySlugToId[$cat['slug']] = $id;
            }

            $this->info('Inserting new products...');
            $bar = $this->output->createProgressBar(count($products));
            foreach (array_chunk($products, 200) as $chunk) {
                $rows = [];
                foreach ($chunk as $p) {
                    $rows[] = [
                        'uuid' => (string) Str::uuid(),
                        'name' => $p['name'],
                        'slug' => $p['slug'],
                        'sku' => $p['sku'],
                        'price' => $p['price'],
                        'stock_quantity' => $p['stock_quantity'],
                        'in_stock' => true,
                        'is_active' => true,
                        'unit' => $p['unit'],
                        'category_id' => $categorySlugToId[$p['category_slug']],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('products')->insert($rows);
                $bar->advance(count($chunk));
            }
            $bar->finish();
            $this->newLine();
        });

        ApiCache::bump();

        $this->info('Done.');
        return 0;
    }
}
