<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ключи идемпотентности (заголовок Idempotency-Key, см. middleware
// App\Http\Middleware\Idempotency): повтор того же запроса после обрыва
// связи возвращает сохранённый ответ вместо второй продажи.
return new class extends Migration
{
    public function up()
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->foreignId('user_id')->nullable();
            $table->string('method', 10);
            $table->string('path');
            $table->string('request_hash', 64);
            // null — запрос ещё выполняется
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->longText('response')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
