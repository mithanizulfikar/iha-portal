<?php
declare(strict_types=1);

namespace Iha\QR;

use Iha\Auth\RateLimiter;
use Iha\Core\Audit;
use Iha\Core\Database;
use Iha\Core\Env;
use PDO;

/**
 * Issues, revokes and verifies QR codes.
 *
 * An application holds an ACTIVE QR code only while ALL of these are true (checked by sync()):
 *   - its effective eligibility status is ELIGIBLE
 *   - its status is SUBMITTED, UNDER_REVIEW or APPROVED (not draft, returned for correction, or rejected)
 *   - all five sections are complete
 *   - the student's account is active and the email is verified
 * sync() is called after every event that can change one of those facts, so a code is revoked as soon
 * as it stops being justified. Only the token HASH is stored; see migration 011 for how the code is redrawn.
 */
final class QrService
{
    public const ISSUABLE_STATUSES = ['SUBMITTED', 'UNDER_REVIEW', 'APPROVED'];

    /** A real secret is required: the placeholder from .env.example is refused. */
    public static function keyOk(): bool
    {
        $k = (string) Env::get('APP_KEY', '');
        return strlen($k) >= 32 && !str_starts_with($k, 'change-me');
    }

    private static function tokenFor(string $salt): string
    {
        return hash_hmac('sha256', 'iha-qr-v1|' . $salt, (string) Env::get('APP_KEY', ''));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function verifyUrl(string $token): string
    {
        return rtrim((string) Env::get('APP_URL', 'http://localhost:8080'), '/') . '/verify?token=' . $token;
    }

    /** The URL encoded in an issued code, or null if it can't be redrawn (APP_KEY missing/changed). */
    public function urlFor(array $qrRow): ?string
    {
        if (!self::keyOk() || empty($qrRow['token_salt'])) {
            return null;
        }
        $token = self::tokenFor((string) $qrRow['token_salt']);
        return hash_equals((string) $qrRow['token_hash'], self::hashToken($token)) ? self::verifyUrl($token) : null;
    }

    /**
     * Bring the application's QR state in line with the rules above.
     * @return array{action:string, qr_id:?int}  action: issued | revoked | kept | none | blocked_key
     */
    public function sync(int $appId, ?int $actorId = null, ?string $actorRole = null): array
    {
        return Database::transaction(function (PDO $pdo) use ($appId, $actorId, $actorRole): array {
            $ctx = $this->context($appId);
            if ($ctx === null) {
                return ['action' => 'none', 'qr_id' => null];
            }
            $should = $this->conditionsMet($ctx);

            $st = $pdo->prepare("SELECT id FROM student_qr_codes WHERE application_id = ? AND status = 'ACTIVE' FOR UPDATE");
            $st->execute([$appId]);
            $activeId = $st->fetchColumn();
            $activeId = $activeId === false ? null : (int) $activeId;

            if ($activeId !== null && !$should) {
                $this->revokeRow($activeId, $appId, null, $this->reasonWhyNot($ctx), $actorId, $actorRole);
                return ['action' => 'revoked', 'qr_id' => $activeId];
            }
            if ($activeId === null && $should) {
                if (!self::keyOk()) {
                    error_log('QR not issued for application ' . $appId . ': APP_KEY is missing or still the placeholder.');
                    return ['action' => 'blocked_key', 'qr_id' => null];
                }
                return ['action' => 'issued', 'qr_id' => $this->issue($appId, (int) $ctx['student_profile_id'], $actorId, $actorRole)];
            }
            return ['action' => $activeId !== null ? 'kept' : 'none', 'qr_id' => $activeId];
        });
    }

    /** Administrator: revoke the active code (e.g. compromised). Needs a documented reason. */
    public function adminRevoke(int $appId, int $adminId, string $role, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new \InvalidArgumentException('A reason of at least 5 characters is required.');
        }
        Database::transaction(function (PDO $pdo) use ($appId, $adminId, $role, $reason): void {
            $st = $pdo->prepare("SELECT id FROM student_qr_codes WHERE application_id = ? AND status = 'ACTIVE' FOR UPDATE");
            $st->execute([$appId]);
            $id = $st->fetchColumn();
            if ($id === false) {
                throw new \InvalidArgumentException('There is no active QR code for this application.');
            }
            $this->revokeRow((int) $id, $appId, $adminId, 'ADMIN: ' . $reason, $adminId, $role);
        });
    }

    /** Administrator: revoke the active code (if any) and issue a fresh one, provided the issuance rules hold. */
    public function adminReplace(int $appId, int $adminId, string $role, string $reason): int
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new \InvalidArgumentException('A reason of at least 5 characters is required.');
        }
        if (!self::keyOk()) {
            throw new \InvalidArgumentException('APP_KEY is not set to a real secret in .env, so QR codes cannot be issued.');
        }
        return Database::transaction(function (PDO $pdo) use ($appId, $adminId, $role, $reason): int {
            $ctx = $this->context($appId);
            if ($ctx === null || !$this->conditionsMet($ctx)) {
                throw new \InvalidArgumentException('A QR code can only be issued while the application is ELIGIBLE, submitted (not returned or rejected), complete, and the account is verified and active.');
            }
            $st = $pdo->prepare("SELECT id FROM student_qr_codes WHERE application_id = ? AND status = 'ACTIVE' FOR UPDATE");
            $st->execute([$appId]);
            $oldId = $st->fetchColumn();
            if ($oldId !== false) {
                $this->revokeRow((int) $oldId, $appId, $adminId, 'REPLACED: ' . $reason, $adminId, $role);
            }
            $newId = $this->issue($appId, (int) $ctx['student_profile_id'], $adminId, $role);
            if ($oldId !== false) {
                $pdo->prepare('UPDATE student_qr_codes SET replaced_by_id = ? WHERE id = ?')->execute([$newId, $oldId]);
                Audit::log('QR_REPLACED', 'application', $appId, $adminId, $role, ['qr_id' => (int) $oldId], ['qr_id' => $newId, 'reason' => $reason]);
            }
            return $newId;
        });
    }

    // ---- reading ------------------------------------------------------------------------------

    public function activeForApplication(int $appId): ?array
    {
        $st = Database::pdo()->prepare("SELECT * FROM student_qr_codes WHERE application_id = ? AND status = 'ACTIVE' ORDER BY id DESC LIMIT 1");
        $st->execute([$appId]);
        return $st->fetch() ?: null;
    }

    /** The logged-in student's active code (their own only). Returns id, created_at and the URL to draw. */
    public function forUser(int $userId): ?array
    {
        $st = Database::pdo()->prepare(
            "SELECT q.* FROM student_qr_codes q JOIN student_profiles sp ON sp.id = q.student_profile_id
             WHERE sp.user_id = ? AND q.status = 'ACTIVE' ORDER BY q.id DESC LIMIT 1"
        );
        $st->execute([$userId]);
        $row = $st->fetch();
        return $row ? ['id' => (int) $row['id'], 'created_at' => $row['created_at'], 'url' => $this->urlFor($row)] : null;
    }

    /** @return array{scans:int,last_scan:?string} */
    public function scanSummary(int $appId): array
    {
        $st = Database::pdo()->prepare(
            "SELECT count(*) AS n, max(e.created_at) AS last FROM qr_verification_events e
             JOIN student_qr_codes q ON q.id = e.qr_code_id WHERE q.application_id = ? AND e.result = 'VALID'"
        );
        $st->execute([$appId]);
        $r = $st->fetch();
        return ['scans' => (int) $r['n'], 'last_scan' => $r['last']];
    }

    /**
     * Public verification. Returns only what the public page may show. A revoked, unknown or malformed
     * token all give the same bare "invalid" answer, so nothing about eligibility history leaks.
     *
     * @return array{valid:bool, student_id?:string, name?:string, school?:string, checked_at:string}
     */
    public function verify(string $token, string $ip): array
    {
        $now = gmdate('Y-m-d H:i:s') . ' UTC';
        $row = null;
        if (preg_match('/^[0-9a-f]{64}$/', $token)) {
            $st = Database::pdo()->prepare(
                "SELECT q.id, sp.student_id, sp.full_name, ar.institution_name
                 FROM student_qr_codes q
                 JOIN student_profiles sp ON sp.id = q.student_profile_id
                 JOIN users u ON u.id = sp.user_id
                 LEFT JOIN academic_records ar ON ar.application_id = q.application_id AND ar.is_eligibility_basis
                 WHERE q.token_hash = ? AND q.status = 'ACTIVE' AND u.status = 'active' AND u.email_verified_at IS NOT NULL"
            );
            $st->execute([self::hashToken($token)]);
            $row = $st->fetch() ?: null;
        }

        Database::pdo()->prepare('INSERT INTO qr_verification_events (qr_code_id, result, ip_hash) VALUES (?, ?, ?)')
            ->execute([$row['id'] ?? null, $row ? 'VALID' : 'INVALID', RateLimiter::key($ip)]);

        if ($row === null) {
            return ['valid' => false, 'checked_at' => $now];
        }
        $out = ['valid' => true, 'student_id' => $row['student_id'], 'checked_at' => $now];
        if (Env::get('QR_PUBLIC_SHOW_NAME', '1') === '1') {
            $out['name'] = (string) $row['full_name'];
        }
        if (Env::get('QR_PUBLIC_SHOW_SCHOOL', '1') === '1' && $row['institution_name'] !== null) {
            $out['school'] = (string) $row['institution_name'];
        }
        return $out;
    }

    // ---- internals ----------------------------------------------------------------------------

    private function context(int $appId): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT a.status, a.completion_percent, a.student_profile_id, u.status AS user_status, u.email_verified_at, ae.effective_status
             FROM applications a
             JOIN student_profiles sp ON sp.id = a.student_profile_id
             JOIN users u ON u.id = sp.user_id
             LEFT JOIN application_eligibility ae ON ae.application_id = a.id
             WHERE a.id = ? FOR UPDATE OF a'
        );
        $st->execute([$appId]);
        return $st->fetch() ?: null;
    }

    private function conditionsMet(array $c): bool
    {
        return $c['effective_status'] === 'ELIGIBLE'
            && in_array($c['status'], self::ISSUABLE_STATUSES, true)
            && (int) $c['completion_percent'] === 100
            && $c['user_status'] === 'active'
            && $c['email_verified_at'] !== null;
    }

    private function reasonWhyNot(array $c): string
    {
        if ($c['effective_status'] !== 'ELIGIBLE') {
            return 'ELIGIBILITY_CHANGED';
        }
        return match ($c['status']) {
            'REJECTED' => 'APPLICATION_REJECTED',
            'RETURNED_FOR_CORRECTION' => 'RETURNED_FOR_CORRECTION',
            default => 'CONDITIONS_NOT_MET',
        };
    }

    private function issue(int $appId, int $profileId, ?int $actorId, ?string $role): int
    {
        $salt = bin2hex(random_bytes(16));
        $token = self::tokenFor($salt);
        $st = Database::pdo()->prepare(
            'INSERT INTO student_qr_codes (student_profile_id, application_id, token_hash, token_salt) VALUES (?, ?, ?, ?) RETURNING id'
        );
        $st->execute([$profileId, $appId, self::hashToken($token), $salt]);
        $id = (int) $st->fetchColumn();
        Audit::log('QR_ISSUED', 'application', $appId, $actorId, $role, null, ['qr_id' => $id]);
        return $id;
    }

    private function revokeRow(int $qrId, int $appId, ?int $revokedBy, string $reason, ?int $actorId, ?string $role): void
    {
        Database::pdo()->prepare(
            "UPDATE student_qr_codes SET status = 'REVOKED', revoked_at = now(), revoked_by = ?, revoke_reason = ? WHERE id = ? AND status = 'ACTIVE'"
        )->execute([$revokedBy, $reason, $qrId]);
        Audit::log('QR_REVOKED', 'application', $appId, $actorId, $role, ['qr_id' => $qrId], ['reason' => $reason]);
    }
}
