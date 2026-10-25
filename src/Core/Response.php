<?php
declare(strict_types=1);

namespace Iha\Core;

final class Response
{
    /** Redirect to an application path (never to a user-supplied URL). */
    public static function redirect(string $path): never
    {
        header('Location: ' . $path, true, 303);
        exit;
    }

    public static function error(int $status, string $title, string $message): void
    {
        http_response_code($status);
        View::render('errors/error', ['title' => $title, 'heading' => $title, 'message' => $message]);
    }
}
