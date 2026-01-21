<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRentalFieldsToProductsTable extends Migration
{
    
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_rentable')->default(false)->after('is_active');
            $table->decimal('rental_price_per_day', 12, 2)->nullable()->after('is_rentable');
            $table->decimal('security_deposit', 12, 2)->nullable()->after('rental_price_per_day');
        });
    }

    
    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['is_rentable', 'rental_price_per_day', 'security_deposit']);
        });
    }
}
