<?php
declare(strict_types=1);

namespace Iha\Auth;

use Iha\Core\Database;
use Iha\Core\Env;

/**
 * Sliding-window limiter backed by auth_rate_limits. Keys are salted SHA-256 hashes, so the
 * table never holds raw emails or IP addresses. Keys are derived from the *submitted* value
 * whether or not an account exists, so limiting behaves identically for real and fake emails.
 */
final class RateLimiter
{
    public static function key(string $value): string
    {
        return hash('sha256', Env::get('APP_KEY', '') . '|' . mb_strtolower($value));
    }

    public function count(string $bucket, string $key, int $windowSeconds): int
    {
        $st = Database::pdo()->prepare(
            "SELECT count(*) FROM auth_rate_limits
             WHERE bucket = ? AND key_hash = ? AND attempted_at > now() - (?::int * interval '1 second')"
        );
        $st->execute([$bucket, $key, $windowSeconds]);
        return (int) $st->fetchColumn();
    }

    public function tooMany(string $bucket, string $key, int $max, int $windowSeconds): bool
    {
        return $this->count($bucket, $key, $windowSeconds) >= $max;
    }

    public function hit(string $bucket, string $key): void
    {
        Database::pdo()->prepare('INSERT INTO auth_rate_limits (bucket, key_hash) VALUES (?, ?)')->execute([$bucket, $key]);
        if (random_int(1, 100) === 1) {
            Database::pdo()->exec("DELETE FROM auth_rate_limits WHERE attempted_at < now() - interval '2 days'");
        }
    }

    /** Check and consume one attempt. Returns false (and records nothing) when the limit is reached. */
    public function attempt(string $bucket, string $key, int $max, int $windowSeconds): bool
    {
        if ($this->tooMany($bucket, $key, $max, $windowSeconds)) {
            return false;
        }
        $this->hit($bucket, $key);
        return true;
    }

    public function clear(string $bucket, string $key): void
    {
        Database::pdo()->prepare('DELETE FROM auth_rate_limits WHERE bucket = ? AND key_hash = ?')->execute([$bucket, $key]);
    }
}
