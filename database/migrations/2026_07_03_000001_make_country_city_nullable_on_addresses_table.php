<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class MakeCountryCityNullableOnAddressesTable extends Migration
{
    public function up()
    {
        DB::statement('ALTER TABLE addresses ALTER COLUMN country DROP NOT NULL');
        DB::statement('ALTER TABLE addresses ALTER COLUMN city DROP NOT NULL');
    }

    public function down()
    {
        DB::statement('ALTER TABLE addresses ALTER COLUMN country SET NOT NULL');
        DB::statement('ALTER TABLE addresses ALTER COLUMN city SET NOT NULL');
    }
}
