<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersTable extends Migration
{
    
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Необязателен: покупатели входят по номеру телефона. На уже
            // развёрнутых Postgres-базах NOT NULL снимает миграция
            // 2026_10_24_000001_phone_login (sqlite в тестах ALTER COLUMN не умеет)
            $table->string('email')->nullable()->unique();
            $table->string('role')->default('user');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    
    public function down()
    {
        Schema::dropIfExists('users');
    }
}
