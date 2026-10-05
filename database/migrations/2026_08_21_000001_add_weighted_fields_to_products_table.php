<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddWeightedFieldsToProductsTable extends Migration
{
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_weighted')->default(false);
            $table->decimal('min_weight', 12, 3)->nullable();
            $table->decimal('max_weight', 12, 3)->nullable();
        });
    }

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['is_weighted', 'min_weight', 'max_weight']);
        });
    }
}
