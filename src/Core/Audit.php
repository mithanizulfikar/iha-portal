<?php
declare(strict_types=1);

namespace Iha\Core;

final class Audit
{
    public static function log(
        string $action,
        string $entityType,
        string|int|null $entityId = null,
        ?int $actorUserId = null,
        ?string $actorRole = null,
        ?array $old = null,
        ?array $new = null
    ): void {
        $ip = Request::ip();
        Database::pdo()->prepare(
            'INSERT INTO audit_logs (actor_user_id, actor_role, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $actorUserId,
            $actorRole,
            $action,
            $entityType,
            $entityId === null ? null : (string) $entityId,
            $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE),
            $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE),
            filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
            Request::userAgent(),
        ]);
    }
}
