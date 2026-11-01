<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Раньше один код купона можно было применять бесконечно и сколько угодно
// раз одним покупателем. usage_limit — общий лимит (null = без лимита),
// used_count — сколько раз применён; orders.coupon_id — по нему проверяется
// "один раз на покупателя" и откатывается счётчик при отмене заказа.
class AddUsageLimitsToCoupons extends Migration
{
    public function up()
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->unsignedInteger('usage_limit')->nullable()->after('min_order_amount');
            $table->unsignedInteger('used_count')->default(0)->after('usage_limit');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('discount')->constrained('coupons')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn(['usage_limit', 'used_count']);
        });
    }
}
