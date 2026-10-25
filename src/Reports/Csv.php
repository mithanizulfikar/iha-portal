<?php
declare(strict_types=1);

namespace Iha\Reports;

final class Csv
{
    /** Neutralise spreadsheet formula injection: cells starting with = + - @ tab or CR get a leading apostrophe. */
    public static function cell(mixed $value): string
    {
        $s = (string) $value;
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $s;
        }
        return $s;
    }

    /** Stream a UTF-8 CSV download (BOM included so Excel reads it correctly). */
    public static function send(string $filename, array $header, iterable $rows): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map([self::class, 'cell'], $header), ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, array_map([self::class, 'cell'], $row), ',', '"', '');
        }
        fclose($out);
    }
}
