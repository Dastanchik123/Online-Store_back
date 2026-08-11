<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class MakeProductsSlugSkuUniqueAmongActiveOnly extends Migration
{
    public function up()
    {
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_slug_unique');
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_sku_unique');

        DB::statement('CREATE UNIQUE INDEX products_slug_unique ON products (slug) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX products_sku_unique ON products (sku) WHERE deleted_at IS NULL');
    }

    public function down()
    {
        DB::statement('DROP INDEX IF EXISTS products_slug_unique');
        DB::statement('DROP INDEX IF EXISTS products_sku_unique');

        DB::statement('ALTER TABLE products ADD CONSTRAINT products_slug_unique UNIQUE (slug)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_sku_unique UNIQUE (sku)');
    }
}
