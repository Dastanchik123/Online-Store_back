<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddFuzzystrmatchExtension extends Migration
{
    public function up()
    {
        // Расширение Postgres — недоступно и не нужно на sqlite (используется в тестах).
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS fuzzystrmatch');
    }

    public function down()
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP EXTENSION IF EXISTS fuzzystrmatch');
    }
}
