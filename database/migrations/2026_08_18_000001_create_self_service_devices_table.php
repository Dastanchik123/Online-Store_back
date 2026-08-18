<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSelfServiceDevicesTable extends Migration
{
    public function up()
    {
        Schema::create('self_service_devices', function (Blueprint $table) {
            $table->id();
            $table->string('terminal_id');
            $table->string('label')->nullable();
            // sha256 самого токена (не bcrypt) — тот же подход, что у
            // personal_access_tokens в Sanctum: токен случайный и длинный,
            // поэтому радужные таблицы не страшны, а sha256 даёт быстрый
            // индексируемый поиск WHERE token_hash = ?. Сырой токен отдаётся
            // кассе один раз, при пейринге, и на сервере больше не хранится.
            $table->string('token_hash')->nullable()->unique();
            // Код пейринга — короткий, одноразовый, живёт 10 минут и вводится
            // вручную сотрудником на кассе. Храним как есть (не как пароль):
            // короткий TTL + rate-limit на роуте достаточны.
            $table->string('pairing_code')->nullable();
            $table->dateTime('pairing_code_expires_at')->nullable();
            $table->dateTime('paired_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();

            $table->index('terminal_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('self_service_devices');
    }
}
