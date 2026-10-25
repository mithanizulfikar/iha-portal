<?php
declare(strict_types=1);

namespace Iha\Geo;

use Iha\Auth\Auth;
use Iha\Core\Request;

/** JSON lookups for the dependent dropdowns. Public geography only, but still login-gated. */
final class GeoController
{
    private GeoService $geo;

    public function __construct()
    {
        $this->geo = new GeoService();
    }

    public function districts(): void
    {
        $this->guard();
        $id = Request::query('province_id', 9);
        $this->json(ctype_digit($id) ? $this->geo->districts((int) $id) : []);
    }

    public function cities(): void
    {
        $this->guard();
        $id = Request::query('district_id', 9);
        $this->json(ctype_digit($id) ? $this->geo->cities((int) $id) : []);
    }

    private function guard(): void
    {
        if (Auth::user() === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo '{"error":"unauthorised"}';
            exit;
        }
    }

    private function json(array $rows): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    }
}
