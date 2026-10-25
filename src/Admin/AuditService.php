<?php
declare(strict_types=1);

namespace Iha\Admin;

use Iha\Core\Database;

final class AuditService
{
    /** @return array{rows:array,total:int,pages:int,page:int} */
    public function list(array $f, int $page, int $perPage = 50): array
    {
        $w = [];
        $p = [];
        if (!empty($f['action'])) {
            $w[] = 'al.action = ?';
            $p[] = $f['action'];
        }
        if (!empty($f['actor_role'])) {
            $w[] = 'al.actor_role = ?';
            $p[] = $f['actor_role'];
        }
        if (!empty($f['entity_type'])) {
            $w[] = 'al.entity_type = ?';
            $p[] = $f['entity_type'];
        }
        if (isset($f['entity_id']) && $f['entity_id'] !== '') {
            $w[] = 'al.entity_id = ?';
            $p[] = $f['entity_id'];
        }
        foreach (['date_from' => 'al.created_at >= ?::date', 'date_to' => 'al.created_at < (?::date + 1)'] as $k => $cond) {
            if (!empty($f[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) {
                $w[] = $cond;
                $p[] = $f[$k];
            }
        }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT count(*) FROM audit_logs al {$where}");
        $st->execute($p);
        $total = (int) $st->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;
        $st = $pdo->prepare(
            "SELECT al.id, al.created_at, al.action, al.actor_role, al.entity_type, al.entity_id, al.ip_address::text AS ip,
                    u.full_name AS actor_name, al.old_values::text AS old_values, al.new_values::text AS new_values
             FROM audit_logs al LEFT JOIN users u ON u.id = al.actor_user_id {$where} ORDER BY al.id DESC LIMIT {$perPage} OFFSET {$offset}"
        );
        $st->execute($p);
        return ['rows' => $st->fetchAll(), 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /** @return string[] */
    public function actions(): array
    {
        return Database::pdo()->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(\PDO::FETCH_COLUMN);
    }
}
