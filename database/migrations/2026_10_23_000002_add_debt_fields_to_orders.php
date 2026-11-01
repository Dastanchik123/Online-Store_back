<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Продажа в долг с первым взносом по QR: долг создаётся только после
// подтверждения банка, поэтому признак долга и срок погашения хранятся в
// заказе до этого момента.
return new class extends Migration
{
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'is_debt')) {
                $table->boolean('is_debt')->default(false)->after('change_amount');
            }
            if (! Schema::hasColumn('orders', 'debt_due_date')) {
                $table->date('debt_due_date')->nullable()->after('is_debt');
            }
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['debt_due_date', 'is_debt'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
