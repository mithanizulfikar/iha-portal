<?php
declare(strict_types=1);

namespace Iha\Core;

final class View
{
    /** Render a template inside the main layout. */
    public static function render(string $template, array $data = [], string $layout = 'layouts/layout'): void
    {
        $content = self::capture($template, $data);
        $user = \Iha\Auth\Auth::user();
        echo self::capture($layout, $data + [
            'content' => $content,
            'user'    => $user,
            'flashes' => Session::pullFlashes(),
            'title'   => $data['title'] ?? 'IHA Student Registration & Eligibility Portal',
        ]);
    }

    /** Render a template to a string (also used for email bodies). */
    public static function capture(string $template, array $data = []): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require IHA_ROOT . '/templates/' . $template . '.php';
        return (string) ob_get_clean();
    }
}
