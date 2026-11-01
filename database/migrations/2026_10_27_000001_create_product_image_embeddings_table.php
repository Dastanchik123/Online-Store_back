<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// «Отпечатки» фото товаров (векторы модели CLIP) для поиска похожих по фото.
// Вектор хранится JSON-текстом, а не типом pgvector: товаров немного,
// сравнение делается в PHP, и так одинаково работают Neon, локальный
// Postgres и SQLite в тестах. Одна строка — одно фото (главное или из галереи).
return new class extends Migration
{
    public function up()
    {
        Schema::create('product_image_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('image_path');
            $table->string('model', 100);
            $table->longText('embedding');
            $table->timestamps();

            $table->unique(['product_id', 'image_path']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('product_image_embeddings');
    }
};
