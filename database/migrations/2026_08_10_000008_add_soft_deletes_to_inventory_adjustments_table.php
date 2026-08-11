<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSoftDeletesToInventoryAdjustmentsTable extends Migration
{
    public function up()
    {
        Schema::table('inventory_adjustments', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::table('inventory_adjustments', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
}
