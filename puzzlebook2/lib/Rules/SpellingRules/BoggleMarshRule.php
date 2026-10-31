<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

/**
 * A 6x6 Boggle board (the "Marsh" board) -- sparse vowels among less common
 * letters, so it traces ~450 words.
 *
 *     K  B  W  A  F  G
 *     P  H  T  R  Y  E
 *     M  C  L  S  N  D
 *     J  O  E  X  I  W
 *     G  P  B  T  A  K
 *     F  V  Y  D  M  R
 *
 * No Qu tile, so the printed instruction omits the Qu note. Precompute its
 * traceable words with `php bin/compute-boggle.php`.
 */
final class BoggleMarshRule extends BoggleRule
{
    protected function board(): array
    {
        return [
            ['k', 'b', 'w', 'a', 'f', 'g'],
            ['p', 'h', 't', 'r', 'y', 'e'],
            ['m', 'c', 'l', 's', 'n', 'd'],
            ['j', 'o', 'e', 'x', 'i', 'w'],
            ['g', 'p', 'b', 't', 'a', 'k'],
            ['f', 'v', 'y', 'd', 'm', 'r'],
        ];
    }
}
