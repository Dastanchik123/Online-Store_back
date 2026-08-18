<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class MakeProductsSlugSkuUniqueAmongActiveOnly extends Migration
{
    public function up()
    {
        // DROP CONSTRAINT — синтаксис Postgres. sqlite (тесты) хранит unique
        // constraint из исходного blueprint иначе, а partial unique index
        // (CREATE UNIQUE INDEX ... WHERE) sqlite поддерживает так же, как Postgres,
        // поэтому просто создаём его, не трогая constraint по имени.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_slug_unique');
            DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_sku_unique');
        }

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS products_slug_unique ON products (slug) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS products_sku_unique ON products (sku) WHERE deleted_at IS NULL');
    }

    public function down()
    {
        DB::statement('DROP INDEX IF EXISTS products_slug_unique');
        DB::statement('DROP INDEX IF EXISTS products_sku_unique');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_slug_unique UNIQUE (slug)');
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_sku_unique UNIQUE (sku)');
        }
    }
}
