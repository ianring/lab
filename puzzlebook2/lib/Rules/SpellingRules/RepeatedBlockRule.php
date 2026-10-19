<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

use Puzzlebook2\Rules\SpellingRuleBase;

/** Cross out every word made of the same group of 2+ letters repeated whole (DADA, TOMTOM, PERIPERI). */
final class RepeatedBlockRule extends SpellingRuleBase
{
    public function failsWord(string $word): bool
    {
        $length = strlen($word);
        for ($blockLength = 2; $blockLength <= intdiv($length, 2); $blockLength++) {
            if ($length % $blockLength !== 0) {
                continue;
            }
            $block = substr($word, 0, $blockLength);
            if (str_repeat($block, intdiv($length, $blockLength)) === $word) {
                return true;
            }
        }
        return false;
    }

    public function describe(): string
    {
        return 'words made of the same group of letters repeated (DADA, TOMTOM, PERIPERI)';
    }
}
