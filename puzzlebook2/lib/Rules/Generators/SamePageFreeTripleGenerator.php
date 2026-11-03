<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\Generators;

use Puzzlebook2\Rules\Scope;
use Puzzlebook2\Rules\ScopeGenerator;
use Puzzlebook2\Solver\BoardView;
use Puzzlebook2\Solver\Rng;

/**
 * Any three free cells on the SAME page ('whole', 'a', 'b') -- the geometry of
 * the compound-triple rule, the three-cell sibling of SamePageFreePairGenerator.
 * Lazy and stateless like every generator; the full triple set is cubic per
 * page and is never materialized -- the solver takes the first workable scope
 * and stops. The role names are only labels here; which word lands in which of
 * the three cells is the rule's wordChooser's business, and geometrically the
 * three are interchangeable (all that matters is three free cells on one page).
 */
final class SamePageFreeTripleGenerator implements ScopeGenerator
{
    public function candidates(BoardView $view, int $seed): iterable
    {
        $spec = $view->spec();
        $perPage = $spec->rows * $spec->cols;

        foreach (Rng::shuffle(range(0, $spec->pages - 1), $seed) as $page) {
            $start = $page * $perPage;
            $cells = Rng::shuffle(range($start, $start + $perPage - 1), $seed + 7919 * ($page + 1));
            $n = count($cells);
            for ($i = 0; $i < $n; $i++) {
                if (!$view->isFree($cells[$i])) {
                    continue;
                }
                for ($j = $i + 1; $j < $n; $j++) {
                    if (!$view->isFree($cells[$j])) {
                        continue;
                    }
                    for ($k = $j + 1; $k < $n; $k++) {
                        if ($view->isFree($cells[$k])) {
                            yield new Scope(['whole' => $cells[$i], 'a' => $cells[$j], 'b' => $cells[$k]]);
                        }
                    }
                }
            }
        }
    }
}
