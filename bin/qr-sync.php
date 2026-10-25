<?php
declare(strict_types=1);

/**
 * Issue or revoke QR codes for all existing submitted applications so they match the rules
 * (useful right after setting APP_KEY, or after upgrading).   php bin/qr-sync.php
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Iha\Core\Database;
use Iha\QR\QrService;

if (!QrService::keyOk()) {
    fwrite(STDERR, "APP_KEY in .env is missing or still the placeholder. Set a long random value first, e.g.:\n  php -r \"echo bin2hex(random_bytes(32));\"\n");
    exit(1);
}
$svc = new QrService();
$counts = [];
foreach (Database::pdo()->query("SELECT id FROM applications WHERE status <> 'DRAFT' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $id) {
    $r = $svc->sync((int) $id, null, 'system');
    $counts[$r['action']] = ($counts[$r['action']] ?? 0) + 1;
}
echo $counts ? json_encode($counts) . "\n" : "No submitted applications.\n";
