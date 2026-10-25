<?php
declare(strict_types=1);

use Iha\Core\Env;

$root = dirname(__DIR__);
define('IHA_ROOT', $root);
require $root . '/src/helpers.php';
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    // Fallback PSR-4 autoloader so Stage 1-2 runs before `composer install`.
    spl_autoload_register(static function (string $class) use ($root): void {
        if (str_starts_with($class, 'Iha\\')) {
            $file = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}

Env::load($root . '/.env');
date_default_timezone_set('UTC');

ini_set('display_errors', Env::get('APP_ENV', 'production') === 'production' ? '0' : '1');
error_reporting(E_ALL);
