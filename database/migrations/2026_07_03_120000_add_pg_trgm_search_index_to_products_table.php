<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddPgTrgmSearchIndexToProductsTable extends Migration
{
    public function up()
    {
        // pg_trgm/GIN — расширение и синтаксис, специфичные для Postgres.
        // В тестах используется sqlite (см. phpunit.xml), где полнотекстовый
        // trgm-поиск не нужен и недоступен — просто пропускаем индекс.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX IF NOT EXISTS products_name_trgm_idx ON products USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS products_sku_trgm_idx ON products USING gin (sku gin_trgm_ops)');
    }

    public function down()
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS products_name_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS products_sku_trgm_idx');
    }
}
