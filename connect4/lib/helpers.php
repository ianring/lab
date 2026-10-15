<?php
declare(strict_types=1);

/** Reading input and writing output. Nothing here knows the rules of the game. */

/** A GET parameter, as a string. */
function q(string $key, string $fallback = ''): string
{
    return isset($_GET[$key]) && is_string($_GET[$key]) ? $_GET[$key] : $fallback;
}

/** A POST parameter, falling back to the query string. */
function p(string $key, string $fallback = ''): string
{
    if (isset($_POST[$key]) && is_string($_POST[$key])) {
        return $_POST[$key];
    }
    return q($key, $fallback);
}

/** Escape for HTML. */
function h(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/** Send a JSON reply and stop. */
function json_out(array $data): void
{
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
