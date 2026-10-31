<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

/**
 * A 6x6 Boggle board (the "Fen" board) -- very few vowels and many rare letters,
 * so it traces ~200 words.
 *
 *     G  B  W  K  F  D
 *     P  H  A  T  R  V
 *     M  C  L  S  N  E
 *     J  W  O  X  Z  Q
 *     G  P  B  T  I  K
 *     F  V  Y  D  M  R
 *
 * No Qu tile, so the printed instruction omits the Qu note. Precompute its
 * traceable words with `php bin/compute-boggle.php`.
 */
final class BoggleFenRule extends BoggleRule
{
    protected function board(): array
    {
        return [
            ['g', 'b', 'w', 'k', 'f', 'd'],
            ['p', 'h', 'a', 't', 'r', 'v'],
            ['m', 'c', 'l', 's', 'n', 'e'],
            ['j', 'w', 'o', 'x', 'z', 'q'],
            ['g', 'p', 'b', 't', 'i', 'k'],
            ['f', 'v', 'y', 'd', 'm', 'r'],
        ];
    }
}
