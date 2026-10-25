<?php
declare(strict_types=1);

namespace Iha\Core;

final class Csrf
{
    public static function token(): string
    {
        if (!is_string(Session::get('_csrf'))) {
            Session::set('_csrf', bin2hex(random_bytes(32)));
        }
        return Session::get('_csrf');
    }

    public static function valid(mixed $submitted): bool
    {
        $expected = Session::get('_csrf');
        return is_string($expected) && is_string($submitted) && hash_equals($expected, $submitted);
    }

    public static function rotate(): void
    {
        Session::forget('_csrf');
    }
}
