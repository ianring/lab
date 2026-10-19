<?php

declare(strict_types=1);

namespace Puzzlebook2Tests\Fixtures;

use Puzzlebook2\Board\BoardSpec;
use Puzzlebook2\Chain\ChainContext;
use Puzzlebook2\Rules\Rule;
use Puzzlebook2\Solver\Board;
use Puzzlebook2\Solver\ReservationQueue;
use Puzzlebook2\Solver\ReservationSlot;

/** Wires a small BoardSpec + a set of Rules into a Board and a fully-loaded ReservationQueue, ready for Solver::solve(). */
final class TinyBoard
{
    /**
     * @param Rule[] $rules
     * @return array{0:Board,1:ReservationQueue,2:ChainContext}
     */
    public static function build(BoardSpec $spec, array $rules, int $seed): array
    {
        $board = new Board($spec);
        $ctx = new ChainContext($seed, $board);
        $queue = new ReservationQueue();
        foreach ($rules as $rule) {
            $demand = $rule->demand($ctx);
            for ($i = 0; $i < $demand->count; $i++) {
                $queue->push(new ReservationSlot($rule, $demand, $ctx, $i));
            }
        }
        return [$board, $queue, $ctx];
    }
}
