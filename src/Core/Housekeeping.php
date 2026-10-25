<?php
declare(strict_types=1);

namespace Iha\Core;

/** Deletes expired technical data only (tokens, rate-limit rows, scan log, old sessions). Never touches student records. */
final class Housekeeping
{
    /** @return array<string,int> what was removed */
    public static function run(): array
    {
        $pdo = Database::pdo();
        $days = max(30, (int) Env::get('QR_EVENT_KEEP_DAYS', '180'));
        $out = [
            'verification tokens' => $pdo->exec("DELETE FROM email_verification_tokens WHERE (used_at IS NOT NULL OR expires_at < now()) AND created_at < now() - interval '7 days'"),
            'reset tokens' => $pdo->exec("DELETE FROM password_reset_tokens WHERE (used_at IS NOT NULL OR expires_at < now()) AND created_at < now() - interval '7 days'"),
            'rate-limit entries' => $pdo->exec("DELETE FROM auth_rate_limits WHERE attempted_at < now() - interval '2 days'"),
            'QR scan-log entries' => $pdo->exec("DELETE FROM qr_verification_events WHERE created_at < now() - ({$days} * interval '1 day')"),
        ];
        if ($pdo->query("SELECT to_regclass('public.sessions') IS NOT NULL")->fetchColumn()) {
            $out['old sessions'] = $pdo->exec("DELETE FROM sessions WHERE last_activity < now() - interval '1 day'");
        }
        return array_map('intval', $out);
    }
}
