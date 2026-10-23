<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

/**
 * The Boggle board printed on the game's box:
 *
 *     F  O  S  R  E
 *     E  G  T  E  D
 *     Qu I  N  A  M
 *     A  P  E  S  T
 *     C  O  R  T  E
 *
 * Precompute its traceable words with `php bin/compute-boggle.php`.
 */
final class BoggleBoxRule extends BoggleRule
{
    protected function board(): array
    {
        return [
            ['f', 'o', 's', 'r', 'e'],
            ['e', 'g', 't', 'e', 'd'],
            ['qu', 'i', 'n', 'a', 'm'],
            ['a', 'p', 'e', 's', 't'],
            ['c', 'o', 'r', 't', 'e'],
        ];
    }
}
