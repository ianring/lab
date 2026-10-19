<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

/**
 * Cross out both words of any pair on the same page that begin with the same
 * $length letters -- CONSOLE and CONSTANT share "con".
 *
 * The cheapest of the coincidence rules to apply: you're scanning for a
 * repeated opening, which the eye picks up without having to process the
 * whole word. It's also the one most prone to accidents, since English piles
 * up on prefixes like CON, PRE, DIS and STA -- which is exactly why the
 * uniqueness invariant matters here.
 */
final class SamePrefixOnPageRule extends PageSignaturePairRule
{
    private const LENGTH = 3;

    protected function signatureOf(string $word): ?string
    {
        return strlen($word) < self::LENGTH ? null : substr($word, 0, self::LENGTH);
    }

    public function describe(): string
    {
        return 'pairs on the same page sharing their first ' . self::LENGTH . ' letters';
    }
}
