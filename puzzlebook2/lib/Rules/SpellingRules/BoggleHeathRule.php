<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

/**
 * A 6x6 Boggle board (the "Heath" board) -- common letters with a few
 * mid-frequency ones, so it traces ~740 words.
 *
 *     E  R  A  T  D  S
 *     S  I  N  H  L  O
 *     T  A  R  M  S  E
 *     O  E  L  C  N  D
 *     N  S  T  G  R  A
 *     P  A  O  K  E  T
 *
 * No Qu tile, so the printed instruction omits the Qu note. Precompute its
 * traceable words with `php bin/compute-boggle.php`.
 */
final class BoggleHeathRule extends BoggleRule
{
    protected function board(): array
    {
        return [
            ['e', 'r', 'a', 't', 'd', 's'],
            ['s', 'i', 'n', 'h', 'l', 'o'],
            ['t', 'a', 'r', 'm', 's', 'e'],
            ['o', 'e', 'l', 'c', 'n', 'd'],
            ['n', 's', 't', 'g', 'r', 'a'],
            ['p', 'a', 'o', 'k', 'e', 't'],
        ];
    }
}
