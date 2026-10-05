<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddReferenceToInventoryAdjustmentsTable extends Migration
{
    public function up()
    {
        Schema::table('inventory_adjustments', function (Blueprint $table) {
            $table->nullableMorphs('reference');
        });
    }

    public function down()
    {
        Schema::table('inventory_adjustments', function (Blueprint $table) {
            $table->dropMorphs('reference');
        });
    }
}
