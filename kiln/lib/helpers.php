<?php
declare(strict_types=1);

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function q(string $key, $default = null)
{
    return $_GET[$key] ?? $default;
}

function p(string $key, $default = null)
{
    return $_POST[$key] ?? $default;
}

function now(): DateTimeImmutable
{
    return new DateTimeImmutable('now');
}

/** Parse an <input type="datetime-local"> value, or anything strtotime groks. */
function parse_dt($value): ?DateTimeImmutable
{
    if (!is_scalar($value)) {
        return null;
    }
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    try {
        return new DateTimeImmutable($value);
    } catch (Exception $ex) {
        return null;
    }
}

function dt_input(?DateTimeImmutable $d): string
{
    return $d ? $d->format('Y-m-d\TH:i') : '';
}

function dt_store(?DateTimeImmutable $d): string
{
    return $d ? $d->format('c') : '';
}

/** "Fri 8:30 pm", with the date only when it is not today. */
function fmt_clock(?DateTimeImmutable $d, ?DateTimeImmutable $reference = null): string
{
    if (!$d) {
        return '—';
    }
    $reference = $reference ?? now();
    $time = ltrim($d->format('g:i a'), '0');
    if ($d->format('Y-m-d') === $reference->format('Y-m-d')) {
        return $time;
    }
    if ($d->format('Y-m-d') === $reference->modify('+1 day')->format('Y-m-d')) {
        return 'tomorrow ' . $time;
    }
    return $d->format('D j M') . ' ' . $time;
}

function fmt_day(?DateTimeImmutable $d): string
{
    return $d ? $d->format('D j M') : '—';
}

/** 3.5 -> "3 hr 30 min" */
function fmt_hours(float $hours): string
{
    return fmt_minutes((int)round($hours * 60));
}

function fmt_minutes(int $minutes): string
{
    $sign = $minutes < 0 ? '-' : '';
    $minutes = abs($minutes);
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    if ($h === 0) {
        return $sign . $m . ' min';
    }
    if ($m === 0) {
        return $sign . $h . ' hr';
    }
    return $sign . $h . ' hr ' . $m . ' min';
}

/**
 * Hours for the middle of a sentence: "2 hours", "1 hour", "1.5 hours",
 * "30 minutes". Reads naturally in prose, unlike fmt_hours()'s "1 hr 30 min".
 */
function fmt_hours_words(float $hours): string
{
    if ($hours > 0 && $hours < 1) {
        $minutes = (int)round($hours * 60);
        return $minutes . ($minutes === 1 ? ' minute' : ' minutes');
    }
    $number = rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
    return $number . ($hours == 1.0 ? ' hour' : ' hours');
}

/**
 * The other way round: read a controller's hours.minutes back into minutes.
 *
 * The display shows 12.30 for twelve and a half hours — a decimal point, but
 * not a decimal. Accepts 12.30, 12:30, "12h30" and a bare 12.
 */
function controller_time_parse(?string $value): ?int
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^(\d{1,3})\s*[.:h]\s*(\d{1,2})$/i', $value, $parts)) {
        return (int)$parts[1] * 60 + min(59, (int)$parts[2]);
    }
    if (preg_match('/^\d{1,3}$/', $value)) {
        return (int)$value * 60;
    }
    return null;
}

/** Minutes as the controller wants them typed: hours.minutes, e.g. "3.20". */
function controller_time(int $minutes): string
{
    $minutes = max(0, $minutes);
    return sprintf('%d.%02d', intdiv($minutes, 60), $minutes % 60);
}

function minutes_between(DateTimeImmutable $a, DateTimeImmutable $b): int
{
    return (int)round(($b->getTimestamp() - $a->getTimestamp()) / 60);
}

function add_minutes(DateTimeImmutable $d, int $minutes): DateTimeImmutable
{
    return $d->modify(($minutes >= 0 ? '+' : '-') . abs($minutes) . ' minutes');
}

/**
 * Cache-buster tied to the file's timestamp, so an edited stylesheet or script
 * is never served stale. A hand-maintained "?v=1" is exactly how you end up
 * debugging code the browser is not running.
 */
function asset_url(string $path): string
{
    $full = __DIR__ . '/../' . ltrim($path, '/');
    $stamp = is_file($full) ? (int)filemtime($full) : 0;
    return $path . ($stamp ? '?v=' . $stamp : '');
}

/**
 * The person and kiln filters as a query string, so a link can carry them —
 * between the list and the calendar, or from one month to the next. Empty when
 * nothing is filtered, which keeps plain URLs plain.
 */
function filter_query(int $person, string $kiln, array $extra = []): string
{
    $params = $extra;
    if ($person > 0) {
        $params['person'] = $person;
    }
    if ($kiln !== '') {
        $params['kiln'] = $kiln;
    }
    return $params ? '?' . http_build_query($params) : '';
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function json_out($data): void
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
