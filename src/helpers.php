<?php
declare(strict_types=1);

/** HTML-escape for output. Every dynamic value in templates goes through this. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(\Iha\Core\Csrf::token()) . '">';
}

/** ' is-invalid' when the field has an error, for Bootstrap styling. */
function field_class(array $errors, string $key): string
{
    return isset($errors[$key]) ? ' is-invalid' : '';
}

/** Bootstrap feedback block for a field error (empty string when none). */
function field_error(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<div class="invalid-feedback">' . e($errors[$key]) . '</div>' : '';
}

/**
 * Coloured status badge. ADMIN-SIDE use for eligibility values; student pages only ever call it
 * with kind "app" (application workflow status).
 * kinds: elig (ELIGIBLE/INELIGIBLE/PENDING_REVIEW), crit (PASS/FAIL/PENDING), app (workflow status)
 */
function badge(?string $value, string $kind = 'app'): string
{
    $maps = [
        'elig' => ['ELIGIBLE' => 'success', 'INELIGIBLE' => 'danger', 'PENDING_REVIEW' => 'warning text-dark'],
        'crit' => ['PASS' => 'success', 'FAIL' => 'danger', 'PENDING' => 'warning text-dark'],
        'app'  => ['DRAFT' => 'secondary', 'SUBMITTED' => 'primary', 'UNDER_REVIEW' => 'info text-dark', 'APPROVED' => 'success',
                   'REJECTED' => 'danger', 'RETURNED_FOR_CORRECTION' => 'warning text-dark'],
    ];
    if ($value === null || $value === '') {
        return '<span class="badge text-bg-light border">' . ($kind === 'elig' ? 'NOT EVALUATED' : '&mdash;') . '</span>';
    }
    $class = $maps[$kind][$value] ?? 'secondary';
    return '<span class="badge text-bg-' . $class . '">' . e(str_replace('_', ' ', $value)) . '</span>';
}
