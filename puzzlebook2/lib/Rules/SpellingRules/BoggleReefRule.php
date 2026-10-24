<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

/**
 * A Boggle board variant (precompute with bin/compute-boggle.php):
 *
 *     R A T E S
 *     O N I L D
 *     C U M P E
 *     G H A B T
 *     Y W O K R
 */
final class BoggleReefRule extends BoggleRule
{
    protected function board(): array
    {
        return [
            ['r', 'a', 't', 'e', 's'],
            ['o', 'n', 'i', 'l', 'd'],
            ['c', 'u', 'm', 'p', 'e'],
            ['g', 'h', 'a', 'b', 't'],
            ['y', 'w', 'o', 'k', 'r'],
        ];
    }
}
