<?php

declare(strict_types=1);

namespace Puzzlebook2;

/**
 * The word fingerprint -- the single shared implementation used by BOTH the
 * public website hasher (web/index.php) and the book print pipeline.
 *
 *   fingerprint = reverse( encode( md5( normalise(word) . SALT ) ) )[0..19]
 *   printed as   XXXXX-XXXXX-XXXXX-XXXXX   (grouped)
 *
 * Both sides MUST produce byte-identical strings, or a player's check on
 * eliminoid.com will not match the fingerprint printed in the book. That is
 * the whole reason this lives in one place: the salt, the normalisation, the
 * alphabet, the reverse, and the length are defined here and nowhere else.
 * Never inline a copy.
 *
 * The digest is shown in Crockford Base32 (alphabet 0-9 then A-Z minus the
 * ambiguous I, L, O, U): 32 symbols instead of hex's 16, all-caps to match the
 * grid, and safe to read off a printed page without confusing 0/O or 1/I/L.
 * The full digest is ~26 symbols; it is reversed and cut to 20 (four groups of
 * five), so the excess is discarded -- still ample for a "did I get it right?"
 * check (100 bits).
 *
 * Note: the salt is PUBLIC, so this is a convenience "did I get it right?"
 * checker, not an anti-cheat gate -- anyone can compute the same fingerprint
 * for any word (see VENTURE.md D11).
 */
final class Fingerprint
{
    /** Crockford Base32. Change this one string to change the base everywhere. */
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private static ?string $cachedSalt = null;

    /**
     * The salt is SECRET and server-side: it lives in config/salt.txt, which
     * is gitignored (never in the public repo) and read by both the book build
     * and the website hasher so their fingerprints agree. Keeping it off the
     * printed page and out of git is what stops anyone precomputing
     * md5(word + salt) over a dictionary to read answers straight off the book
     * -- without the salt, a printed fingerprint reveals nothing, and checking
     * a word requires a (rate-limited) query to the site.
     */
    public static function salt(): string
    {
        if (self::$cachedSalt === null) {
            $path = __DIR__ . '/../config/salt.txt';
            $fromFile = is_file($path) ? trim((string) file_get_contents($path)) : '';
            self::$cachedSalt = $fromFile !== '' ? $fromFile : 'DEV-PLACEHOLDER-set-config-salt.txt';
        }
        return self::$cachedSalt;
    }

    /** Canonical normalisation: uppercase, letters A-Z only. */
    public static function normalise(string $raw): string
    {
        return preg_replace('/[^A-Z]/', '', strtoupper($raw)) ?? '';
    }

    /** Length of the printed code: four groups of five symbols. */
    public const CODE_LENGTH = 20;

    /**
     * The canonical fingerprint of a word (normalises it first): the Base32
     * digest REVERSED, then cut to CODE_LENGTH symbols (the excess is discarded).
     * grouped() then prints it as XXXXX-XXXXX-XXXXX-XXXXX.
     */
    public static function of(string $word): string
    {
        $full = self::encode(md5(self::normalise($word) . self::salt(), true));
        // Pad to a fixed width first, so the reverse is stable even in the rare
        // case encode() drops a leading symbol; then reverse and keep the front.
        $full = str_pad($full, 26, self::ALPHABET[0], STR_PAD_LEFT);
        return substr(strrev($full), 0, self::CODE_LENGTH);
    }

    /** Big-endian byte string -> a positional base over $alphabet (base58-style). */
    public static function encode(string $bytes, string $alphabet = self::ALPHABET): string
    {
        $base = strlen($alphabet);
        $input = array_values(unpack('C*', $bytes)); // byte values 0..255
        $out = '';
        while ($input !== []) {
            $remainder = 0;
            $quotient = [];
            foreach ($input as $byte) {
                $acc = $remainder * 256 + $byte;
                $q = intdiv($acc, $base);
                $remainder = $acc % $base;
                if ($quotient !== [] || $q > 0) {
                    $quotient[] = $q;
                }
            }
            $out = $alphabet[$remainder] . $out;
            $input = $quotient;
        }
        return $out === '' ? $alphabet[0] : $out;
    }

    /** Group a fingerprint into dash-separated blocks (XXXXX-XXXXX-...) for easier eyeball comparison. */
    public static function grouped(string $code, int $size = 5, string $sep = '-'): string
    {
        return trim(chunk_split($code, $size, $sep), $sep);
    }
}
