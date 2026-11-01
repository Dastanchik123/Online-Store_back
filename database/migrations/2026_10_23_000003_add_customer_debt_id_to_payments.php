<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Погашение долга безналом: QR-платёж GoPay привязан к долгу. После
// подтверждения банка он проводится как выплата по долгу, а не как оплата
// заказа (PaymentSettlementService).
return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('payments', 'customer_debt_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->foreignId('customer_debt_id')->nullable()->after('order_id')
                    ->constrained('customer_debts')->nullOnDelete();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('payments', 'customer_debt_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropConstrainedForeignId('customer_debt_id');
            });
        }
    }
};
