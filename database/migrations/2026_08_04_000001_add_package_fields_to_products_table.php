<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPackageFieldsToProductsTable extends Migration
{
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('package_unit', 20)->nullable()->after('unit');
            $table->decimal('package_size', 12, 3)->nullable()->after('package_unit');
            $table->decimal('package_price', 12, 2)->nullable()->after('package_size');
        });
    }

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['package_unit', 'package_size', 'package_price']);
        });
    }
}
