<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddFuzzystrmatchExtension extends Migration
{
    public function up()
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS fuzzystrmatch');
    }

    public function down()
    {
        DB::statement('DROP EXTENSION IF EXISTS fuzzystrmatch');
    }
}
