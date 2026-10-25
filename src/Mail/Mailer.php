<?php
declare(strict_types=1);

namespace Iha\Mail;

interface Mailer
{
    /** @return bool true when the message was handed off successfully */
    public function send(string $to, string $subject, string $htmlBody, string $textBody): bool;
}
