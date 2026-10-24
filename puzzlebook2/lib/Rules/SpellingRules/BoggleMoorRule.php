<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

/**
 * A Boggle board variant (precompute with bin/compute-boggle.php):
 *
 *     E T O A R
 *     D N I S L
 *     B M U P C
 *     T G A H E
 *     K R Y W F
 */
final class BoggleMoorRule extends BoggleRule
{
    protected function board(): array
    {
        return [
            ['e', 't', 'o', 'a', 'r'],
            ['d', 'n', 'i', 's', 'l'],
            ['b', 'm', 'u', 'p', 'c'],
            ['t', 'g', 'a', 'h', 'e'],
            ['k', 'r', 'y', 'w', 'f'],
        ];
    }
}
