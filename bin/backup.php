<?php
declare(strict_types=1);

/**
 * Database backup with pg_dump (compressed "custom" format), then a readability check and pruning of old copies.
 *   php bin/backup.php
 * .env settings (all optional): PG_DUMP_PATH (full path to pg_dump.exe on Windows), BACKUP_DIR, BACKUP_KEEP_DAYS (default 30).
 * Copy the files in the backup folder to a DIFFERENT machine or storage; a backup on the same disk does not survive the disk.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Iha\Core\Env;

$pgDump = Env::get('PG_DUMP_PATH', 'pg_dump');
$dir = Env::get('BACKUP_DIR', IHA_ROOT . '/storage/backups');
$keepDays = max(1, (int) Env::get('BACKUP_KEEP_DAYS', '30'));

if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create the backup folder: {$dir}\n");
    exit(1);
}
$file = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'iha-' . gmdate('Ymd-His') . '.dump';

/** Run a command with PGPASSWORD set only for the child process. @return array{0:int,1:string} */
$run = static function (array $cmd) use (&$run): array {
    $env = array_merge(getenv(), ['PGPASSWORD' => (string) Env::get('DB_PASSWORD', '')]);
    $p = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($p)) {
        return [127, 'could not start the program'];
    }
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), trim($out)];
};

[$code, $out] = $run([$pgDump, '--format=custom', '--no-owner', '--host=' . Env::require('DB_HOST'), '--port=' . Env::get('DB_PORT', '5432'),
    '--username=' . Env::require('DB_USER'), '--file=' . $file, Env::require('DB_NAME')]);
if ($code !== 0 || !is_file($file) || filesize($file) < 1024) {
    @unlink($file);
    fwrite(STDERR, "Backup FAILED: {$out}\nIf pg_dump was not found, set PG_DUMP_PATH in .env (for example C:\\Program Files\\PostgreSQL\\16\\bin\\pg_dump.exe).\n");
    exit(1);
}

// Check the archive can be read back (pg_restore sits next to pg_dump).
$pgRestore = preg_replace('/pg_dump(\.exe)?$/i', 'pg_restore$1', $pgDump);
[$c2, $o2] = $run([$pgRestore, '--list', $file]);
if ($c2 !== 0) {
    fwrite(STDERR, "WARNING: the backup was written but could not be verified with pg_restore --list: {$o2}\n");
}

$removed = 0;
foreach (glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'iha-*.dump') ?: [] as $old) {
    if ($old !== $file && filemtime($old) < time() - $keepDays * 86400) {
        @unlink($old);
        $removed++;
    }
}
printf("Backup written: %s (%s KB)%s. Removed %d older than %d days.\n", $file, number_format(filesize($file) / 1024, 0), $c2 === 0 ? ', verified' : '', $removed, $keepDays);
