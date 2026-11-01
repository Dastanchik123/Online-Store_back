<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Счётчики номеров чеков: P-1, P-2… (касса), S-1, S-2… (самообслуживание).
// Вместо прежних k1-…/SS1-… по номеру терминала и MAX()+1 (два параллельных
// чека получали один номер). Старые номера в истории остаются как есть.
return new class extends Migration
{
    public function up()
    {
        Schema::create('order_counters', function (Blueprint $table) {
            $table->string('prefix', 10)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('order_counters');
    }
};
