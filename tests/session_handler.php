<?php
declare(strict_types=1);

/** Database session handler against the real database (rolled back).   php tests/session_handler.php */

require dirname(__DIR__) . '/src/bootstrap.php';

use Iha\Core\Database;
use Iha\Core\DbSessionHandler;
use Iha\Core\Request;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
}
function one(string $sql, array $p = []): mixed
{
    $s = Database::pdo()->prepare($sql);
    $s->execute($p);
    return $s->fetchColumn();
}

$pdo = Database::pdo();
$pdo->beginTransaction();
$h = new DbSessionHandler();
$id = bin2hex(random_bytes(16));
$payload = "uid|i:5;_csrf|s:8:\"abcdefgh\";_flash|a:1:{i:0;a:2:{s:4:\"type\";s:7:\"success\";s:7:\"message\";s:2:\"ok\";}}";

echo "Session storage\n";
check('open and close succeed', $h->open('', 'IHASESSID') && $h->close());
check('an unknown session id reads as empty and is not valid', $h->read($id) === '' && $h->validateId($id) === false);
check('write stores the session', $h->write($id, $payload) === true);
check('read returns exactly what was written', $h->read($id) === $payload);
check('the stored row never contains the session id or the readable data', (int) one('SELECT count(*) FROM sessions WHERE id = ?', [$id]) === 0
    && (int) one('SELECT count(*) FROM sessions WHERE id = ?', [hash('sha256', $id)]) === 1 && !str_contains((string) one('SELECT data FROM sessions WHERE id = ?', [hash('sha256', $id)]), 'csrf'));
check('the session id is now valid', $h->validateId($id) === true);
$h->write($id, $payload . 'x');
check('writing again updates the same row', (int) one('SELECT count(*) FROM sessions WHERE id = ?', [hash('sha256', $id)]) === 1 && $h->read($id) === $payload . 'x');

echo "Expiry and removal\n";
$pdo->prepare("UPDATE sessions SET last_activity = now() - interval '9 hours' WHERE id = ?")->execute([hash('sha256', $id)]);
check('a session idle for over 8 hours is no longer accepted', $h->read($id) === '' && $h->validateId($id) === false);
$h->updateTimestamp($id, '');
check('updateTimestamp revives the activity time', $h->validateId($id) === true);
$old = bin2hex(random_bytes(16));
$h->write($old, 'a|i:1;');
$pdo->prepare("UPDATE sessions SET last_activity = now() - interval '3 days' WHERE id = ?")->execute([hash('sha256', $old)]);
$removed = $h->gc(1440);
check('gc deletes sessions older than a day and keeps the rest', $removed >= 1 && (int) one('SELECT count(*) FROM sessions WHERE id = ?', [hash('sha256', $old)]) === 0 && $h->validateId($id));
check('destroy removes the session', $h->destroy($id) === true && $h->validateId($id) === false && (int) one('SELECT count(*) FROM sessions WHERE id = ?', [hash('sha256', $id)]) === 0);

echo "Client address behind a proxy\n";
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
putenv('TRUSTED_IP_HEADER');
check('by default only REMOTE_ADDR is used and a forged header is ignored', (function () {
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';
    return Request::ip() === '10.0.0.1';
})());
putenv('TRUSTED_IP_HEADER=X-Forwarded-For');
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9, 10.0.0.1';
check('with TRUSTED_IP_HEADER the first address in the header is used', Request::ip() === '203.0.113.9');
$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';
check('a garbage header falls back to REMOTE_ADDR', Request::ip() === '10.0.0.1');
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
check('a missing header falls back to REMOTE_ADDR', Request::ip() === '10.0.0.1');
putenv('TRUSTED_IP_HEADER');

echo "Housekeeping\n";
$r = \Iha\Core\Housekeeping::run();
check('housekeeping runs and reports counts including sessions', isset($r['verification tokens'], $r['reset tokens'], $r['rate-limit entries'], $r['old sessions']));

$pdo->rollBack();
echo "\n{$pass} passed, {$fail} failed (all test data rolled back)\n";
exit($fail === 0 ? 0 : 1);
