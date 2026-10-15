<?php
declare(strict_types=1);

/**
 * Where a puzzle's answer key lives publicly.
 *
 * The id is content-addressed (see Puzzle::id()), so this URL names one exact
 * puzzle for good - printing it on a worksheet is safe even if the generator
 * changes later.
 *
 * Override the host per deployment with SUDOKU_BASE_URL.
 */
final class PuzzleLink
{
    public const BASE = 'https://ianring.com/musictheory/sudoku/';

    public static function base(): string
    {
        $base = getenv('SUDOKU_BASE_URL') ?: self::BASE;
        return rtrim($base, '/') . '/';
    }

    public static function url(Puzzle $p): string
    {
        return self::base() . $p->id();
    }

    /** The same URL without its scheme, for printing under a QR code. */
    public static function display(Puzzle $p): string
    {
        return preg_replace('#^https?://#', '', self::url($p)) ?? self::url($p);
    }
}
