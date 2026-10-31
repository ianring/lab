<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

/**
 * A 6x6 Boggle board (the "Ridge" board) -- common, well-connected letters, so
 * it traces ~1,000 words.
 *
 *     E  R  A  T  E  S
 *     S  I  N  E  L  O
 *     T  A  R  I  S  E
 *     O  E  L  A  N  I
 *     N  S  T  E  R  A
 *     A  I  O  L  E  T
 *
 * No Qu tile, so the printed instruction omits the Qu note. Precompute its
 * traceable words with `php bin/compute-boggle.php`.
 */
final class BoggleRidgeRule extends BoggleRule
{
    protected function board(): array
    {
        return [
            ['e', 'r', 'a', 't', 'e', 's'],
            ['s', 'i', 'n', 'e', 'l', 'o'],
            ['t', 'a', 'r', 'i', 's', 'e'],
            ['o', 'e', 'l', 'a', 'n', 'i'],
            ['n', 's', 't', 'e', 'r', 'a'],
            ['a', 'i', 'o', 'l', 'e', 't'],
        ];
    }
}
