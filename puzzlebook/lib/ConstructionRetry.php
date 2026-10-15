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
 */
final class ConstructionRetry extends \RuntimeException
{
}
