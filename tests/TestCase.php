<?php

namespace Tests;

use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\SqliteTestConnection;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        // Регистрируем sqlite-соединение с совместимостью для Postgres-специфичного
        // raw SQL (SUBSTRING(... FROM ...), оператор ~), который используют
        // PosController/SyncController — см. Tests\Support\SqliteTestConnection.
        // Только для тестов, продовый конфиг (config/database.php) не тронут.
        Connection::resolverFor('sqlite', function ($pdo, $database, $prefix, $config) {
            return new SqliteTestConnection($pdo, $database, $prefix, $config);
        });

        parent::setUp();
    }
}
