<?php
declare(strict_types=1);

namespace Iha\Eligibility;

use Iha\Academic\MarksCalculator;
use Iha\Core\Audit;
use Iha\Core\Database;
use PDO;

/** Administrator-configurable eligibility rules. A change always creates a NEW immutable version. */
final class RuleService
{
    public function __construct(private EligibilityService $eligibility = new EligibilityService()) {}

    public function active(): ?array
    {
        return Database::pdo()->query(
            'SELECT r.*, u.full_name AS created_by_name FROM eligibility_rules r LEFT JOIN users u ON u.id = r.created_by WHERE r.is_active'
        )->fetch() ?: null;
    }

    public function history(): array
    {
        return Database::pdo()->query(
            'SELECT r.*, u.full_name AS created_by_name FROM eligibility_rules r LEFT JOIN users u ON u.id = r.created_by ORDER BY r.version DESC'
        )->fetchAll();
    }

    /**
     * @return array{version:int, reevaluated:int}
     * @throws \InvalidArgumentException with an admin-facing message
     */
    public function publish(string $dobMin, string $dobMax, string $minPct, string $notes, bool $reevaluate, int $adminId, string $role): array
    {
        $isDate = static function (string $s): bool {
            return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
        };
        if (!$isDate($dobMin) || !$isDate($dobMax)) {
            throw new \InvalidArgumentException('Enter both date-of-birth boundaries as valid dates (YYYY-MM-DD).');
        }
        if ($dobMin > $dobMax) {
            throw new \InvalidArgumentException('The earliest permitted date of birth cannot be after the latest.');
        }
        $cents = MarksCalculator::toCents(trim($minPct));
        if ($cents === null || $cents > 10000) {
            throw new \InvalidArgumentException('The minimum academic percentage must be a number from 0 to 100 (up to 2 decimals).');
        }
        $pct = MarksCalculator::formatCents($cents);
        $notes = trim($notes);
        if (mb_strlen($notes) > 500) {
            throw new \InvalidArgumentException('The note is too long (500 characters maximum).');
        }

        return Database::transaction(function (PDO $pdo) use ($dobMin, $dobMax, $pct, $notes, $reevaluate, $adminId, $role): array {
            $cur = $pdo->query('SELECT * FROM eligibility_rules WHERE is_active FOR UPDATE')->fetch() ?: null;
            if ($cur && $cur['dob_min'] === $dobMin && $cur['dob_max'] === $dobMax && $cur['min_percentage'] === $pct) {
                throw new \InvalidArgumentException('These values are the same as the active rule. Nothing was changed.');
            }
            $next = (int) $pdo->query('SELECT COALESCE(MAX(version), 0) + 1 FROM eligibility_rules')->fetchColumn();
            $pdo->exec('UPDATE eligibility_rules SET is_active = FALSE WHERE is_active');   // before inserting: one active rule only
            $st = $pdo->prepare(
                'INSERT INTO eligibility_rules (version, dob_min, dob_max, min_percentage, is_active, created_by, notes) VALUES (?, ?, ?, ?, TRUE, ?, ?) RETURNING id'
            );
            $st->execute([$next, $dobMin, $dobMax, $pct, $adminId, $notes === '' ? null : $notes]);
            $id = (int) $st->fetchColumn();

            Audit::log('ELIGIBILITY_RULE_CHANGED', 'eligibility_rule', $id, $adminId, $role,
                $cur ? ['version' => (int) $cur['version'], 'dob_min' => $cur['dob_min'], 'dob_max' => $cur['dob_max'], 'min_percentage' => $cur['min_percentage']] : null,
                ['version' => $next, 'dob_min' => $dobMin, 'dob_max' => $dobMax, 'min_percentage' => $pct, 'reevaluate' => $reevaluate]);

            $count = 0;
            if ($reevaluate) {
                // Only applications still waiting for a decision. Approved, rejected and returned ones are left alone.
                foreach ($pdo->query("SELECT id FROM applications WHERE status IN ('SUBMITTED', 'UNDER_REVIEW') ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $appId) {
                    $this->eligibility->evaluate((int) $appId, 'RULE_CHANGE', $adminId, $role);
                    $count++;
                }
            }
            return ['version' => $next, 'reevaluated' => $count];
        });
    }
}
