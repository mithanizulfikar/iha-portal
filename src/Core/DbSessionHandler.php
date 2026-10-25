<?php
declare(strict_types=1);

namespace Iha\Core;

use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * PHP sessions in PostgreSQL. Only a SHA-256 of the session id is stored, so a copy of the table cannot be used to take over a login.
 * Unlike file sessions there is no lock: two simultaneous requests from one browser can overwrite each other's changes
 * (at worst a one-time message is lost). Session IDs older than 8 hours are never accepted; the application's own 30-minute idle timeout is stricter.
 */
final class DbSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public const MAX_AGE_SECONDS = 28800;

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        try {
            $st = Database::pdo()->prepare("SELECT data FROM sessions WHERE id = ? AND last_activity > now() - (?::int * interval '1 second')");
            $st->execute([self::key($id), self::MAX_AGE_SECONDS]);
            $v = $st->fetchColumn();
            return $v === false ? '' : (string) base64_decode((string) $v, true);
        } catch (\Throwable $e) {
            error_log('Session read failed: ' . $e->getMessage());
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            Database::pdo()->prepare(
                'INSERT INTO sessions (id, data) VALUES (?, ?) ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, last_activity = now()'
            )->execute([self::key($id), base64_encode($data)]);
            return true;
        } catch (\Throwable $e) {
            error_log('Session write failed: ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            Database::pdo()->prepare('DELETE FROM sessions WHERE id = ?')->execute([self::key($id)]);
        } catch (\Throwable $e) {
            error_log('Session destroy failed: ' . $e->getMessage());
        }
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            return Database::pdo()->exec("DELETE FROM sessions WHERE last_activity < now() - interval '1 day'");
        } catch (\Throwable $e) {
            error_log('Session gc failed: ' . $e->getMessage());
            return false;
        }
    }

    public function validateId(string $id): bool
    {
        try {
            $st = Database::pdo()->prepare("SELECT 1 FROM sessions WHERE id = ? AND last_activity > now() - (?::int * interval '1 second')");
            $st->execute([self::key($id), self::MAX_AGE_SECONDS]);
            return $st->fetchColumn() !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        try {
            Database::pdo()->prepare('UPDATE sessions SET last_activity = now() WHERE id = ?')->execute([self::key($id)]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function key(string $id): string
    {
        return hash('sha256', $id);
    }
}
