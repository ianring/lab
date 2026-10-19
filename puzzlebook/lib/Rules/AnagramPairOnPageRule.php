<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

/**
 * Cross out both words of any pair on the same page that are anagrams of
 * each other -- LISTEN and SILENT, or DUSTY and STUDY.
 *
 * The signature is simply the word's letters in alphabetical order, which is
 * the same for two words exactly when they're anagrams.
 *
 * Expensive for the reader: there's no way to look at a word and rule it out,
 * so you end up sorting letters in your head and comparing. Worth placing
 * late, where few words remain to check.
 */
final class AnagramPairOnPageRule extends PageSignaturePairRule
{
    private const MIN_LENGTH = 4;

    protected function signatureOf(string $word): ?string
    {
        if (strlen($word) < self::MIN_LENGTH) {
            return null;
        }
        $letters = str_split($word);
        sort($letters);
        return implode('', $letters);
    }

    public function describe(): string
    {
        return 'pairs of anagrams on the same page (' . self::MIN_LENGTH . '+ letters)';
    }
}
