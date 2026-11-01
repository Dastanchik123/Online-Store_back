<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Канал продажи теперь определяется только колонкой orders.channel
// (online | pos | self_service), а не текстом заметки. Старые заказы
// размечаем один раз: кассовые продажи создавались персоналом (staff_id)
// с "POS" в заметке, всё остальное без канала — онлайн-заказы.
return new class extends Migration
{
    public function up()
    {
        DB::table('orders')
            ->whereNull('channel')
            ->where(function ($q) {
                // Заметки, которые писала сама система кассы (PosController,
                // удалённый офлайн-синк), — точные префиксы, а не любое "POS"
                $q->where('notes', 'like', 'Оффлайн продажа (POS%')
                    ->orWhere('notes', 'like', 'Синхронизировано из POS%')
                    ->orWhere(function ($w) {
                        $w->whereNotNull('staff_id')->where('notes', 'like', '%POS%');
                    });
            })
            ->update(['channel' => 'pos']);

        DB::table('orders')->whereNull('channel')->update(['channel' => 'online']);
    }

    public function down()
    {
        // Разметка необратима без потери информации — старые значения NULL
        // не восстанавливаем, колонка остаётся валидной для старого кода.
    }
};
