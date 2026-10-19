<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\Generators;

use Puzzlebook2\Board\Adjacency;
use Puzzlebook2\Rules\Scope;
use Puzzlebook2\Rules\ScopeGenerator;
use Puzzlebook2\Solver\BoardView;
use Puzzlebook2\Solver\Rng;

/**
 * Any two still-free cells related by $adjacency -- 'a' and 'b'. Used for
 * both vertical and horizontal pair rules; which one just depends on the
 * Adjacency passed in. Stateless like every generator: both the anchor
 * order and each anchor's neighbour order are recomputed from the seed per
 * call, so the sequence is a pure function of (board state, seed).
 */
final class AdjacentPairGenerator implements ScopeGenerator
{
    public function __construct(private Adjacency $adjacency)
    {
    }

    public function candidates(BoardView $view, int $seed): iterable
    {
        foreach (Rng::shuffle($view->spec()->allCells(), $seed) as $a) {
            if (!$view->isFree($a)) {
                continue;
            }
            $neighbours = Rng::shuffle($this->adjacency->neighboursOf($a, $view->spec()), $seed + $a);
            foreach ($neighbours as $b) {
                if ($view->isFree($b)) {
                    yield new Scope(['a' => $a, 'b' => $b]);
                }
            }
        }
    }
}
