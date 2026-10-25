<?php
declare(strict_types=1);

/**
 * Import districts and cities from a CSV with the header: province,district,city
 * (city may be empty for district-only rows).
 *
 *   php bin/import-geo.php --file=data/pk_geo.csv --source-name="..." --source-url="..." \
 *       --coverage="..." --admin-level="district+city" --dataset-date=2025-01-31
 *
 * Provinces must already exist (database/seeds/001_reference.sql). Unknown province names are
 * reported and skipped. Re-running is safe: existing rows are left as they are.
 * Student-entered "Other" cities are never part of this process.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Iha\Core\Database;

$opt = getopt('', ['file:', 'source-name:', 'source-url::', 'coverage:', 'admin-level:', 'dataset-date::', 'notes::']);
foreach (['file', 'source-name', 'coverage', 'admin-level'] as $req) {
    if (empty($opt[$req])) {
        fwrite(STDERR, "Missing --{$req}. Provenance is mandatory so coverage is never overstated.\n");
        exit(1);
    }
}
if (!is_readable($opt['file'])) {
    fwrite(STDERR, "Cannot read {$opt['file']}\n");
    exit(1);
}

$norm = static fn(string $s): string => preg_replace('/\s+/', ' ', mb_strtolower(trim($s)));

$aliases = [
    'kpk' => 'khyber pakhtunkhwa', 'khyber-pakhtunkhwa' => 'khyber pakhtunkhwa', 'nwfp' => 'khyber pakhtunkhwa',
    'islamabad' => 'islamabad capital territory', 'ict' => 'islamabad capital territory',
    'gilgit baltistan' => 'gilgit-baltistan', 'gb' => 'gilgit-baltistan',
    'ajk' => 'azad jammu and kashmir', 'azad kashmir' => 'azad jammu and kashmir', 'azad jammu & kashmir' => 'azad jammu and kashmir',
    'balochistan' => 'balochistan', 'baluchistan' => 'balochistan',
];

$pdo = Database::pdo();
$provinces = [];
foreach ($pdo->query('SELECT id, name FROM provinces') as $p) {
    $provinces[$norm($p['name'])] = (int) $p['id'];
}

$fh = fopen($opt['file'], 'r');
$header = array_map(static fn($h) => strtolower(trim((string) $h)), fgetcsv($fh) ?: []);
foreach (['province', 'district', 'city'] as $col) {
    if (!in_array($col, $header, true)) {
        fwrite(STDERR, "CSV must have columns: province,district,city\n");
        exit(1);
    }
}

$districtId = [];   // "pid|lowername" => id
$cityKnown  = [];
$stats = ['districts_added' => 0, 'cities_added' => 0, 'rows_skipped' => 0, 'rows_read' => 0];
$unknown = [];

$pdo->beginTransaction();
try {
    $selD = $pdo->prepare('SELECT id FROM districts WHERE province_id = ? AND lower(name) = lower(?)');
    $insD = $pdo->prepare('INSERT INTO districts (province_id, name) VALUES (?, ?) RETURNING id');
    $selC = $pdo->prepare('SELECT 1 FROM cities WHERE district_id = ? AND lower(name) = lower(?)');
    $insC = $pdo->prepare('INSERT INTO cities (district_id, name) VALUES (?, ?)');

    while (($row = fgetcsv($fh)) !== false) {
        if ($row === [null] || count($row) < count($header)) {
            continue;
        }
        $r = array_combine($header, array_map(static fn($v) => trim((string) $v), array_slice($row, 0, count($header))));
        $stats['rows_read']++;

        $pk = $norm($r['province']);
        $pk = $aliases[$pk] ?? $pk;
        $pid = $provinces[$pk] ?? null;
        if ($pid === null || $r['district'] === '') {
            $stats['rows_skipped']++;
            $unknown[$r['province']] = true;
            continue;
        }

        $dk = $pid . '|' . $norm($r['district']);
        if (!isset($districtId[$dk])) {
            $selD->execute([$pid, $r['district']]);
            $id = $selD->fetchColumn();
            if ($id === false) {
                $insD->execute([$pid, $r['district']]);
                $id = $insD->fetchColumn();
                $stats['districts_added']++;
            }
            $districtId[$dk] = (int) $id;
        }

        if ($r['city'] !== '') {
            $ck = $districtId[$dk] . '|' . $norm($r['city']);
            if (!isset($cityKnown[$ck])) {
                $selC->execute([$districtId[$dk], $r['city']]);
                if ($selC->fetchColumn() === false) {
                    $insC->execute([$districtId[$dk], $r['city']]);
                    $stats['cities_added']++;
                }
                $cityKnown[$ck] = true;
            }
        }
    }

    $pdo->prepare(
        'INSERT INTO geo_dataset_sources (name, source_url, coverage, admin_level, dataset_date, rows_imported, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $opt['source-name'], $opt['source-url'] ?? null, $opt['coverage'], $opt['admin-level'],
        $opt['dataset-date'] ?? null, $stats['rows_read'] - $stats['rows_skipped'], $opt['notes'] ?? null,
    ]);
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Import failed, nothing was saved: ' . $e->getMessage() . "\n");
    exit(1);
}

print_r($stats);
if ($unknown) {
    echo "Skipped rows had unrecognised provinces: " . implode(', ', array_keys($unknown)) . "\n";
}
