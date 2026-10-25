<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Iha\Core\Database;

// Seeds are idempotent (ON CONFLICT DO NOTHING) and safe to re-run.
$pdo = Database::pdo();
$files = glob(dirname(__DIR__) . '/database/seeds/*.sql') ?: [];
sort($files, SORT_STRING);
foreach ($files as $f) {
    $pdo->beginTransaction();
    $pdo->exec((string) file_get_contents($f));
    $pdo->commit();
    echo 'Seeded ' . basename($f) . "\n";
}
