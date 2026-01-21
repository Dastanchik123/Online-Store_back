<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddHotOrderToProductsTable extends Migration
{
    
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->integer('hot_order')->default(0)->after('is_hot');
        });
    }

    
    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('hot_order');
        });
    }
}
