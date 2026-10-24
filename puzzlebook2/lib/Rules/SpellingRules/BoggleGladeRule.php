<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\SpellingRules;

/**
 * A Boggle board variant (precompute with bin/compute-boggle.php):
 *
 *     A R E T S
 *     L I N O D
 *     C P U M B
 *     E H G A T
 *     S W R K Y
 */
final class BoggleGladeRule extends BoggleRule
{
    protected function board(): array
    {
        return [
            ['a', 'r', 'e', 't', 's'],
            ['l', 'i', 'n', 'o', 'd'],
            ['c', 'p', 'u', 'm', 'b'],
            ['e', 'h', 'g', 'a', 't'],
            ['s', 'w', 'r', 'k', 'y'],
        ];
    }
}
