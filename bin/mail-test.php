<?php
declare(strict_types=1);

/** Send a test email with the current .env settings.   php bin/mail-test.php you@example.com */

require dirname(__DIR__) . '/src/bootstrap.php';

use Iha\Core\Env;
use Iha\Mail\MailFactory;
use Iha\Mail\SmtpMailer;

$to = trim((string) ($argv[1] ?? ''));
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php bin/mail-test.php <your email address>\n");
    exit(1);
}
$driver = strtolower((string) Env::get('MAIL_DRIVER', 'log'));
echo "Mail driver: {$driver}\n";
if ($driver !== 'smtp') {
    echo "MAIL_DRIVER is not \"smtp\", so this only writes to storage\\logs\\mail.log. Set MAIL_DRIVER=smtp and the SMTP_* values in .env to send real email.\n";
}

$mailer = MailFactory::make();
try {
    $ok = $mailer->send($to, 'IHA Portal test email', '<p>This is a test email from the IHA Portal. If you can read it, email is working.</p>',
        "This is a test email from the IHA Portal. If you can read it, email is working.\n");
} catch (\Throwable $e) {
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}
if ($ok) {
    echo $driver === 'smtp' ? "Sent to {$to}. Check the inbox (and the spam folder).\n" : "Written to the log file.\n";
    exit(0);
}
fwrite(STDERR, 'Failed to send.' . ($mailer instanceof SmtpMailer && $mailer->lastError !== '' ? ' Reason: ' . $mailer->lastError : '') . "\n");
exit(1);
