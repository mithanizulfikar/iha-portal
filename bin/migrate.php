<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Iha\Core\Database;
use Iha\Core\Migrator;

$ran = (new Migrator(Database::pdo(), dirname(__DIR__) . '/database/migrations'))->run();
echo $ran ? "Applied:\n  " . implode("\n  ", $ran) . "\n" : "Nothing to migrate.\n";
