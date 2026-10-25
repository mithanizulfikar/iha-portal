<?php
declare(strict_types=1);

namespace Iha\Mail;

use Iha\Core\Env;

final class MailFactory
{
    public static function make(): Mailer
    {
        $smtp = strtolower((string) Env::get('MAIL_DRIVER', 'log')) === 'smtp';
        if (!$smtp && Env::get('APP_ENV', 'production') === 'production') {
            error_log('WARNING: MAIL_DRIVER is "log" in production; students will not receive emails.');
        }
        return $smtp ? new SmtpMailer() : new LogMailer();
    }
}
