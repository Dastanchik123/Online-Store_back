<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Приложение переведено с UTC на Asia/Bishkek (UTC+6). Колонки timestamp
// без пояса хранили время в UTC — сдвигаем их на +6 часов, чтобы старые
// записи читались в новом поясе так же, как раньше.
return new class extends Migration
{
    private const OFFSET = '6 hours';

    public function up()
    {
        $this->shift('+');
    }

    public function down()
    {
        $this->shift('-');
    }

    private function shift(string $sign): void
    {
        if (DB::getDriverName() !== 'pgsql') return;

        $columns = DB::select("
            SELECT table_name, column_name
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND data_type = 'timestamp without time zone'
              AND table_name <> 'migrations'
        ");

        foreach ($columns as $c) {
            DB::statement(sprintf(
                'UPDATE "%s" SET "%s" = "%s" %s interval \'%s\' WHERE "%s" IS NOT NULL',
                $c->table_name, $c->column_name, $c->column_name, $sign, self::OFFSET, $c->column_name
            ));
        }
    }
};
