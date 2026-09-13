<?php

declare(strict_types=1);

/**
 * View-Hilfsfunktionen (nur Darstellung, keine Geschäftslogik).
 */

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('fmt_date')) {
    function fmt_date(?string $value, string $fallback = '–'): string
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000')) {
            return $fallback;
        }
        $ts = strtotime($value);

        return $ts === false ? $fallback : date('d.m.Y', $ts);
    }
}

if (!function_exists('fmt_datetime')) {
    function fmt_datetime(?string $value, string $fallback = '–'): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }
        $dt = date_create($value, new DateTimeZone('UTC'));
        if ($dt === false) {
            return $fallback;
        }
        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));

        return $dt->format('d.m.Y H:i');
    }
}

if (!function_exists('fmt_money')) {
    function fmt_money(mixed $value, string $fallback = '–'): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        return number_format((float) $value, 2, ',', '.') . ' €';
    }
}

if (!function_exists('fmt_bytes')) {
    function fmt_bytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0, ',', '.') . ' KB';
        }

        return $bytes . ' B';
    }
}

if (!function_exists('old')) {
    function old(string $key, mixed $default = ''): string
    {
        $old = $_SESSION['_old_input'] ?? [];
        $value = $old[$key] ?? $default;

        return is_scalar($value) ? (string) $value : '';
    }
}

if (!function_exists('field_error')) {
    function field_error(string $key): string
    {
        $errors = $_SESSION['_errors'] ?? [];
        if (empty($errors[$key])) {
            return '';
        }

        return '<span class="form-error">' . e($errors[$key]) . '</span>';
    }
}

if (!function_exists('has_error')) {
    function has_error(string $key): bool
    {
        return !empty(($_SESSION['_errors'] ?? [])[$key]);
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e($_SESSION['_csrf_token'] ?? '') . '">';
    }
}

if (!function_exists('selected')) {
    function selected(mixed $a, mixed $b): string
    {
        return (string) $a === (string) $b && (string) $a !== '' ? ' selected' : '';
    }
}

if (!function_exists('checked')) {
    function checked(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOL) ? ' checked' : '';
    }
}

if (!function_exists('badge')) {
    /** Status-Badge mit Farbklasse (success, info, warning, danger, neutral). */
    function badge(?string $label, string $color = 'neutral'): string
    {
        $allowed = ['success', 'info', 'warning', 'danger', 'neutral'];
        $color = in_array($color, $allowed, true) ? $color : 'neutral';

        return '<span class="badge badge-' . $color . '">' . e($label ?? '–') . '</span>';
    }
}

if (!function_exists('query_url')) {
    /** Baut die aktuelle URL mit geänderten Query-Parametern (z. B. Paginierung/Sortierung). */
    function query_url(string $path, array $current, array $changes): string
    {
        $params = array_filter(array_merge($current, $changes), static fn ($v) => $v !== null && $v !== '');

        return $path . ($params === [] ? '' : '?' . http_build_query($params));
    }
}

if (!function_exists('icon')) {
    function icon(string $name, string $class = 'icon'): string
    {
        return '<svg class="' . e($class) . '" aria-hidden="true"><use href="#icon-' . e($name) . '"/></svg>';
    }
}

if (!function_exists('nl2br_e')) {
    function nl2br_e(?string $value): string
    {
        return nl2br(e($value));
    }
}

if (!function_exists('missing_labels')) {
    /** @param array<int,string>|string|null $missing */
    function missing_labels(array|string|null $missing): array
    {
        if (is_string($missing)) {
            $missing = json_decode($missing, true) ?: [];
        }
        $labels = [
            'location' => 'Standort fehlt',
            'employee' => 'Mitarbeiter fehlt',
            'cost_center' => 'Kostenstelle fehlt',
            'condition' => 'Zustand fehlt',
        ];

        return array_map(static fn (string $key): string => $labels[$key] ?? $key, $missing ?? []);
    }
}
