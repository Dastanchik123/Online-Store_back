<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFilterIndexesToProductsTable extends Migration
{
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index('price');
            $table->index('in_stock');
            $table->index('created_at');
            $table->index(['category_id', 'is_active', 'in_stock'], 'products_catalog_filter_idx');
            $table->index(['is_hot', 'hot_group', 'hot_order'], 'products_hot_idx');
        });
    }

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['price']);
            $table->dropIndex(['in_stock']);
            $table->dropIndex(['created_at']);
            $table->dropIndex('products_catalog_filter_idx');
            $table->dropIndex('products_hot_idx');
        });
    }
}
