<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules\Generators;

use Puzzlebook2\Rules\Scope;
use Puzzlebook2\Rules\ScopeGenerator;
use Puzzlebook2\Solver\BoardView;
use Puzzlebook2\Solver\Rng;

/**
 * Geometry known in advance, independent of fill order -- e.g. the four
 * corners of each page. $groups is a fixed list of role=>cell maps,
 * computed once by whoever builds this generator (usually from BoardSpec
 * alone); only "is every cell in this group still free" varies at runtime.
 */
final class FixedGroupGenerator implements ScopeGenerator
{
    /** @param array<int,array<string,int>> $groups */
    public function __construct(private array $groups)
    {
    }

    public function candidates(BoardView $view, int $seed): iterable
    {
        $order = Rng::shuffle(array_keys($this->groups), $seed);
        foreach ($order as $key) {
            $cellsByRole = $this->groups[$key];
            $allFree = true;
            foreach ($cellsByRole as $cell) {
                if (!$view->isFree($cell)) {
                    $allFree = false;
                    break;
                }
            }
            if ($allFree) {
                yield new Scope($cellsByRole);
            }
        }
    }
}
