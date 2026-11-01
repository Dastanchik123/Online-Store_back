<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Касса перешла на 100% онлайн: офлайн-очередь POS (/api/sync/push) удалена,
// журнал идемпотентности её операций больше не нужен.
return new class extends Migration
{
    public function up()
    {
        Schema::dropIfExists('sync_logs');
    }

    public function down()
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_uuid')->nullable()->unique();
            $table->string('type')->nullable();
            $table->timestamps();
        });
    }
};
