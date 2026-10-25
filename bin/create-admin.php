<?php
declare(strict_types=1);

/** php bin/create-admin.php admin@example.com "Full Name" super_admin|application_reviewer|report_viewer */

require dirname(__DIR__) . '/src/bootstrap.php';

use Iha\Core\Database;

[, $email, $name, $role] = $argv + [null, null, null, null];
if (!$email || !$name || !in_array($role, ['super_admin', 'application_reviewer', 'report_viewer'], true)) {
    fwrite(STDERR, "Usage: php bin/create-admin.php <email> \"<full name>\" <super_admin|application_reviewer|report_viewer>\n");
    exit(1);
}
$email = strtolower(trim($email));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email address.\n");
    exit(1);
}

$password = getenv('ADMIN_PASSWORD') ?: null;
if ($password === null) {
    $tty = PHP_OS_FAMILY !== 'Windows';
    echo 'Password (min 12 characters): ';
    if ($tty) { shell_exec('stty -echo'); }
    $password = trim((string) fgets(STDIN));
    if ($tty) { shell_exec('stty echo'); }
    echo "\n";
}
if (mb_strlen($password) < 12) {
    fwrite(STDERR, "Password must be at least 12 characters.\n");
    exit(1);
}

$stmt = Database::pdo()->prepare(
    'INSERT INTO users (email, password_hash, full_name, role, email_verified_at, terms_accepted_at)
     VALUES (?, ?, ?, ?, now(), now())'
);
$stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name, $role]);
echo "Created {$role}: {$email}\n";
