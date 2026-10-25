<?php
declare(strict_types=1);

namespace Iha\Mail;

use Iha\Core\Env;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

final class SmtpMailer implements Mailer
{
    /** Reason the last send failed (empty after a success). Shown by bin/mail-test.php. */
    public string $lastError = '';

    public function send(string $to, string $subject, string $htmlBody, string $textBody): bool
    {
        $this->lastError = '';
        if (!class_exists(PHPMailer::class)) {
            throw new \RuntimeException('PHPMailer is not installed. Run "composer install --no-dev" in the project folder.');
        }
        $m = new PHPMailer(true);
        $m->Timeout = 15;          // never leave a student waiting on a dead mail server
        $m->SMTPDebug = 0;
        try {
            $m->isSMTP();
            $m->Host = Env::require('SMTP_HOST');
            $m->Port = (int) Env::get('SMTP_PORT', '587');
            $user = Env::get('SMTP_USER', '');
            $m->SMTPAuth = $user !== '';
            if ($m->SMTPAuth) {
                $m->Username = $user;
                $m->Password = (string) Env::get('SMTP_PASSWORD', '');
            }
            $enc = strtolower((string) Env::get('SMTP_ENCRYPTION', 'tls'));
            $m->SMTPSecure = $enc === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : ($enc === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : '');
            $m->CharSet = 'UTF-8';
            $m->setFrom(Env::require('MAIL_FROM_ADDRESS'), (string) Env::get('MAIL_FROM_NAME', 'IHA Portal'));
            $m->addAddress($to);
            $m->Subject = $subject;
            $m->isHTML(true);
            $m->Body = $htmlBody;
            $m->AltBody = $textBody;
            return $m->send();
        } catch (MailException $e) {
            $this->lastError = trim($e->getMessage() . ' ' . $m->ErrorInfo);
            error_log('SMTP send failed: ' . $this->lastError);
            return false;
        }
    }
}
