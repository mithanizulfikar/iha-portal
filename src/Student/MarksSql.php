<?php
declare(strict_types=1);

namespace Iha\Student;

/** Turns a validated marks string ("75", "75.5") into a plain decimal string for NUMERIC columns. */
final class MarksSql
{
    public static function dec(string $s): string
    {
        $c = \Iha\Academic\MarksCalculator::toCents($s) ?? 0;
        return sprintf('%d.%02d', intdiv($c, 100), $c % 100);
    }
}
