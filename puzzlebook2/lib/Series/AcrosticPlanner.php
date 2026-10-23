<?php

declare(strict_types=1);

namespace Puzzlebook2\Series;

use Puzzlebook2\Board\BoardSpec;

/**
 * Turns a target phrase into a run of acrostic plant specs -- one cell per
 * letter, each fixing only its first letter (the rest of the word stays free;
 * see PlantRule / Ian's optimization). The cells trace a WRAPPING DIAGONAL:
 * one word per row, the column stepping +1 each row and wrapping at the edge,
 * which hides the message far better than a straight column.
 *
 * startCol is chosen by the caller to keep the diagonal off the grid's four
 * corners (the corner rule guards an E there), so acrostic words need no
 * special letter exclusion.
 */
final class AcrosticPlanner
{
    /**
     * @return array<int,array{cell:int,letter:string,id:string,role:string}>
     */
    public static function wrappingDiagonal(
        BoardSpec $spec,
        int $page,
        int $startCol,
        string $target,
        string $idPrefix,
    ): array {
        $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $target));
        $plants = [];
        $n = min(strlen($letters), $spec->rows);
        for ($r = 0; $r < $n; $r++) {
            $col = ($startCol + $r) % $spec->cols;
            $plants[] = [
                'cell' => $spec->linearIndex($page, $r, $col),
                'letter' => $letters[$r],
                'id' => "{$idPrefix}-{$r}",
                'role' => 'acrostic',
            ];
        }
        return $plants;
    }

    /**
     * Column (and start) that keep a 25-step wrapping diagonal clear of all
     * four corners on a 25x5 grid: startCol 2 puts row 0 at col 2 and row 24
     * at col 1 -- neither a corner.
     */
    public static function cornerFreeStartCol(): int
    {
        return 2;
    }
}
