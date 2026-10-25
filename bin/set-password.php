<?php
declare(strict_types=1);

/** Set a new password for any account from the command line.   php bin/set-password.php user@example.com */

require dirname(__DIR__) . '/src/bootstrap.php';

use Iha\Auth\AuthService;
use Iha\Core\Audit;
use Iha\Core\Database;

$email = strtolower(trim((string) ($argv[1] ?? '')));
if ($email === '') {
    fwrite(STDERR, "Usage: php bin/set-password.php <email>\n");
    exit(1);
}
$st = Database::pdo()->prepare('SELECT id, role FROM users WHERE email = ?');
$st->execute([$email]);
$user = $st->fetch();
if (!$user) {
    fwrite(STDERR, "No account with that email.\n");
    exit(1);
}

$password = getenv('NEW_PASSWORD') ?: null;
if ($password === null) {
    $tty = PHP_OS_FAMILY !== 'Windows';
    echo 'New password (10+ characters, with a letter and a number): ';
    if ($tty) { shell_exec('stty -echo'); }
    $password = trim((string) fgets(STDIN));
    if ($tty) { shell_exec('stty echo'); }
    echo "\n";
}
$errors = AuthService::passwordErrors($password, $password);
if ($user['role'] !== 'student' && strlen($password) < 12) {
    $errors['password'] = 'Administrator passwords must be at least 12 characters.';
}
if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
Database::transaction(function ($pdo) use ($user, $password): void {
    $pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = now(), must_change_password = FALSE WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    Audit::log('PASSWORD_SET_CLI', 'user', (int) $user['id'], null, 'system');
});
echo "Password updated. Existing sessions for this account are signed out.\n";
