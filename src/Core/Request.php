<?php
declare(strict_types=1);

namespace Iha\Core;

final class Request
{
    /** Trimmed POST string, never an array; empty string when absent. */
    public static function post(string $key, int $maxLength = 255): string
    {
        $v = $_POST[$key] ?? '';
        if (!is_string($v)) {
            return '';
        }
        return mb_substr(trim($v), 0, $maxLength);
    }

    /** Raw POST string (used for passwords, which must not be trimmed). */
    public static function postRaw(string $key, int $maxLength = 1024): string
    {
        $v = $_POST[$key] ?? '';
        return is_string($v) ? mb_substr($v, 0, $maxLength) : '';
    }

    public static function query(string $key, int $maxLength = 255): string
    {
        $v = $_GET[$key] ?? '';
        return is_string($v) ? mb_substr(trim($v), 0, $maxLength) : '';
    }

    /**
     * Client IP. By default only REMOTE_ADDR is trusted, because forwarded headers can be forged.
     * Behind a hosting platform's proxy every visitor shares the proxy's address, which would make the per-IP rate limits
     * hit everyone at once; there set TRUSTED_IP_HEADER (for example X-Forwarded-For) to the header YOUR platform fills in
     * with the real client address AND overwrites if a visitor sends their own. Never set it on a server that is reachable directly.
     */
    public static function ip(): string
    {
        $header = (string) Env::get('TRUSTED_IP_HEADER', '');
        if ($header !== '') {
            $value = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $header))] ?? '';
            if (is_string($value) && $value !== '') {
                $first = trim(explode(',', $value)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    return $first;
                }
            }
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public static function userAgent(): ?string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        return is_string($ua) ? mb_substr($ua, 0, 255) : null;
    }
}
