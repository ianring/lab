<?php

declare(strict_types=1);

namespace Puzzlebook\Rules;

/**
 * Cross out both words of any pair on the same page where one is the other
 * written backwards -- STRESSED and DESSERTS, or DRAWER and REWARD.
 *
 * Cheap for the reader despite being a cross-reference rule, because it
 * short-circuits: you reverse a word in your head and almost always get
 * nonsense, which you discard instantly. Only the rare word whose reversal
 * is a plausible word sends you hunting the page for its partner.
 *
 * The signature is whichever of the word and its reversal sorts first, so
 * both halves of a pair produce the same value. A palindrome reverses to
 * itself and so could only ever pair with a duplicate of itself, which can't
 * happen -- every word in the book is distinct.
 */
final class ReversalOnPageRule extends PageSignaturePairRule
{
    private const MIN_LENGTH = 4;

    protected function signatureOf(string $word): ?string
    {
        if (strlen($word) < self::MIN_LENGTH) {
            return null;
        }
        $reversed = strrev($word);
        if ($reversed === $word) {
            return null;
        }
        return $word < $reversed ? $word . '|' . $reversed : $reversed . '|' . $word;
    }

    public function describe(): string
    {
        return 'pairs on the same page where one word is the other backwards (' . self::MIN_LENGTH . '+ letters)';
    }
}
