<?php
declare(strict_types=1);

namespace Iha\Core;

use PDO;

/**
 * Applies numbered .sql files in order. Each file runs in its own transaction and is
 * recorded in schema_migrations with a checksum; an already-applied file whose contents
 * changed is reported as an error instead of being silently re-run.
 */
final class Migrator
{
    public function __construct(private PDO $pdo, private string $dir) {}

    /** @return string[] names of files applied in this run */
    public function run(): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                filename   TEXT PRIMARY KEY,
                checksum   CHAR(64) NOT NULL,
                applied_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )'
        );
        $applied = $this->pdo->query('SELECT filename, checksum FROM schema_migrations')
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        $files = glob(rtrim($this->dir, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $ran = [];
        foreach ($files as $file) {
            $name = basename($file);
            $sql = (string) file_get_contents($file);
            $sum = hash('sha256', $sql);

            if (isset($applied[$name])) {
                if ($applied[$name] !== $sum) {
                    throw new \RuntimeException("Migration {$name} was modified after being applied. Add a new migration instead.");
                }
                continue;
            }

            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec($sql);
                $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (filename, checksum) VALUES (?, ?)');
                $stmt->execute([$name, $sum]);
                $this->pdo->commit();
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw new \RuntimeException("Migration {$name} failed: " . $e->getMessage(), 0, $e);
            }
            $ran[] = $name;
        }
        return $ran;
    }
}
