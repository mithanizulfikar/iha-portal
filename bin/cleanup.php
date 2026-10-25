<?php
declare(strict_types=1);

/**
 * Housekeeping for technical data only (never student records): used or expired tokens, rate-limit rows, old QR scan-log
 * entries (QR_EVENT_KEEP_DAYS, default 180) and old sessions.   Run daily:   php bin/cleanup.php
 */

require dirname(__DIR__) . '/src/bootstrap.php';

foreach (Iha\Core\Housekeeping::run() as $label => $n) {
    echo "Removed {$n} {$label}\n";
}
