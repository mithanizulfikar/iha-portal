<?php
declare(strict_types=1);

namespace Iha\Mail;

/**
 * Development mailer: appends the message (including verification links) to storage/logs/mail.log.
 * Never use in production; links in that file would let anyone with file access verify accounts.
 */
final class LogMailer implements Mailer
{
    public function send(string $to, string $subject, string $htmlBody, string $textBody): bool
    {
        $entry = sprintf(
            "==== %s ====\nTo: %s\nSubject: %s\n\n%s\n\n",
            gmdate('Y-m-d H:i:s') . ' UTC', $to, $subject, $textBody
        );
        return file_put_contents(IHA_ROOT . '/storage/logs/mail.log', $entry, FILE_APPEND | LOCK_EX) !== false;
    }
}
