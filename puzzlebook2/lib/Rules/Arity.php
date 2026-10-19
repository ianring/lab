<?php

declare(strict_types=1);

namespace Puzzlebook2\Rules;

enum Arity
{
    /** Specific real words chosen together, up front, by wordChooser -- no fallback once committed. */
    case Exact;
    /** Any word satisfying the owning rule's shape works -- full dictionary fallback. */
    case Shaped;
}
