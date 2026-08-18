<?php

namespace Tests\Support;

use Illuminate\Database\SQLiteConnection;

/**
 * Только для тестов (sqlite in-memory, см. phpunit.xml). Часть бизнес-кода
 * (PosController::store, SyncController::push) использует Postgres-специфичный
 * оконный SQL-синтаксис "SUBSTRING(col FROM 'pattern')" для извлечения числового
 * суффикса из order_number (например "k1-123" -> 123). Это валидный синтаксис
 * ANSI SQL/Postgres, но sqlite не поддерживает саму грамматику "FROM" внутри
 * вызова функции (это не runtime-функция, а часть парсера) — запрос падает с
 * syntax error независимо от данных в таблице.
 *
 * Чтобы протестировать реальный HTTP-путь этих контроллеров без изменения
 * бизнес-кода, подключаем на уровне тестового sqlite-соединения regex-функцию
 * REGEXP_SUBSTR и на лету переписываем "SUBSTRING(x FROM 'pattern')" в
 * "REGEXP_SUBSTR(x, 'pattern')" непосредственно перед отправкой в PDO.
 * Семантика запроса не меняется, бизнес-логика контроллеров не трогается.
 */
class SqliteTestConnection extends SQLiteConnection
{
    public function __construct($pdo, $database = '', $tablePrefix = '', array $config = [])
    {
        parent::__construct($pdo, $database, $tablePrefix, $config);

        $this->registerRegexpSubstr();
    }

    private function registerRegexpSubstr(): void
    {
        $pdo = $this->getPdo();

        if (!$pdo instanceof \PDO) {
            return;
        }

        $pdo->sqliteCreateFunction('REGEXP_SUBSTR', function ($subject, $pattern) {
            if ($subject === null) {
                return null;
            }

            // Postgres-паттерн '[0-9]+$' используется как есть в PCRE (совместимо)
            if (@preg_match('/' . $pattern . '/', (string) $subject, $matches)) {
                return $matches[0];
            }

            return null;
        }, 2);
    }

    protected function getDefaultQueryGrammar()
    {
        return $this->withTablePrefix(new class extends \Illuminate\Database\Query\Grammars\SQLiteGrammar {
        });
    }

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        return parent::select($this->rewrite($query), $bindings, $useReadPdo);
    }

    public function selectOne($query, $bindings = [], $useReadPdo = true)
    {
        return parent::selectOne($this->rewrite($query), $bindings, $useReadPdo);
    }

    public function statement($query, $bindings = [])
    {
        return parent::statement($this->rewrite($query), $bindings);
    }

    public function affectingStatement($query, $bindings = [])
    {
        return parent::affectingStatement($this->rewrite($query), $bindings);
    }

    private function rewrite(string $query): string
    {
        // SUBSTRING(order_number FROM '[0-9]+$')  ->  REGEXP_SUBSTR(order_number, '[0-9]+$')
        $query = preg_replace(
            "/SUBSTRING\\(([a-zA-Z0-9_\\.\"]+)\\s+FROM\\s+'([^']*)'\\)/",
            "REGEXP_SUBSTR($1, '$2')",
            $query
        );

        // Postgres regex-match оператор: order_number ~ '^[0-9]+$'  ->  REGEXP_SUBSTR(...) IS NOT NULL
        $query = preg_replace(
            "/([a-zA-Z0-9_\\.\"]+)\\s+~\\s+'([^']*)'/",
            "REGEXP_SUBSTR($1, '$2') IS NOT NULL",
            $query
        );

        return $query;
    }
}
