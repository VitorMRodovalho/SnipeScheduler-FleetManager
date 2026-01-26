<?php
// datetime_helpers.php
// Shared helpers for configurable date/time formatting.

if (!function_exists('app_date_format_options')) {
    function app_date_format_options(): array
    {
        return [
            'd/m/Y' => '31/12/2026 (DD/MM/YYYY)',
            'm/d/Y' => '12/31/2026 (MM/DD/YYYY)',
            'Y-m-d' => '2026-12-31 (YYYY-MM-DD, ISO)',
            'Y.m.d' => '2026.12.31 (YYYY.MM.DD)',
            'd.m.Y' => '31.12.2026 (DD.MM.YYYY)',
            'd-m-Y' => '31-12-2026 (DD-MM-YYYY)',
            'Y/m/d' => '2026/12/31 (YYYY/MM/DD)',
            'j M Y' => '31 Dec 2026 (D Mon YYYY)',
            'M j, Y' => 'Dec 31, 2026 (Mon D, YYYY)',
        ];
    }
}

if (!function_exists('app_time_format_options')) {
    function app_time_format_options(): array
    {
        return [
            'H:i' => '23:59 (24-hour)',
            'H:i:s' => '23:59:59 (24-hour with seconds)',
            'h:i A' => '11:59 PM (12-hour)',
            'h:i:s A' => '11:59:59 PM (12-hour with seconds)',
        ];
    }
}

if (!function_exists('app_get_timezone')) {
    function app_get_timezone(?array $cfg = null): ?DateTimeZone
    {
        $cfg = $cfg ?? load_config();
        $timezone = $cfg['app']['timezone'] ?? 'Europe/Jersey';
        try {
            return new DateTimeZone($timezone);
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('app_get_date_format')) {
    function app_get_date_format(?array $cfg = null): string
    {
        $cfg = $cfg ?? load_config();
        $options = app_date_format_options();
        $raw = $cfg['app']['date_format'] ?? 'd/m/Y';
        return array_key_exists($raw, $options) ? $raw : 'd/m/Y';
    }
}

if (!function_exists('app_get_time_format')) {
    function app_get_time_format(?array $cfg = null): string
    {
        $cfg = $cfg ?? load_config();
        $options = app_time_format_options();
        $raw = $cfg['app']['time_format'] ?? 'H:i';
        return array_key_exists($raw, $options) ? $raw : 'H:i';
    }
}

if (!function_exists('app_parse_datetime_value')) {
    function app_parse_datetime_value($value, ?DateTimeZone $tz = null): ?DateTime
    {
        if ($value instanceof DateTimeInterface) {
            $dt = new DateTime($value->format('c'));
            if ($tz) {
                $dt->setTimezone($tz);
            }
            return $dt;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $dt = new DateTime('@' . $value);
            if ($tz) {
                $dt->setTimezone($tz);
            }
            return $dt;
        }

        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }

        try {
            $dt = new DateTime($text);
            if ($tz) {
                $dt->setTimezone($tz);
            }
            return $dt;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('app_format_date')) {
    function app_format_date($value, ?array $cfg = null, ?DateTimeZone $tz = null): string
    {
        $cfg = $cfg ?? load_config();
        $tz = $tz ?? app_get_timezone($cfg);
        $dt = app_parse_datetime_value($value, $tz);
        if (!$dt) {
            return is_scalar($value) ? (string)$value : '';
        }
        return $dt->format(app_get_date_format($cfg));
    }
}

if (!function_exists('app_format_datetime')) {
    function app_format_datetime($value, ?array $cfg = null, ?DateTimeZone $tz = null): string
    {
        $cfg = $cfg ?? load_config();
        $tz = $tz ?? app_get_timezone($cfg);
        $dt = app_parse_datetime_value($value, $tz);
        if (!$dt) {
            return is_scalar($value) ? (string)$value : '';
        }
        $format = app_get_date_format($cfg) . ' ' . app_get_time_format($cfg);
        return $dt->format($format);
    }
}
