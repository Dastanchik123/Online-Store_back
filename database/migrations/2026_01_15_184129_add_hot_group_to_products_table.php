<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddHotGroupToProductsTable extends Migration
{
    
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('hot_group')->nullable()->after('hot_order');
        });
    }

    
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('hot_group');
        });
    }
}
