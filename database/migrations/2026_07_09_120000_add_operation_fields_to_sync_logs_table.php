<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Идемпотентность offline-операций POS: каждая применённая операция
// регистрируется по op_uuid, повторная отправка игнорируется.
return new class extends Migration
{
    public function up()
    {
        Schema::table('sync_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('sync_logs', 'operation_uuid')) {
                $table->uuid('operation_uuid')->nullable()->unique();
            }
            if (!Schema::hasColumn('sync_logs', 'type')) {
                $table->string('type')->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('sync_logs', function (Blueprint $table) {
            $table->dropColumn(['operation_uuid', 'type']);
        });
    }
};
