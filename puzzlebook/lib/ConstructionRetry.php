<?php

declare(strict_types=1);

namespace Puzzlebook;

/**
 * A recoverable construction failure: this particular arrangement of cells
 * didn't satisfy some constraint, but a different arrangement might.
 *
 * ChainBuilder catches these and tries again with a different seed, rather
 * than giving up -- which is the difference between "your rule set is
 * impossible" and "that one layout didn't happen to work out". Only when
 * every attempt fails does it report, and then it reports which constraint
 * failed most often, so the message points at the real bottleneck.
 *
 * Genuine dead ends (the word list can't supply a shape at all, a reserved
 * word contradicts a rule) stay as plain RuntimeExceptions -- retrying
 * those would just spin.
 *
 * $stepId, when known, names the ONE construction step responsible -- the
 * rule whose own construct() threw, or (via Canvas::killedByFor()) whichever
 * step claimed the cell a fill-time collision happened at. ChainBuilder's
 * local-retry loop uses it to undo and redo just that step instead of the
 * whole attempt. Null means no single step can honestly be blamed (a
 * cascading budget mismatch, a wrong final survivor count) -- the safe
 * fallback is to restart construction from the very beginning, same as
 * every attempt did before local retry existed.
 */
final class ConstructionRetry extends \RuntimeException
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, private ?int $stepId = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function stepId(): ?int
    {
        return $this->stepId;
    }
}
