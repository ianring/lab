<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

use Puzzlebook\WordTags;

/**
 * Cross out both words of any pair on the same page whose vowels are the same
 * and in the same order -- PARADOX and CATALOG are both A-A-O.
 *
 * $minVowels is doing real work. With no minimum this rule is unusable: CAT
 * and BAD are both just "A", so nearly every page would be wiped out. At
 * three vowels the signature is specific enough to be a genuine find.
 *
 * Y never counts as a vowel, as everywhere else in the book.
 */
final class SameVowelsOnPageRule extends PageSignaturePairRule
{
    private const MIN_VOWELS = 3;

    protected function signatureOf(string $word): ?string
    {
        $vowels = '';
        for ($i = 0, $len = strlen($word); $i < $len; $i++) {
            if (WordTags::isVowel($word[$i])) {
                $vowels .= $word[$i];
            }
        }
        return strlen($vowels) < self::MIN_VOWELS ? null : $vowels;
    }

    public function describe(): string
    {
        return 'pairs on the same page with the same ' . self::MIN_VOWELS . '+ vowels in the same order';
    }
}
