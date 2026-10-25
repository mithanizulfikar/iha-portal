<?php
declare(strict_types=1);

namespace Iha\Core;

use PDO;

/** Single PDO/PostgreSQL connection. Always prepared statements, real (non-emulated) prepares. */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
                Env::require('DB_HOST'),
                Env::get('DB_PORT', '5432'),
                Env::require('DB_NAME'),
                Env::get('DB_SSLMODE', 'prefer')
            );
            self::$pdo = new PDO($dsn, Env::require('DB_USER'), Env::get('DB_PASSWORD', ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            self::$pdo->exec("SET TIME ZONE 'UTC'");
        }
        return self::$pdo;
    }

    /** Run $fn inside a transaction (or a savepoint when one is already open); rolls back on any throwable. */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            $sp = 'sp_' . bin2hex(random_bytes(4));
            $pdo->exec("SAVEPOINT {$sp}");
            try {
                $result = $fn($pdo);
                $pdo->exec("RELEASE SAVEPOINT {$sp}");
                return $result;
            } catch (\Throwable $e) {
                $pdo->exec("ROLLBACK TO SAVEPOINT {$sp}");
                throw $e;
            }
        }
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
