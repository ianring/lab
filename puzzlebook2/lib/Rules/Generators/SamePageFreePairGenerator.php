<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\Generators;

use Puzzlebook2\Rules\Scope;
use Puzzlebook2\Rules\ScopeGenerator;
use Puzzlebook2\Solver\BoardView;
use Puzzlebook2\Solver\Rng;

/**
 * Any two free cells on the SAME page ('a' and 'b') -- the geometry of
 * page-scoped pair rules (anagram pairs). Lazy and stateless like every
 * generator; the full pair set is quadratic per page and is never
 * materialized.
 */
final class SamePageFreePairGenerator implements ScopeGenerator
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
                    if ($view->isFree($cells[$j])) {
                        yield new Scope(['a' => $cells[$i], 'b' => $cells[$j]]);
                    }
                }
            }
        }
    }
}
