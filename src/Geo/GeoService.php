<?php
declare(strict_types=1);

namespace Iha\Geo;

use Iha\Core\Database;

/** Reads the official geography tables and validates province -> district -> city selections. */
final class GeoService
{
    public function provinces(): array
    {
        return Database::pdo()->query('SELECT id, name FROM provinces ORDER BY name')->fetchAll();
    }

    public function districts(int $provinceId): array
    {
        $st = Database::pdo()->prepare('SELECT id, name FROM districts WHERE province_id = ? ORDER BY name');
        $st->execute([$provinceId]);
        return $st->fetchAll();
    }

    public function cities(int $districtId): array
    {
        $st = Database::pdo()->prepare('SELECT id, name FROM cities WHERE district_id = ? ORDER BY name');
        $st->execute([$districtId]);
        return $st->fetchAll();
    }

    /** "City, District, Province" for display (custom city names shown as typed). */
    public function describe(?int $provinceId, ?int $districtId, ?int $cityId, ?string $cityOther): string
    {
        $pdo = Database::pdo();
        $parts = [];
        if ($cityOther !== null && $cityOther !== '') {
            $parts[] = $cityOther;
        } elseif ($cityId) {
            $st = $pdo->prepare('SELECT name FROM cities WHERE id = ?');
            $st->execute([$cityId]);
            $parts[] = (string) $st->fetchColumn();
        }
        if ($districtId) {
            $st = $pdo->prepare('SELECT name FROM districts WHERE id = ?');
            $st->execute([$districtId]);
            $parts[] = (string) $st->fetchColumn();
        }
        if ($provinceId) {
            $st = $pdo->prepare('SELECT name FROM provinces WHERE id = ?');
            $st->execute([$provinceId]);
            $parts[] = (string) $st->fetchColumn();
        }
        return implode(', ', array_filter($parts, static fn($p) => $p !== ''));
    }

    /** Option lists needed to render a saved selection without waiting for JavaScript. */
    public function optionsFor(array $loc): array
    {
        $p = ctype_digit((string) ($loc['province_id'] ?? '')) ? (int) $loc['province_id'] : null;
        $d = ctype_digit((string) ($loc['district_id'] ?? '')) ? (int) $loc['district_id'] : null;
        return [
            'provinces' => $this->provinces(),
            'districts' => $p ? $this->districts($p) : [],
            'cities'    => ($p && $d) ? $this->cities($d) : [],
        ];
    }

    /**
     * Validate a location group posted under $prefix ('', 'perm_', 'school_', 'guardian_').
     * The city field holds a city id, the literal "other", or "". "Other" exists for cities only.
     * Student-typed city names are returned in city_other and are never written to the cities table.
     *
     * @return array{0: array{province_id:?int,district_id:?int,city_id:?int,city_other:?string}, 1: array<string,string>}
     */
    public function resolve(array $post, string $prefix): array
    {
        $out = ['province_id' => null, 'district_id' => null, 'city_id' => null, 'city_other' => null];
        $errors = [];
        $k = static fn(string $f): string => $prefix . $f;

        $p = self::parseId($post[$k('province_id')] ?? '');
        $d = self::parseId($post[$k('district_id')] ?? '');
        $rawCity = is_string($post[$k('city_id')] ?? '') ? trim((string) ($post[$k('city_id')] ?? '')) : '';
        $isOther = $rawCity === 'other';
        $c = $isOther ? null : self::parseId($rawCity);

        if ($p === false || $d === false || $c === false) {
            $errors[$k('province_id')] = 'Invalid location selection. Please choose again.';
            return [$out, $errors];
        }
        if ($p === null) {
            if ($d !== null || $c !== null || $isOther) {
                $errors[$k('province_id')] = 'Select the province or territory first.';
            }
            return [$out, $errors];
        }

        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT 1 FROM provinces WHERE id = ?');
        $st->execute([$p]);
        if ($st->fetchColumn() === false) {
            $errors[$k('province_id')] = 'Invalid province or territory.';
            return [$out, $errors];
        }
        $out['province_id'] = $p;

        if ($d === null) {
            if ($c !== null || $isOther) {
                $errors[$k('district_id')] = 'Select the district first.';
            }
            return [$out, $errors];
        }
        $st = $pdo->prepare('SELECT 1 FROM districts WHERE id = ? AND province_id = ?');
        $st->execute([$d, $p]);
        if ($st->fetchColumn() === false) {
            $errors[$k('district_id')] = 'That district does not belong to the selected province.';
            return [$out, $errors];
        }
        $out['district_id'] = $d;

        if ($isOther) {
            $name = preg_replace('/\s+/u', ' ', trim((string) ($post[$k('city_other')] ?? ''))) ?? '';
            $len = mb_strlen($name);
            if ($len < 2 || $len > 100 || !preg_match('/^[\p{L}\p{M}\p{N}\s.,\'()\/\-]+$/u', $name)) {
                $errors[$k('city_other')] = 'Enter the city/locality name (2 to 100 characters).';
            } else {
                $out['city_other'] = $name;
            }
        } elseif ($c !== null) {
            $st = $pdo->prepare('SELECT 1 FROM cities WHERE id = ? AND district_id = ?');
            $st->execute([$c, $d]);
            if ($st->fetchColumn() === false) {
                $errors[$k('city_id')] = 'That city does not belong to the selected district.';
            } else {
                $out['city_id'] = $c;   // a custom name typed earlier is deliberately dropped
            }
        }
        return [$out, $errors];
    }

    /** @return int|null|false  null = empty, false = not a valid id */
    private static function parseId(mixed $v): int|null|false
    {
        if (!is_string($v)) {
            return false;
        }
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        return ctype_digit($v) && strlen($v) <= 9 ? (int) $v : false;
    }
}
