<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

/**
 * A Boggle board variant (precompute with bin/compute-boggle.php):
 *
 *     S E A R T
 *     O N I L E
 *     C A T D M
 *     R O P I N
 *     B U G H L
 */
final class BoggleValeRule extends BoggleRule
{
    protected function board(): array
    {
        return [
            ['s', 'e', 'a', 'r', 't'],
            ['o', 'n', 'i', 'l', 'e'],
            ['c', 'a', 't', 'd', 'm'],
            ['r', 'o', 'p', 'i', 'n'],
            ['b', 'u', 'g', 'h', 'l'],
        ];
    }
}
